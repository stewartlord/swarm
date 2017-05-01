<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Comments\Controller;

use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Attachments\Model\Attachment;
use Comments\Model\Comment;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\InputFilter\InputFilter;
use Zend\Json\Json;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * Index action to return rendered comments for a given topic.
     *
     * @return  ViewModel
     */
    public function indexAction()
    {
        $topic = trim($this->getEvent()->getRouteMatch()->getParam('topic'), '/');

        // send 404 if no topic is provided
        if (!strlen($topic)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if the topic relates to a change, ensure it's accessible
        $this->restrictChangeAccess($topic);

        // handle requests for JSON
        if ($this->getRequest()->getQuery('format') === 'json') {
            $services   = $this->getServiceLocator();
            $p4Admin    = $services->get('p4_admin');
            $ipProtects = $services->get('ip_protects');
            $comments   = Comment::fetchAll(array(Comment::FETCH_BY_TOPIC => $topic), $p4Admin, $ipProtects);

            return new JsonModel(
                array(
                    'topic'     => $topic,
                    'comments'  => $comments->toArray()
                )
            );
        }

        $view  = new ViewModel(
            array(
                'topic' => $topic
            )
        );

        $view->setTerminal(true);
        return $view;
    }

    /**
     * Action to add a new comment.
     *
     * @return  JsonModel
     */
    public function addAction()
    {
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');

        // if the topic relates to a change, ensure it's accessible
        $this->restrictChangeAccess($this->request->getPost('topic'));

        $p4Admin  = $services->get('p4_admin');
        $user     = $services->get('user');
        $comments = $services->get('viewhelpermanager')->get('comments');
        $filter   = $this->getCommentFilter($user, 'add', array(Comment::TASK_COMMENT, Comment::TASK_OPEN));
        $data     = $this->request->getPost();
        $delay    = $data->get('delayNotification', false);

        $filter->setData($this->request->getPost());
        $isValid = $filter->isValid();
        if ($isValid) {
            $comment = new Comment($p4Admin);
            $comment->set($filter->getValues())
                    ->save();

            // delay comment email notification if we are instructed to do so;
            // otherwise collect previously delayed notifications to send
            $sendComments = $this->handleDelayedComments($comment, $delay);

            // push comment into queue for further processing.
            // note that we don't send individual notifications for delayed comments
            $queue = $services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                array(
                    'current'      => $comment->get(),
                    'quiet'        => $delay ? array('mail') : null,
                    'sendComments' => $sendComments
                )
            );
        }

        return new JsonModel(
            array(
                'isValid'   => $isValid,
                'messages'  => $filter->getMessages(),
                'comments'  => $isValid ? $comments($filter->getValue('topic')) : null,
            )
        );
    }

    /**
     * Action to edit a comment
     *
     * @return JsonModel
     */
    public function editAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => 'Invalid request method. HTTP POST required.'
                )
            );
        }

        // start by ensuring the user is at least logged in
        $services = $this->getServiceLocator();
        $user     = $services->get('user');
        $services->get('permissions')->enforce('authenticated');

        // if the topic relates to a change, ensure it's accessible
        $this->restrictChangeAccess($this->request->getPost('topic'));

        // attempt to retrieve the specified comment
        // translate invalid/missing id's into a 404
        try {
            $id       = $this->getEvent()->getRouteMatch()->getParam('comment');
            $p4Admin  = $services->get('p4_admin');
            $comment  = Comment::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        if (!isset($comment)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // users can only edit the content of comments they own
        $isContentEdit = $request->getPost('body') !== null || $request->getPost('attachments') !== null;
        if ($isContentEdit && $comment->get('user') !== $user->getId()) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        // users cannot add/remove likes on archived comments
        $isLike = $request->getPost('addLike') || $request->getPost('removeLike');
        if ($isLike && in_array('closed', $comment->getFlags())) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        $filter   = $this->getCommentFilter($user, 'edit', array_keys($comment->getTaskTransitions()));
        $comments = $services->get('viewhelpermanager')->get('comments');
        $fields   = array_keys($filter->getRawValues());
        $posted   = $request->getPost()->toArray();
        $filter->setValidationGroup(array_intersect($fields, array_keys($posted)));

        // if the user has selected verify and archive, add the appropriate flag
        if (isset($posted['taskState']) && $posted['taskState'] == Comment::TASK_VERIFIED_ARCHIVE) {
            $posted['addFlags'] = 'closed';
        }

        $filter->setData($posted);
        $isValid = $filter->isValid();
        if ($isValid) {
            $old      = $comment->get();
            $filtered = $filter->getValues();

            // add/remove likes and flags are not stored fields
            unset(
                $filtered['addLike'],
                $filtered['removeLike'],
                $filtered['addFlags'],
                $filtered['removeFlags']
            );

            $comment->set($filtered);

            // add/remove likes and any flags that the user passed
            $comment
                ->addLike($filter->getValue('addLike'))
                ->removeLike($filter->getValue('removeLike'))
                ->addFlags($filter->getValue('addFlags'))
                ->removeFlags($filter->getValue('removeFlags'))
                ->set('edited', $isContentEdit ? time() : $comment->get('edited'))
                ->save();

            // for content edits, handle delayed notifications
            // this means we delay email notifications when instructed to do so
            // and collect delayed comments for sending when ending a batch
            $delay        = $request->getPost()->get('delayNotification', false);
            $sendComments = $isContentEdit
                ? $this->handleDelayedComments($comment, $delay)
                : null;

            // push comment update into queue for further processing
            $queue = $services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                array(
                    'user'         => $user->getId(),
                    'previous'     => $old,
                    'current'      => $comment->get(),
                    'quiet'        => $delay ? array('mail') : null,
                    'sendComments' => $sendComments
                )
            );
        } else {
            $this->getResponse()->setStatusCode(400);
        }

        return new JsonModel(
            array(
                'isValid'         => $isValid,
                'messages'        => $filter->getMessages(),
                'taskTransitions' => $comment->getTaskTransitions(),
                'comment'         => $comment->get(),
                'comments'        => $request->getPost('renderComments') ? $comments($comment->get('topic')) : null
            )
        );
    }

    /**
     * Return the filter for data to add comments.
     *
     * @param   User            $user           the current authenticated user.
     * @param   string          $mode           one of 'add' or 'edit'
     * @param   array           $transitions    transitions being validated against
     * @return  InputFilter     filter for adding comments data
     */
    protected function getCommentFilter(User $user, $mode, array $transitions = array())
    {
        $services       = $this->getServiceLocator();
        $ipProtects     = $services->get('ip_protects');
        $filter         = new InputFilter;
        $flagValidators = array(
            array(
                'name'      => '\Application\Validator\IsArray'
            ),
            array(
                'name'      => '\Application\Validator\Callback',
                'options'   => array(
                    'callback'  => function ($value) {
                        if (in_array(false, array_map('is_string', $value))) {
                            return 'flags must be set as strings';
                        }

                        return true;
                    }
                )
            )
        );
        $userValidator = array(
            'name'      => '\Application\Validator\Callback',
            'options'   => array(
                'callback'  => function ($value) use ($user) {
                    if ($value !== $user->getId()) {
                        return 'Not logged in as %s';
                    }

                    return true;
                }
            )
        );

        // ensure user is provided and refers to the active user
        $filter->add(
            array(
                'name'          => 'user',
                'required'      => true,
                'validators'    => array($userValidator)
            )
        );

        $filter->add(
            array(
                'name'      => 'topic',
                'required'  => true
            )
        );

        $filter->add(
            array(
                'name'      => 'context',
                'required'  => false,
                'filters'   => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                return $value !== null
                                    ? Json::decode($value, Json::TYPE_ARRAY)
                                    : null;
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($ipProtects) {
                                // deny if user doesn't have list access to the context file
                                $file = isset($value['file']) ? $value['file'] : null;
                                if ($file && !$ipProtects->filterPaths($file, Protections::MODE_LIST)) {
                                    return "No permission to list the associated file.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        $filter->add(
            array(
                'name'          => 'attachments',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'          => '\Application\Validator\Callback',
                        'options'       => array(
                            'callback'  => function ($value) use ($services) {
                                // allow empty value
                                if (empty($value)) {
                                    return true;
                                }

                                // error on invalid input (e.g., a string)
                                if (!is_array($value)) {
                                    return false;
                                }

                                // ensure all IDs are true integers and correspond to existing attachments
                                foreach ($value as $id) {
                                    if (!ctype_digit((string) $id)) {
                                        return false;
                                    }
                                }

                                if (count(Attachment::exists($value, $services->get('p4_admin'))) != count($value)) {
                                    return "Supplied attachment(s) could not be located on the server";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        $filter->add(
            array(
                'name'      => 'body',
                'required'  => true
            )
        );

        $filter->add(
            array(
                'name'          => 'flags',
                'required'      => false,
                'validators'    => $flagValidators
            )
        );

        $filter->add(
            array(
                'name'       => 'taskState',
                'required'   => false,
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($mode, $transitions) {
                                if (!in_array($value, $transitions, true)) {
                                    return 'Invalid task state transition specified. '
                                         . 'Valid transitions are: ' . implode(', ', $transitions);
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // in edit mode don't allow user, topic, or context
        // but include virtual add/remove flags and add/remove like fields
        if ($mode == 'edit') {
            $filter->remove('user');
            $filter->remove('topic');
            $filter->remove('context');

            $filter->add(
                array(
                    'name'          => 'addFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                )
            );

            $filter->add(
                array(
                    'name'          => 'removeFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                )
            );

            $filter->add(
                array(
                    'name'          => 'addLike',
                    'required'      => false,
                    'validators'    => array($userValidator)
                )
            );

            $filter->add(
                array(
                    'name'          => 'removeLike',
                    'required'      => false,
                    'validators'    => array($userValidator)
                )
            );
        }

        return $filter;
    }

    /**
     * Helper to ensure that the given topic does not refer to a forbidden change.
     *
     * @param   string  $topic      the topic to check change access for
     * @throws  ForbiddenException  if the topic refers to a change the user can't access
     */
    protected function restrictChangeAccess($topic)
    {
        // early exit if the topic is not change related
        if (!preg_match('#(changes|reviews)/([0-9]+)#', $topic, $matches)) {
            return;
        }

        $group = $matches[1];
        $id    = $matches[2];

        // if the topic refers to a review, we need to fetch it to determine the change
        // if the topic refers to a change, it always uses the original change id, but for
        // the access check we need to make sure we use the latest/renumbered id.
        $services = $this->getServiceLocator();
        if ($group === 'reviews') {
            $review = Review::fetch($id, $services->get('p4_admin'));
            $change = $review->getHeadChange();
        } else {
            // resolve original number to latest/submitted change number
            // for 12.1+ we can rely on 'p4 change -O', for older servers, try context param
            $p4     = $services->get('p4');
            $lookup = $id;
            if (!$p4->isServerMinVersion('2012.1')) {
                $context = $this->getRequest()->getQuery('context', array());
                $lookup  = isset($context['change']) ? $context['change'] : $id;
            }

            try {
                $change = Change::fetch($lookup, $services->get('p4'));
                $change = $id == $change->getOriginalId() ? $change->getId() : false;
            } catch (SpecNotFoundException $e) {
                $change = false;
            }
        }

        if ($change === false || !$services->get('changes_filter')->canAccess($change)) {
            throw new ForbiddenException("You don't have permission to access this topic.");
        }
    }

    /**
     * Delay notification for the given comment or collect delayed
     * comments and close the batch if we are sending (delay is false).
     *
     * @param   Comment     $comment    comment to process
     * @param   bool        $delay      delay this comment, false to close the batch
     * @return  array|null  delayed comment data if sending, null otherwise
     */
    protected function handleDelayedComments(Comment $comment, $delay)
    {
        $topic           = $comment->get('topic');
        $userConfig      = $this->getServiceLocator()->get('user')->getConfig();
        $delayedComments = $userConfig->getDelayedComments($topic);

        // nothing to do if we are sending but there are no delayed comments
        if (!$delay && !count($delayedComments)) {
            return null;
        }

        // if not already present, add the comment to delayed comments; in the case of an add,
        // the comment batch time should match the time of the first comment - this should avoid
        // later concluding that the comment was created before the batch
        if (!array_key_exists($comment->getId(), $delayedComments)) {
            $delayedComments[$comment->getId()] = $comment->get('edited')
                ? time()
                : $comment->get('time');
        }

        $userConfig->setDelayedComments($topic, $delay ? $delayedComments : null)->save();
        return $delay ? null : $delayedComments;
    }
}
