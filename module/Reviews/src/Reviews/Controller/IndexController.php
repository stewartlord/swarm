<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\Controller;

use Application\Filter\FormBoolean;
use Application\Filter\Preformat;
use Application\Module as ApplicationModule;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Comments\Model\Comment;
use Groups\Model\Group;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\ConflictException;
use P4\File\File;
use P4\File\Exception\Exception as FileException;
use P4\Key\Key;
use P4\Spec\Change;
use P4\Spec\Definition as Spec;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Projects\Model\Project;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Lock\Lock;
use Reviews\Filter\GitInfo;
use Reviews\Model\FileInfo;
use Reviews\Model\Review;
use Users\Model\User;
use Users\Validator\Users as UsersValidator;
use Zend\Http\Client as HttpClient;
use Zend\Http\Request as HttpRequest;
use Zend\InputFilter\InputFilter;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\Parameters;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * Index action to return rendered reviews.
     *
     * @return  ViewModel
     */
    public function indexAction()
    {
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $request  = $this->getRequest();
        $query    = $request->getQuery();

        // for non-json requests, render the template and exit
        if ($query->get('format') !== 'json') {
            return;
        }

        // get review models
        $models = Review::fetchAll($this->getFetchAllOptions($query), $p4Admin);

        // remove reviews that are restricted for the current user
        // we filter based on access to the most recent change
        $models = $services->get('changes_filter')->filter($models, 'getHeadChange');

        // collect comment counts
        $topics = $models->invoke('getTopic');
        $counts = Comment::countByTopic(array_unique($topics), $p4Admin);

        // prepare review data for output
        $reviews       = array();
        $preformat     = new Preformat($request->getBaseUrl());
        $avatar        = $services->get('viewhelpermanager')->get('avatar');
        $projectList   = $services->get('viewhelpermanager')->get('projectList');
        $activeProject = $this->getEvent()->getRouteMatch()->getParam('activeProject');
        $disableHtml   = (bool) $query->get('disableHtml', false);
        foreach ($models as $model) {
            // - render author avatar
            // - add preformated description
            // - add formatted date of creation
            // - add rendered list of projects
            // - add comments count
            // - add up/down votes
            $author       = $model->get('author');
            $description  = $model->get('description');
            $projects     = $model->get('projects');
            $topic        = $model->getTopic();
            $reviews[]    = array_merge(
                $model->get(),
                array(
                    'authorAvatar' => !$disableHtml ? $avatar($author, 32, $model->isValidAuthor()) : null,
                    'description'  => !$disableHtml ? $preformat->filter($description) : $description,
                    'createDate'   => date('c', $model->get('created')),
                    'projects'     => !$disableHtml ? $projectList($projects, $activeProject) : $projects,
                    'comments'     => isset($counts[$topic]) ? $counts[$topic] : array(0, 0),
                    'upVotes'      => array_keys($model->getUpVotes()),
                    'downVotes'    => array_keys($model->getDownVotes())
                )
            );
        }

        return new JsonModel(
            array(
                'reviews'    => $reviews,
                'lastSeen'   => $models->getProperty('lastSeen'),
                'totalCount' => $models->hasProperty('totalCount')
                    ? $models->getProperty('totalCount')
                    : null
            )
        );
    }

    /**
     * Create a new review record for the specified change.
     *
     * @return  JsonModel
     */
    public function addAction()
    {
        $request    = $this->getRequest();
        $services   = $this->getServiceLocator();
        $translator = $services->get('translator');

        // only allow logged in users to add reviews
        $services->get('permissions')->enforce('authenticated');

        // request must be a post
        if (!$request->isPost()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.')
                )
            );
        }

        $p4Admin = $services->get('p4_admin');
        $p4User  = $services->get('p4_user');

        // if this is an edge server and there is a commit Swarm, forward request
        // to the commit Swarm so that the review is bound to the commit server
        $info       = $p4Admin->getInfo();
        $serverType = isset($info['serverServices']) ? $info['serverServices'] : null;
        $flags      = array('-l', '-n', ApplicationModule::PROPERTY_SWARM_COMMIT_URL);
        $commitUrl  = $p4Admin->isServerMinVersion('2013.1')
            ? $p4Admin->run('property', $flags)->getData(0, 'value')
            : null;
        if ($commitUrl && strpos('edge-server', $serverType) !== false) {
            return $this->forwardAdd($commitUrl);
        }

        $user              = $services->get('user');
        $id                = $request->getPost('id');
        $changeId          = $request->getPost('change');
        $reviewers         = $request->getPost('reviewers');
        $requiredReviewers = $request->getPost('requiredReviewers');
        $description       = $request->getPost('description');

        // validate specified change for existence and access
        try {
            $change = Change::fetch($changeId, $p4User);
        } catch (SpecNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }
        if (!isset($change)) {
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('The specified change does not exist.'),
                    'change'    => $changeId
                )
            );
        } elseif (!$change->canAccess()) {
            throw new ForbiddenException("You don't have permission to access the specified change.");
        }

        // if a review id is set, validate and fetch the review
        if (strlen($id)) {
            try {
                $review = Review::fetch($id, $p4Admin);
            } catch (RecordNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            // if we got a not found or invalid argument exception send a 404
            if (!isset($review)) {
                $this->getResponse()->setStatusCode(404);
                return new JsonModel(
                    array(
                        'isValid'   => false,
                        'error'     => $translator->t('The specified review does not exist.'),
                    )
                );
            }

            $this->restrictChangeAccess($review);
        }

        // if this is an existing review, ensure the change isn't already associated
        if (isset($review)
            && (in_array($changeId, $review->getChanges()) || $review->getVersionOfChange($changeId) !== false)
        ) {
            return new JsonModel(
                array(
                     'isValid'   => false,
                     'error'     => $translator->t('The review already contains change %d.', array($changeId)),
                     'change'    => $changeId
                )
            );
        }

        // lock the next bit via our advisory locking to avoid potential race condition where another
        // process tries to create a review from the same change
        $lock = new Lock(Review::LOCK_CHANGE_PREFIX . $changeId, $p4Admin);
        $lock->lock();

        try {
            // if there is an existing review and we weren't passed a review id or
            // the review id we were passed differs, error out.
            $existing = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $changeId), $p4Admin);
            if ($existing->count() && (!isset($review) || !in_array($review->getId(), $existing->invoke('getId')))) {
                $lock->unlock();
                return new JsonModel(
                    array(
                        'isValid'   => false,
                        'error'     => $translator->t('A Review for change %d already exists.', array($changeId)),
                        'change'    => $changeId
                    )
                );
            }

            // create the review model from the change if a review was not passed
            if (!isset($review)) {
                $review = Review::createFromChange($changeId, $p4Admin);
                $isAdd  = true;
            }

            // users can optionally pass a description and add reviewers or required reviewers
            // if they have, make use of the review filter to validate and sanitize user input
            $filterData = array_filter(
                array(
                    'description'       => $description,
                    'reviewers'         => $reviewers,
                    'requiredReviewers' => $requiredReviewers
                )
            );
            if ($filterData) {
                $filter = $this->getReviewFilter($review);
                $filter->setData($filterData);
                $filter->setValidationGroup(array_keys($filterData));

                if (!$filter->isValid()) {
                    $lock->unlock();
                    return new JsonModel(
                        array(
                            'isValid'  => false,
                            'messages' => $filter->getMessages(),
                        )
                    );
                }

                $review->set('description', $filter->getValue('description') ?: $review->get('description'));

                // 'requiredReviewers' and 'reviewers' are pseudo-fields used to set the list of reviewers
                // and which are required. if both are being passed we make sure we don't temporarily lose
                // any required reviewers as their votes and other properties would be lost.
                $filtered          = $filter->getValues();
                $requiredReviewers = isset($filtered['requiredReviewers']) ? $filtered['requiredReviewers'] : null;
                $reviewers         = isset($filtered['reviewers'])         ? $filtered['reviewers']         : null;
                if ($reviewers !== null) {
                    $review->addParticipant(array_merge($reviewers, (array) $requiredReviewers));
                    unset($filtered['reviewers']);
                }
                if ($requiredReviewers !== null) {
                    $review->setParticipantsData(array_fill_keys($requiredReviewers, true), 'required');
                    unset($filtered['requiredReviewers']);
                }
            }

            // link the change and its author to the review
            if ($change->isSubmitted()) {
                $review->addCommit($changeId);
            }
            $review->addChange($changeId)
                   ->addParticipant($user->getId())
                   ->save();
        } catch (\Exception $e) {
            // we handle this after unlocking
        }

        // we are done with updating the review, release the lock
        $lock->unlock();

        // rethrow any errors we got when we were locked down
        if (isset($e)) {
            throw $e;
        }

        // push review into queue to process the files and create notifications
        $queue = $services->get('queue');
        $queue->addTask(
            'review',
            $review->getId(),
            array(
                'user'             => $user->getId(),
                'updateFromChange' => $changeId,
                'isAdd'            => isset($isAdd) && $isAdd
            )
        );

        return new JsonModel(
            array(
                'isValid'   => true,
                'id'        => $review->getId(),
                'review'    => $review->toArray() + array('versions' => $review->getVersions())
            )
        );
    }

    /**
     * View the specified review
     */
    public function reviewAction()
    {
        $services = $this->getServiceLocator();
        $config   = $services->get('config');
        $p4Admin  = $services->get('p4_admin');
        $request  = $this->getRequest();
        $route    = $this->getEvent()->getRouteMatch();
        $id       = $route->getParam('review');
        $version  = $route->getParam('version', $request->getQuery('v'));
        $format   = $request->getQuery('format');

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // split version parameter into left and right (e.g. v2,3)
        // two comma-separated versions indicate we should diff one against the other
        $right = null;
        $left  = null;
        if ($version) {
            $parts = explode(',', $version);
            $right = count($parts) > 1 ? $parts[1] : $parts[0];
            $left  = count($parts) > 1 ? $parts[0] : null;
        }

        // if an invalid review or version was specified, send 404 response
        if (!isset($review) || ($right && !$review->hasVersion($right)) || ($left && !$review->hasVersion($left))) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        // if request was posted, consider this an edit attempt.
        if ($this->getRequest()->isPost()) {
            $services->get('permissions')->enforce('authenticated');
            return $this->editReview($review, $this->getRequest()->getPost()->toArray());
        }

        // if requested format is json, return data needed by javascript to update the review
        if ($format === 'json') {
            $preformat = new Preformat($request->getBaseUrl());
            return new JsonModel(
                array(
                    'review'            => $review->toArray() + array('versions' => $review->getVersions()),
                    'avatars'           => $this->getReviewAvatars($review),
                    'transitions'       => $this->getReviewTransitions($review),
                    'description'       => $preformat->filter($review->get('description')),
                    'canEditReviewers'  => $this->canEditReviewers($review)
                )
            );
        }

        // get file information for the requested version(s)
        // if a second version was specified, we 'diff' the file lists
        // note we fetch max + 1 so that we know if we've exceeded max.
        $p4      = $services->get('p4');
        $change  = Change::fetch($right ? $review->getChangeOfVersion($right) : $review->getHeadChange(), $p4);
        $against = $left ? Change::fetch($review->getChangeOfVersion($left), $p4) : null;
        $max     = isset($config['p4']['max_changelist_files']) ? (int) $config['p4']['max_changelist_files'] : 1000;
        $files   = $this->getAffectedFiles($review, $change, $against, $max ? $max + 1 : null);

        // if we've exceeded max files, indicate we've cropped the file list and drop the last element
        if ($max && count($files) > $max) {
            $cropped = true;
            array_pop($files);
        }

        // filter files to comply with user's IP-based protections
        $ipProtects = $services->get('ip_protects');
        $files      = $ipProtects->filterPaths($files, Protections::MODE_LIST, 'depotFile');

        // generate add/edit/delete metrics
        $counts = array('adds' => 0, 'edits' => 0, 'deletes' => 0);
        foreach ($files as $file) {
            $counts['adds']    += (int) $file['isAdd'];
            $counts['edits']   += (int) $file['isEdit'];
            $counts['deletes'] += (int) $file['isDelete'];
        }

        // compute base-path (can't rely on change object when diffing two changes)
        // note: because the paths are sorted, we can get away with a clever trick
        // and just compare the first and last file.
        $basePath = '';
        if (count($files)) {
            $last    = end($files);
            $first   = reset($files);
            $length  = min(strlen($first['depotFile']), strlen($last['depotFile']));
            $compare = $p4->isCaseSensitive() ? 'strcmp' : 'strcasecmp';
            for ($i = 0; $i < $length; $i++) {
                if ($compare($first['depotFile'][$i], $last['depotFile'][$i]) !== 0) {
                    break;
                }
            }
            $basePath = substr($first['depotFile'], 0, $i);
            $basePath = substr($basePath, 0, strrpos($basePath, '/'));
        }

        // prepare json data for associated jobs - we show only jobs attached
        // to the associated swarm-managed shelf
        $jobs = $this->forward()->dispatch(
            'Changes\Controller\Index',
            array(
                'action' => 'fixes',
                'change' => $review->getId(),
                'mode'   => null,
            )
        );

        // prepare existing projects associated with the review
        $projects = $review->getProjects();

        return new ViewModel(
            array(
                'project'    => count($projects) === 1
                    ? current(array_keys($projects))
                    : null,
                'review'            => $review,
                'avatars'           => $this->getReviewAvatars($review),
                'transitions'       => $this->getReviewTransitions($review),
                'canEditReviewers'  => $this->canEditReviewers($review),
                'change'            => $change,
                'changeRev'         => $right ?: $review->getVersionOfChange($change->getId()),
                'against'           => $against,
                'againstRev'        => $against ? ($left ?: $review->getVersionOfChange($against->getId())) : null,
                'files'             => $files,
                'fileInfos'         => FileInfo::fetchAllByReview($review, $p4Admin),
                'counts'            => $counts,
                'max'               => $max,
                'cropped'           => isset($cropped) ? true : false,
                'basePath'          => $basePath,
                'jobs'              => $jobs instanceof JsonModel ? $jobs->getVariable('jobs') : array(),
                'jobSpec'           => Spec::fetch('job', $p4)
            )
        );
    }

    /**
     * Update the test status
     */
    public function testStatusAction()
    {
        $p4Admin = $this->getServiceLocator()->get('p4_admin');
        $match   = $this->getEvent()->getRouteMatch();
        $id      = $match->getParam('review');
        $status  = $match->getParam('status');
        $token   = $match->getParam('token');
        $details = $this->getRequest()->getPost()->toArray()
                 + $this->getRequest()->getQuery()->toArray();

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // this is intended to be a token authenticated action;
        // ensure a valid token was passed in
        if (!strlen($token) || $review->getToken() !== $token) {
            throw new ForbiddenException(
                'Invalid or missing token; cannot update test status'
            );
        }

        $version    = (int) end(explode('.v', $token));
        $oldStatus  = $review->get('testStatus');
        $oldDetails = $review->getTestDetails(true);
        $oldVersion = $oldDetails !== null ? (int) $oldDetails['version'] : null;

        // if the review touches multiple projects, we could get multiple results for the same version
        // in that case we want to preserve the details of the first failure
        if (count($review->getProjects()) > 1 && $version === $oldVersion && $oldStatus === Review::TEST_STATUS_FAIL) {
            $status  = $oldStatus;
            $details = $oldDetails;
        }

        // we always carry forward timing details
        $details['startTimes']   = $oldDetails['startTimes'];
        $details['endTimes']     = $oldDetails['endTimes'];
        $details['averageLapse'] = $oldDetails['averageLapse'];

        // we always update the version and record the end time
        $details['version']      = $version;
        $details['endTimes'][]   = time();

        // if this is the last result we expect, update the average lapse time
        if ($details['startTimes'] && count($details['endTimes']) >= count($details['startTimes'])) {
            $lapse = max($details['endTimes']) - min($details['startTimes']);
            $details['averageLapse'] = $details['averageLapse']
                ? ($details['averageLapse'] + $lapse) / 2
                : $lapse;
        }

        $result = $this->editReview($review, array('testStatus' => $status, 'testDetails' => $details));

        // pluck specific fields - token only grants access to update status, not review details
        return new JsonModel(array('isValid'  => $result->isValid, 'messages' => $result->messages));
    }

    /**
     * Update the deploy status
     */
    public function deployStatusAction()
    {
        $p4Admin = $this->getServiceLocator()->get('p4_admin');
        $match   = $this->getEvent()->getRouteMatch();
        $id      = $match->getParam('review');
        $status  = $match->getParam('status');
        $token   = $match->getParam('token');
        $details = $this->getRequest()->getPost()->toArray()
                 + $this->getRequest()->getQuery()->toArray();

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // this is intended to be a token authenticated action;
        // ensure a valid token was passed in
        if (!strlen($token) || $review->getToken() !== $token) {
            throw new ForbiddenException(
                'Invalid or missing token; cannot update deploy status'
            );
        }

        $result = $this->editReview($review, array('deployStatus' => $status, 'deployDetails' => $details));

        // pluck specific fields - token only grants access to update status, not review details
        return new JsonModel(array('isValid'  => $result->isValid, 'messages' => $result->messages));
    }

    /**
     * Vote up/down the current review
     */
    public function voteAction()
    {
        // only allow votes for logged in users
        $services   = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');
        $p4Admin    = $services->get('p4_admin');
        $user       = $services->get('user');
        $translator = $services->get('translator');
        $request    = $this->getRequest();
        $route      = $this->getEvent()->getRouteMatch();
        $id         = $route->getParam('review');
        $userId     = $request->getPost('user');
        $version    = $request->getPost('version');
        $vote       = strtolower($route->getParam('vote', $request->getPost('vote')));

        // if a userId was passed we will make sure it matches the current user
        if (isset($userId) && $userId !== $user->getId()) {
            throw new ForbiddenException('Not logged in as ' . $userId);
        }

        if (!$request->isPost()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.')
                )
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review) || !isset($vote)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        return $this->editReview($review, array('vote' => array('value' => $vote, 'version' => $version)));
    }

    /**
     * edit the active user's review preferences for the current review
     * at present only required is supported but over time this will be extended out
     * to more generically support properties in an extensible manner
     */
    public function reviewerAction()
    {
        $services   = $this->getServiceLocator();
        $p4Admin    = $services->get('p4_admin');
        $user       = $services->get('user');
        $translator = $services->get('translator');
        $request    = $this->getRequest();
        $route      = $this->getEvent()->getRouteMatch();
        $id         = $route->getParam('review');
        $userId     = $route->getParam('user');

        // only allow votes for logged in users
        $services->get('permissions')->enforce('authenticated');

        // we only support PATCH/DELETE or a simulated patch/delete
        $isPatch  = $request->isPatch()
            || ($request->isPost() && strtolower($request->getQuery('_method')) === 'patch');
        $isDelete = $request->isDelete()
            || ($request->isPost() && strtolower($request->getQuery('_method')) === 'delete');
        if (!$isPatch && !$isDelete) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. PATCH or DELETE required.')
                )
            );
        }

        // make sure the userId matches the current user
        if ($userId !== $user->getId()) {
            throw new ForbiddenException('Not logged in as ' . $userId);
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        return $isDelete
            ? $this->editReview($review, array('leave'     => $userId))
            : $this->editReview($review, array('patchUser' => $request->getPost()));
    }

    /**
     * edit the reviewers for the current review
     */
    public function reviewersAction()
    {
        $services          = $this->getServiceLocator();
        $p4Admin           = $services->get('p4_admin');
        $request           = $this->getRequest();
        $route             = $this->getEvent()->getRouteMatch();
        $id                = $route->getParam('review');
        $reviewers         = $request->getPost('reviewers', array());
        $requiredReviewers = $request->getPost('requiredReviewers', array());

        $services->get('permissions')->enforce('authenticated');

        if (!$request->isPost()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => 'Invalid request method. HTTP POST required.'
                )
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        return $this->editReview($review, array('reviewers' => $reviewers, 'requiredReviewers' => $requiredReviewers));
    }

    /**
     * Execute a transition on the review
     */
    public function transitionAction()
    {
        // only allow transition if they are logged in
        $services     = $this->getServiceLocator();
        $config       = $services->get('config');
        $services->get('permissions')->enforce('authenticated');

        $p4Admin      = $services->get('p4_admin');
        $user         = $services->get('user');
        $queue        = $services->get('queue');
        $translator   = $services->get('translator');
        $id           = $this->getEvent()->getRouteMatch()->getParam('review');
        $request      = $this->getRequest();
        $response     = $this->getResponse();
        $state        = $request->getPost('state');
        $jobs         = $request->getPost('jobs');
        $fixStatus    = $request->getPost('fixStatus');
        $wait         = $request->getPost('wait');
        $description  = trim($request->getPost('description'));

        if (!$request->isPost()) {
            return new JsonModel(
                array(
                     'isValid'   => false,
                     'error'     => $translator->t('Invalid request method. HTTP POST required.')
                )
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        // if the user has not supplied a commit description, borrow the review description
        if ($state == 'approved:commit' && !strlen($description)) {
            $description = $review->get('description');
        }

        // let edit do the validation and work. always clear commit status.
        // clearing commit status allows the user to move to needs review/revision,
        // archive or reject to escape a hung commit.
        $values = array('state' => $state, 'commitStatus' => null);

        // approve/reject states will also upvote/downvote
        if (strpos($state, Review::STATE_APPROVED) === 0 || $state == Review::STATE_REJECTED) {
            $values['vote'] = array('value' => $state == Review::STATE_REJECTED ? -1 : 1);
        }

        $json = $this->editReview($review, $values, $description);

        // if we received a description for a non-commit and edit succeeded; add a comment
        if (strlen($description) && $state != 'approved:commit' && $json->getVariable('isValid')) {
            $comment = new Comment($p4Admin);
            $comment->set(
                array(
                    'topic'   => 'reviews/' . $review->getId(),
                    'user'    => $user->getId(),
                    'context' => array('review' => $review->getId()),
                    'body'    => $description
                )
            )->save();

            // push comment into queue for possible further processing
            // note: we pass 'quiet' so that no activity is created and no mail is sent.
            $queue->addTask('comment', $comment->getId(), array('quiet' => true, 'current' => $comment->get()));
        }

        // if we aren't doing a commit, or we want to but edit failed, simply return
        if ($state != 'approved:commit' || $json->getVariable('isValid') != true) {
            return $json;
        }

        // looks like we're in for a commit; disconnect the browser to get it rolling
        if (!$wait) {
            $response->getHeaders()->addHeaderLine('Content-Type: application/json; charset=utf-8');
            $response->setContent($json->serialize());
            $this->disconnect();
        }

        // large changes can take a while to commit
        ini_set(
            'max_execution_time',
            isset($config['reviews']['commit_timeout'])
            ? (int) $config['reviews']['commit_timeout']
            : 1800
        );

        // commit the review as the user, and check whether we should attribute the commit to the review author
        $p4User       = $this->getServiceLocator()->get('p4_user');
        $creditAuthor = isset($config['reviews']['commit_credit_author']) && $config['reviews']['commit_credit_author'];

        // if jobs were not provided, check to see if any existing jobs should be carried over to the commit
        if ($jobs === null) {
            $jobs = $p4User->run('fixes', array('-c', $review->getId()))->getData();
            $jobs = array_map(
                function ($value) {
                    return $value['Job'];
                },
                $jobs
            );
        }

        try {
            $commit = $review->commit(
                array(
                    Review::COMMIT_DESCRIPTION   => $description,
                    Review::COMMIT_JOBS          => is_array($jobs) ? $jobs : null,
                    Review::COMMIT_FIX_STATUS    => $fixStatus,
                    Review::COMMIT_CREDIT_AUTHOR => $creditAuthor
                ),
                $p4User
            );
        } catch (ConflictException $e) {
            // inform the user that files are outdated
            return new JsonModel(
                array('isValid' => false, 'error' => 'Out of date files must be resolved or reverted.')
            );
        } catch (CommandException $e) {
            // handle invalid job ID by returning an informative error response
            $pattern = "/(Job '[^']*' doesn't exist.)/s";
            if (preg_match($pattern, $e->getMessage(), $matches)) {
                return new JsonModel(array('isValid' => false, 'error' => $matches[1]));
            }

            // handle an invalid job status by returning an informative error response
            $pattern = "/(Job fix status must be one of [^\.]*\.)/s";
            if (preg_match($pattern, $e->getMessage(), $matches)) {
                return new JsonModel(array('isValid' => false, 'error' => $matches[1]));
            }

            // fall back to original CommandException handling
            throw $e;
        }

        // re-fetch the review and update the json model with review and commit data
        if ($wait) {
            $review = Review::fetch($review->getId(), $p4Admin);

            return $json
                ->setVariable('isValid', isset($commit))
                ->setVariable('commit',  isset($commit) ? $commit->getId() : null)
                ->setVariable('review',  $review->get());
        }

        return $response;
    }

    /**
     * Remove a version from a review
     *
     * @todo    do the project re-assessment in a worker?
     * @todo    rebuild the canonical shelf
     * @todo    safer to reference versions by change?
     */
    public function deleteVersionAction()
    {
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $match    = $this->getEvent()->getRouteMatch();
        $id       = $match->getParam('review');
        $version  = $match->getParam('version');

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }
        if (!isset($review) || !$review->hasVersion($version)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictChangeAccess($review);

        // only admins can delete versions from reviews
        $services->get('permissions')->enforce('admin');

        // refuse to delete the only version
        // (that should probably be a delete review operation, but we don't have one)
        $versions = $review->getVersions();
        if (count($versions) <= 1) {
            throw new ForbiddenException("Cannot remove the last version of a review.");
        }

        // remove version's change from commits/changes lists
        $version--;
        $change = isset($versions[$version]['archiveChange'])
            ? $versions[$version]['archiveChange']
            : $versions[$version]['change'];
        $review->setChanges(array_diff($review->getChanges(), array($change)));
        $review->setCommits(array_diff($review->getCommits(), array($change)));

        // drop the version
        unset($versions[$version]);
        $review->setVersions($versions);

        // re-assess projects
        $review->setProjects(null);
        foreach ($versions as $version) {
            $change = Change::fetch($version['change'], $p4Admin);
            $review->addProjects(Project::getAffectedByChange($change, $p4Admin));
        }

        $review->save();

        return new JsonModel(array('review' => $review->get()));
    }

    /**
     * Upgrade review records.
     *
     * The application-wide upgrade level is stored in a 'swarm-upgrade-level' key.
     * If the value is greater-than or equal to the latest level we know about, this action
     * will report that upgrades are done. If the counter does not exist or the value is
     * less-than the latest, we will do the upgrade.
     *
     * The review model's save() method handles upgrading automatically, so all we do here
     * is iterate over all review records and re-save those that haven't been upgraded yet.
     * The upgrade level of each record is indicated by the (hidden) 'upgrade' field.
     *
     * There is some complexity around upgrading and reporting status to the browser.
     * The initial request disconnects the client and runs in the background.
     * We use a refresh header to tell the browser to reload the page to show the latest
     * status. Status is written to a 'swarm-upgrade-status' key. The status key is cleared
     * when upgrades are complete.
     */
    public function upgradeAction()
    {
        $response = $this->getResponse();
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');

        // only allow upgrade if user is an admin
        $services->get('permissions')->enforce('admin');

        // if an upgrade is in progress, report the status
        $status = new Key($p4Admin);
        $status->setId('swarm-upgrade-status');
        if ($status->get()) {
            $response->getHeaders()->addHeaderLine('Refresh: 1');
            return new ViewModel(array('status' => $status->get()));
        }

        // if upgrade is all done, report done
        $level = new Key($p4Admin);
        $level->setId('swarm-upgrade-level');
        if ($level->get() > 0) {
            return new ViewModel(array('status' => 'done'));
        }

        // looks like we need to upgrade!
        // we want to avoid two upgrade processes running concurrently, so we
        // increment the counter and verify we got the expected result (1).
        if ((int) $level->increment() !== 1) {
            $level->set(1);
            throw new \Exception("Cannot upgrade. It appears another upgrade is in progress.");
        }

        // write out 'started' to status counter, tell client to refresh
        // and disconnect so we can process upgrade in the background.
        $status->set('started');
        $response->getHeaders()->addHeaderLine('Refresh: 0');
        $this->disconnect();

        // ensure we don't run out of resources prematurely
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        // fetch all review records and re-save them to trigger upgrade logic
        $progress = 0;
        $reviews  = Review::fetchAll(array(), $p4Admin);
        foreach ($reviews as $review) {
            if ($review->get('upgrade') < Review::UPGRADE_LEVEL) {
                $review->save();
            }
            $progress++;
            $status->set(floor(($progress / $reviews->count()) * 100) . '%');
        }

        // all done, clear status counter.
        $status->delete();
    }

    /**
     * Set file information. At present, this only knows how to mark a file as read/unread.
     */
    public function fileInfoAction()
    {
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $p4User   = $services->get('p4_user');
        $route    = $this->getEvent()->getRouteMatch();
        $request  = $this->getRequest();
        $response = $this->getResponse();
        $review   = $route->getParam('review');
        $version  = $route->getParam('version');
        $file     = $route->getParam('file');

        // user must be logged in to adjust file info
        $services->get('permissions')->enforce('authenticated');

        // fetch review, or return 404
        try {
            $review = Review::fetch($review, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }
        if (!$review instanceof Review) {
            $response->setStatusCode(404);
            return;
        }
        $this->restrictChangeAccess($review);

        // ensure specified version(s) exists, else 404
        // note we grab the 'archive' change number for consistency
        $against = strpos($version, ',') ? current(explode(',', $version)) : null;
        $version = end(explode(',', $version));
        try {
            $change  = $review->getChangeOfVersion($version, true);
            $against = $against ? $review->getChangeOfVersion($against, true) : null;
        } catch (\Exception $e) {
            $response->setStatusCode(404);
            return;
        }

        // ensure specified file exists, else 404
        try {
            $file = trim($file, '/');
            $file = strlen($file) ? '//' . $file : null;
            $file = File::fetch($file ? $file . '@=' . $change : null, $p4User);
        } catch (FileException $e) {
            // try again if we are diffing against an older version
            // this is needed in the case of files that have been removed
            if ($against) {
                try {
                    $file = File::fetch($file ? $file . '@=' . $against : null, $p4User);
                } catch (FileException $e) {
                    // handled below
                }
            }

            if (!$file instanceof File) {
                $response->setStatusCode(404);
                return;
            }
        }

        // validate posted data
        $filter = $this->getFileInfoFilter()->setData($request->getPost());
        if (!$filter->isValid()) {
            $response->setStatusCode(400);
            return new JsonModel(
                array(
                    'isValid'  => false,
                    'messages' => $filter->getMessages()
                )
            );
        }

        // at this point, we are good to update the record
        // if the record doesn't exist yet, make one
        try {
            $fileInfo = FileInfo::fetch(
                FileInfo::composeId($review->getId(), $file->getDepotFilename()),
                $p4Admin
            );
        } catch (RecordNotFoundException $e) {
            $fileInfo = new FileInfo($p4Admin);
            $fileInfo->set('review',    $review->getId())
                     ->set('depotFile', $file->getDepotFilename());
        }

        if ($filter->getValue('read')) {
            // use digest if we have one and it is current
            $digest = $file->hasField('digest') && $file->get('headChange') == $change
                ? $file->get('digest')
                : null;

            $fileInfo->markReadBy($filter->getValue('user'), $version, $digest);
        } else {
            $fileInfo->clearReadBy($filter->getValue('user'));
        }
        $fileInfo->save();

        return new JsonModel(
            array(
                'isValid' => true,
                'readBy'  => $fileInfo->getReadBy()
            )
        );
    }

    protected function getFileInfoFilter()
    {
        $filter = new InputFilter;
        $user   = $this->getServiceLocator()->get('user');

        // ensure user is provided and refers to the active user
        $filter->add(
            array(
                'name'          => 'user',
                'required'      => true,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($user) {
                                if ($value !== $user->getId()) {
                                    return 'Not logged in as %s';
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // ensure read flag is provided and has value 1 or 0 (for true/false)
        $filter->add(
            array(
                'name'          => 'read',
                'required'      => true,
                'validators'    => array(
                    array(
                        'name'      => 'InArray',
                        'options'   => array(
                            'haystack' => array('1', '0')
                        )
                    )
                )
            )
        );

        return $filter;
    }

    /**
     * Edit the review record.
     *
     * @param  Review       $review         review record to edit
     * @param  array        $data           list with field-value pairs to change in $review record
     *                                      (values for fields not present in $data will remain unchanged)
     * @param  string       $description    optional - text to accompany update (e.g. comment or commit message)
     * @return JsonModel
     */
    protected function editReview(Review $review, array $data, $description = null)
    {
        // validate user input
        $filter = $this->getReviewFilter($review);
        $filter->setData($data);

        // indicate that we want to validate only fields present in $data
        $filter->setValidationGroup(array_keys($data));

        // if the data is valid, update the review record
        $isValid = $filter->isValid();
        if ($isValid) {
            $services = $this->getServiceLocator();
            $queue    = $services->get('queue');
            $user     = $services->get('user');
            $old      = $review->get();
            $filtered = $filter->getValues();

            // dear future me, if you are about to add a pseudo field or edit the
            // order of items below there are a few things to keep in mind:
            // - we want direct modifications of involved reviewers, require-reviewers to
            //   occur early on so we can detect them, this means things like voting, @mentions,
            //   adding active user as participant, etc. have to happen after isReviewersChange detection
            // - put another way, anything that changes participantsData should occur after
            //   we have detected $isReviewersChange to avoid erroneously tripping it (e.g. voting)
            // - we should keep in mind the event.review listener will also 'quiet' some things
            //   so we don't have to capture all ignorable events at this level

            // 'patchUser' is a pseudo-field indicating we should adjust the given user's properties
            $patchUser = isset($filtered['patchUser']) ? $filtered['patchUser'] : null;
            foreach ((array) $patchUser as $key => $value) {
                $review->setParticipantData($user->getId(), $value, $key);
            }
            unset($filtered['patchUser']);

            // 'join' is a pseudo-field indicating the active user wishes to join the review
            $join = isset($filtered['join']) ? $filtered['join'] : null;
            unset($filtered['join']);
            $review->addParticipant($join);

            // 'leave' is a pseudo-field indicating the active user wishes to leave the review
            $leave = isset($filtered['leave']) ? $filtered['leave'] : null;
            unset($filtered['leave']);
            $review->setParticipants(array_diff($review->getParticipants(), (array) $leave));

            // 'requiredReviewers' and 'reviewers' are pseudo-fields used to set the list of reviewers
            // and which are required. if both are being passed we make sure we don't temporarily lose
            // any required reviewers as their votes and other properties would be lost.
            $requiredReviewers = isset($filtered['requiredReviewers']) ? $filtered['requiredReviewers'] : null;
            $reviewers         = isset($filtered['reviewers'])         ? $filtered['reviewers']         : null;
            if ($reviewers !== null) {
                $review->setParticipants(array_merge($reviewers, (array) $requiredReviewers));
                unset($filtered['reviewers']);
            }
            if ($requiredReviewers !== null) {
                $review->setParticipantsData(array_fill_keys($requiredReviewers, true), 'required');
                unset($filtered['requiredReviewers']);
            }

            // detect if anyone adjusted participants, do this before we deal with votes as they
            // will also muck the participants data but are classified separately
            $isReviewersChange = $old['participants'] != $review->getParticipantsData();

            // 'vote' is a pseudo-field indicating we should add a vote for the current user
            $vote = isset($filtered['vote']) ? $filtered['vote'] : null;
            if ($vote !== null) {
                $review->addParticipant($user->getId())
                       ->addVote($user->getId(), $vote['value'], $vote['version']);
                unset($filtered['vote']);
            }

            // set all of the other non-pseudo fields
            $review->set($filtered);

            // if no fields we care about have changed, this isn't worth reporting on. this
            // causes us to ignore the approved:commit transition if already approved, changes
            // to just the deployStatus and nops such as edit reviewers with no modifications.
            // we don't quiet fields such as testStatus, even if they haven't changed, though
            // later logic may still choose to.
            $ignored = array_flip(array('deployStatus', 'deployDetails'));
            $notify  = array_flip(array('testStatus'));
            $quiet   = !array_intersect_key($filtered, $notify)
                     && array_diff_key($review->get(), $ignored) == array_diff_key($old, $ignored);

            // add whoever just edited the review as a participant unless the operation was
            // to set all reviewers (as we should simply honor the list in that case) or to
            // leave (as adding you back would be silly).
            // we do this late so we can detect if any 'requested' changes occurred accurately.
            if ($reviewers === null && $leave === null) {
                $review->addParticipant($user->getId());
            }

            $review->save();

            // convenience function to detect changes - special handling for the state field
            $old += array(
                'state'        => null,
                'testStatus'   => null,
                'deployStatus' => null,
                'description'  => null
            );
            $hasChanged = function ($field) use ($filtered, $old) {
                if (!array_key_exists($field, $filtered)) {
                    return false;
                }

                $newValue = $filtered[$field];
                $oldValue = isset($old[$field]) ? $old[$field] : null;

                // 'approved' to 'approved:commit' is not considered a state change
                if ($field === 'state' && $newValue === Review::STATE_APPROVED . ':commit') {
                    $newValue = Review::STATE_APPROVED;
                }

                return $newValue != $oldValue;
            };

            // description of the canonical shelf should match review description
            // note: we need a valid client for this operation
            if ($hasChanged('description')) {
                $p4 = $review->getConnection();
                $p4->getService('clients')->grab();
                $change = Change::fetch($review->getId(), $p4);

                // if this is a git review update the description but keep the
                // existing git info (keys/values), otherwise just use the review
                // description as-is for the update
                $description = $review->get('description');
                if ($review->getType() === 'git') {
                    $gitInfo     = new GitInfo($change->getDescription());
                    $description = $gitInfo->setDescription($description)->getValue();
                }

                $change->setDescription($description)->save(true);
                $p4->getService('clients')->release();

                // if description sync is enabled, synchronize review description with any associated changes
                $config   = $services->get('config');
                $keywords = $services->get('review_keywords');
                if (isset($config['reviews']['sync_descriptions']) && $config['reviews']['sync_descriptions']) {
                    $review->syncDescription(
                        $description,
                        $keywords->update($description, array('id' => $review->getId())),
                        $services->get('p4_admin')
                    );
                }
            }

            // push update into queue for further processing.
            $queue->addTask(
                'review',
                $review->getId(),
                array(
                    'user'                => $user->getId(),
                    'isStateChange'       => $hasChanged('state'),
                    'isVote'              => isset($vote['value']) ? $vote['value'] : false,
                    'isReviewersChange'   => $isReviewersChange,
                    'isDescriptionChange' => $hasChanged('description'),
                    'testStatus'          => $filter->getValue('testStatus'),   // defaults to null if not posted
                    'deployStatus'        => $filter->getValue('deployStatus'), // defaults to null if not posted
                    'description'         => trim($description),
                    'previous'            => $old,
                    'quiet'               => $quiet
                )
            );
        }

        $preformat = new Preformat($this->getRequest()->getBaseUrl());
        return new JsonModel(
            array(
                'isValid'           => $isValid,
                'messages'          => $filter->getMessages(),
                'review'            => $review->toArray() + array('versions' => $review->getVersions()),
                'avatars'           => $this->getReviewAvatars($review),
                'transitions'       => $this->getReviewTransitions($review),
                'description'       => $preformat->filter($review->get('description')),
                'canEditReviewers'  => $this->canEditReviewers($review)
            )
        );
    }

    /**
     * This method implements our access controls for who can/can-not edit the reviewers.
     * Note joining/leaving and adjusting your own required'ness is a separate operation
     * and not governed by this check.
     *
     * @param   Review  $review     review model
     * @return  bool    true if active user can edit the review, false otherwise
     */
    protected function canEditReviewers(Review $review)
    {
        $services    = $this->getServiceLocator();
        $permissions = $services->get('permissions');

        // if you aren't authenticated you aren't allowed to edit
        if (!$permissions->is('authenticated')) {
            return false;
        }

        // if you are an admin, the author, or there aren't any
        // projects associated with this review, you can edit.
        $userId = $services->get('user')->getId();
        if ($permissions->is('admin')
            || $review->get('author') === $userId
            || !$review->getProjects()
        ) {
            return true;
        }

        // looks like this review impacts projects, let's figure
        // out who the involved members and moderators are.
        $p4Admin    = $services->get('p4_admin');
        $members    = array();
        $moderators = array();
        $impacted   = $review->getProjects();
        $projects   = Project::fetchAll(array('ids' => array_keys($impacted)), $p4Admin);
        foreach ($projects as $project) {
            $branches   = $impacted[$project->getId()];
            $moderators = array_merge($moderators, $project->getModerators($branches));
            $members    = array_merge($members,    $project->getAllMembers());
        }

        // if there are moderators, only they can edit.
        // if there aren't any moderators, then any members can edit.
        return in_array($userId, $moderators ?: $members);
    }

    /**
     * Get transitions for review model and filter response.
     * If the user isn't a candidate to transition this review, false is returned. It is recommended
     * the transition UI be disabled in that case.
     * If the user is a candidate, an array will be returned though it may be empty. Even an empty
     * array indicates transitioning is viable and the UI may opt to stay enable and show items such
     * as 'add a commit' in this case.
     *
     * @param   Review      $review     review model
     * @return  array|false             array of available transitions (may be empty) or false
     */
    protected function getReviewTransitions(Review $review)
    {
        $services    = $this->getServiceLocator();
        $config      = $services->get('config');
        $userId      = $services->get('user')->getId();
        $p4Admin     = $services->get('p4_admin');
        $permissions = $services->get('permissions');
        $transitions = $review->getTransitions();

        // filter transitions by user role (author/member/moderator)
        // early exit for anonymous users
        if (!$permissions->is('authenticated')) {
            return false;
        }

        // prepare list of members/moderators of review-related projects/project-branches
        $members    = array();
        $moderators = array();
        $impacted   = $review->getProjects();
        if ($impacted) {
            $projects = Project::fetchAll(array('ids' => array_keys($impacted)), $p4Admin);
            foreach ($projects as $project) {
                $branches   = $impacted[$project->getId()];
                $moderators = array_merge($moderators, $project->getModerators($branches));
                $members    = array_merge($members,    $project->getAllMembers());
            }
        }

        $isAuthor    = $userId === $review->get('author');
        $isMember    = in_array($userId, $members);
        $isModerator = in_array($userId, $moderators);

        // remove option to commit a review if disabled in route params or config
        $disableCommitRoute  = $this->getEvent()->getRouteMatch()->getParam('disableCommit');
        $disableCommitConfig = isset($config['reviews']['disable_commit']) && $config['reviews']['disable_commit'];
        if ($disableCommitRoute || $disableCommitConfig) {
            unset($transitions[Review::STATE_APPROVED . ':commit']);
        }

        // if we have required reviewers, and they haven't all
        // upvoted don't allow anyone to approve or commit unless
        // the current user is the last required reviewer to vote, then we
        // will let them approve, and take their approval as an up-vote
        $required = array_filter($review->getParticipantsData('required'));
        $upVotes  = $review->getUpVotes();
        if (array_diff_key($required, $upVotes + array($userId => null))) {
            unset($transitions[Review::STATE_APPROVED]);
            unset($transitions[Review::STATE_APPROVED . ':commit']);
        }

        // deny authors approving their own reviews if self-approval is disabled in config
        // note this will take place even if the author is also a moderator
        if ($isAuthor
            && $review->get('state') !== Review::STATE_APPROVED
            && isset($config['reviews']['disable_self_approve'])
            && $config['reviews']['disable_self_approve'] == true
        ) {
            unset($transitions[Review::STATE_APPROVED]);
            unset($transitions[Review::STATE_APPROVED . ':commit']);
        }

        // if review touches project(s) then
        //  - only members and the review author can change state or attach commits
        //
        // if review touches moderated branch(es) then
        //  - only moderators can approve or reject the review
        //  - authors who are not moderators can move between needs-review/needs-revision/archived and
        //    can attach commits; they cannot approve or reject review
        //  - members can move between needs-review/needs-revision and can attach
        //    commits; they cannot approve/reject or archive
        //  - users that are not project members, moderators or the author cannot perform any transitions
        if (count($moderators)) {
            $currentState = $review->get('state');
            $authorStates = array(Review::STATE_NEEDS_REVIEW, Review::STATE_NEEDS_REVISION, Review::STATE_ARCHIVED);
            $memberStates = array(Review::STATE_NEEDS_REVIEW, Review::STATE_NEEDS_REVISION);
            if ($currentState === Review::STATE_APPROVED) {
                $authorStates[] = Review::STATE_APPROVED;
                $authorStates[] = Review::STATE_APPROVED . ':commit';
                $memberStates[] = Review::STATE_APPROVED;
                $memberStates[] = Review::STATE_APPROVED . ':commit';
            }

            // if the author is also a moderator, treat them as moderator
            if ($isAuthor && !$isModerator) {
                $transitions = in_array($currentState, $authorStates)
                    ? array_intersect_key($transitions, array_flip($authorStates))
                    : array();
            } elseif ($isMember && !$isAuthor && !$isModerator) {
                $transitions = in_array($currentState, $memberStates)
                    ? array_intersect_key($transitions, array_flip($memberStates))
                    : array();
            } elseif (!$isModerator) {
                $transitions = false;
            }
        } elseif (count($impacted) && !$isMember && !$isAuthor) {
            $transitions = false;
        }

        return $transitions;
    }

    /**
     * Get filter for review model input data.
     *
     * @param   Review          $review     review model for context
     * @return  InputFilter     filter for review model input data
     */
    protected function getReviewFilter(Review $review)
    {
        $filter      = new InputFilter;
        $transitions = $this->getReviewTransitions($review);
        $services    = $this->getServiceLocator();
        $p4          = $services->get('p4');
        $translator  = $services->get('translator');

        // determine if the active user is allowed to edit other reviewers
        $canEditReviewers = $this->canEditReviewers($review);

        // normalize transitions to an array, it can be false which is effectively empty set
        $transitions = is_array($transitions) ? $transitions : array();

        // declare state field
        $filter->add(
            array(
                'name'          => 'state',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($review, $transitions) {
                                if (!in_array($value, array_keys($transitions))) {
                                    return "You cannot transition this review to '%s'.";
                                }

                                // if a commit is already going on, error out for second attempt
                                if ($value == 'approved:commit' && $review->isCommitting()) {
                                    return "A commit is already in progress.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare commit status
        $filter->add(
            array(
                 'name'          => 'commitStatus',
                 'required'      => false,
                 'validators'    => array(
                     array(
                         'name'      => '\Application\Validator\Callback',
                         'options'   => array(
                             'callback'  => function ($value) use ($review) {
                                 // if a commit is already going on, don't allow clearing status
                                 if ($review->isCommitting()) {
                                     return "A commit is in progress; can't clear commit status.";
                                 }

                                 if ($value) {
                                     return "Commit status can only be cleared; not set.";
                                 }

                                 return true;
                             }
                         )
                     )
                 )
            )
        );

        // declare test-status field
        $filter->add(
            array(
                'name'          => 'testStatus',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (!in_array($value, array('pass', 'fail'))) {
                                    return "Test status must be 'pass' or 'fail'.";
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare test details field
        $filter->add(
            array(
                'name'          => 'testDetails',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // if url is set, validate it
                                if (isset($value['url']) && strlen($value['url'])) {
                                    $validator = new \Zend\Validator\Uri;
                                    if (!$validator->isValid($value['url'])) {
                                        return "Url in test details must be a valid uri.";
                                    }
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare deploy-status field
        $filter->add(
            array(
                 'name'          => 'deployStatus',
                 'required'      => false,
                 'validators'    => array(
                     array(
                         'name'      => '\Application\Validator\Callback',
                         'options'   => array(
                             'callback'  => function ($value) {
                                 if (!in_array($value, array('success', 'fail'))) {
                                     return "Deploy status must be 'success' or 'fail'.";
                                 }
                                 return true;
                             }
                         )
                     )
                 )
            )
        );

        // declare deploy details field
        $filter->add(
            array(
                 'name'          => 'deployDetails',
                 'required'      => false,
                 'validators'    => array(
                     array(
                         'name'      => '\Application\Validator\IsArray'
                     ),
                     array(
                         'name'      => '\Application\Validator\Callback',
                         'options'   => array(
                             'callback'  => function ($value) {
                                 // if url is set, validate it
                                 if (isset($value['url']) && strlen($value['url'])) {
                                     $validator = new \Zend\Validator\Uri;
                                     if (!$validator->isValid($value['url'])) {
                                         return "Url in deploy details must be a valid uri.";
                                     }
                                 }
                                 return true;
                             }
                         )
                     )
                 )
            )
        );

        // declare description field
        // we normalize line endings to \n to keep git-fusion happy and trim excess whitespace
        $filter->add(
            array(
                'name'          => 'description',
                'required'      => true,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                return preg_replace('/(\r\n|\r)/', "\n", $value);
                            }
                        )
                    ),
                    'trim'
                )
            )
        );

        // declare 'patchUser' pseudo-field for purpose of modifying active participant's properties
        $filter->add(
            array(
                'name'          => 'patchUser',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // note null/false are handled oddly on older filter_var's
                                // so just leave em be if that's what we came in with
                                $value = (array) $value;
                                if (isset($value['required'])
                                    && !is_null($value['required'])
                                    && !is_bool($value['required'])
                                ) {
                                    $value['required'] = filter_var(
                                        $value['required'],
                                        FILTER_VALIDATE_BOOLEAN,
                                        FILTER_NULL_ON_FAILURE
                                    );
                                }

                                return $value;
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (!array_key_exists('required', $value)) {
                                    return "You must specify at least one known property.";
                                }
                                if ($value['required'] === null) {
                                    return "Invalid value specified for 'required', expecting true or false";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare 'join' pseudo-field for purpose of adding active user as a reviewer
        $filter->add(
            array(
                'name'          => 'join',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($services) {
                                if ($value != $services->get('user')->getId()) {
                                    return "Cannot join review, not logged in as %s.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare 'leave' pseudo-field for purpose of removing active user as a reviewer
        $filter->add(
            array(
                'name'          => 'leave',
                'required'      => false,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($services) {
                                if ($value != $services->get('user')->getId()) {
                                    return "Cannot leave review, not logged in as %s.";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare 'reviewers' pseudo-field for purpose of editing the reviewers list
        $filter->add(
            array(
                'name'          => 'reviewers',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // throw away any provided keys
                                return array_values((array) $value);
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Zend\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($canEditReviewers) {
                                if (!$canEditReviewers) {
                                    return 'You do not have permission to edit reviewers.';
                                }
                                return true;
                            }
                        )
                    ),
                    new UsersValidator(array('connection' => $p4))
                )
            )
        );

        // declare 'requiredReviewers' pseudo-field for purpose of editing the list of required reviewers
        $filter->add(
            array(
                'name'          => 'requiredReviewers',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                // throw away any provided keys
                                return array_values((array) $value);
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\IsArray'
                    ),
                    array(
                        'name'      => '\Zend\Validator\Callback',
                        'options'   => array(
                            'callback' => function ($value) use ($canEditReviewers) {
                                if (!$canEditReviewers) {
                                    return 'You do not have permission to edit required reviewers.';
                                }
                                return true;
                            }
                        )
                    ),
                    new UsersValidator(array('connection' => $p4))
                )
            )
        );

        // declare 'vote' pseudo-field for purpose of adding user vote
        $filter->add(
            array(
                'name'          => 'vote',
                'required'      => false,
                'filters'       => array(
                    array(
                        'name'      => '\Zend\Filter\Callback',
                        'options'   => array(
                            'callback'  => function ($vote) {
                                if (!is_array($vote)) {
                                    return $vote;
                                }

                                $vote += array('value' => null, 'version' => null);
                                $valid = array('up' => 1, 'down' => -1, 'clear' => 0);
                                if (array_key_exists($vote['value'], $valid)) {
                                    $vote['value'] = $valid[$vote['value']];
                                }
                                if (!$vote['version']) {
                                    $vote['version'] = null;
                                }

                                return $vote;
                            }
                        )
                    )
                ),
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($vote) {
                                // only allow 0, -1, 1 as values
                                if (!is_array($vote) || !is_int($vote['value'])
                                    || !in_array($vote['value'], array(1, -1, 0))
                                ) {
                                    return "Invalid vote value";
                                }

                                if ($vote['version'] && !ctype_digit((string) $vote['version'])) {
                                    return "Invalid vote version";
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        return $filter;
    }

    /**
     * List files affected by the given change or between two changes.
     *
     * The intent is to show the work the author did in a review at a version or
     * between two versions. If one change is given, it is easy. We simply ask
     * the server to 'describe' the change. If two changes are given, it is hard.
     *
     * It is hard because the server can't tell us. We need to collect the list
     * of files affected by either change, analyze the file actions in each change
     * and produce information we can use to show just the diffs introduced between
     * those changes.
     *
     * @param   Review      $review     the review to get affected files in
     * @param   Change      $right      the primary (newer) change
     * @param   Change      $left       optional - an older change
     * @param   int|null    $max        optional - limit number of files returned (can cause inaccurate results)
     * @return  array   list of affected files with describe-like information
     * @throws  \InvalidArgumentException   if left or right is not a version of the review
     *                                      or if the left change is newer than the right
     * @todo    consider moving this into the review model or a utility class
     * @todo    handle moves when called with left & right (report fromFile, fromRev)
     * @todo    support non-consecutive changes (e.g. v3 vs. v1)
     */
    protected function getAffectedFiles(Review $review, Change $right, Change $left = null, $max = null)
    {
        $isAdd    = function ($action) {
            return preg_match('/add|branch|import/', $action) !== 0;
        };
        $isEdit   = function ($action) {
            return preg_match('/add|branch|import|delete/', $action) === 0;
        };
        $isDelete = function ($action) {
            return strpos($action, 'delete') !== false;
        };

        // early exit for a single change (simple case)
        if (!$left) {
            $affected = array();
            foreach ($right->getFileData(true, $max) as $file) {
                $file['isAdd']    = $isAdd($file['action']);
                $file['isEdit']   = $isEdit($file['action']);
                $file['isDelete'] = $isDelete($file['action']);
                $affected[]       = $file;
            }

            return $affected;
        }

        // left must be older than right
        $leftVersion  = $review->getVersionOfChange($left);
        $rightVersion = $review->getVersionOfChange($right);
        if (!$leftVersion || !$rightVersion) {
            throw new \InvalidArgumentException(
                "Left and right must be versions of the review."
            );
        }

        // because we have two changes, we need to collect files affected by either change
        // and we need to keep both sides of file info so we can tell what happened.
        // if the server is case insensitive, we lower case the depotFile key to ensure
        // accurate left/right cross-references.
        $affected        = array();
        $isCaseSensitive = $this->getServiceLocator()->get('p4')->isCaseSensitive();
        foreach (array($left, $right) as $i => $change) {
            foreach ($change->getFileData(true, $max) as $file) {
                $depotFile = $isCaseSensitive ? $file['depotFile'] : strtolower($file['depotFile']);
                $affected += array($depotFile => array('left' => null, 'right' => null));
                $file     += array('digest' => null, 'fileSize' => null);
                $affected[$depotFile][$i === 0 ? 'left' : 'right'] = $file;
            }
        }

        // we need to resort filesPaths after we build the affected array
        // in order to match the ordering we would get from getFileData
        ksort($affected, SORT_STRING);

        // because we merged files from two different changes, we need to re-apply max
        // otherwise we could end up returning more files than the caller requested
        array_splice($affected, $max);

        // assess what happened to each file - we are looking for three things:
        //  1. action - determine what the basic action was (add, edit, delete)
        //  2. diff   - determine what revs should be diffed (left vs. right)
        //  3. remove - if the file was not meaningfully affected, remove it
        //
        // the following table which shows how we treat each file given different
        // combinations of left/right actions:
        //
        //                        R I G H T
        //
        //                  |  A  |  E  |  D  |  X
        //             -----+-----+-----+-----+-----
        //               A  |  E  |  E  |  D  | D/R
        //         L   -----+-----+-----+-----+-----
        //         E     E  |  E  |  E  |  D  | E/R
        //         F   -----+-----+-----+-----+-----
        //         T     D  |  A  |  A  |  R  | A/R
        //             -----+-----+-----+-----+-----
        //               X  |  A  |  E  |  D  |
        //
        //    A = add
        //    E = edit
        //    D = delete
        //    X = not present
        //    R = remove (no difference)
        //  D/R = delete if left shelved, otherwise remove (no diff)
        //  E/R = reverse diff (edits undone) if left shelved, otherwise remove (no diff)
        //  A/R = add if left shelved, otherwise remove (no diff)
        //
        foreach ($affected as $depotFile => $file) {
            $action = null;

            // for most cases, we diff using '#rev' for submits and '@=change' for shelves
            $diffLeft  = $left->isSubmitted()
                ? (isset($file['left'])  ? '#' . $file['left']['rev']  : null)
                : '@=' . $left->getId();
            $diffRight = $right->isSubmitted()
                ? (isset($file['right']) ? '#' . $file['right']['rev'] : null)
                : '@=' . $right->getId();

            // handle the cases where we have both a left and right side
            if (isset($file['left'], $file['right'])) {
                // check the digests - if they match and action doesn't involve delete, drop the file
                if ($file['left']['digest'] == $file['right']['digest']
                    && !$isDelete($file['left']['action']) && !$isDelete($file['right']['action'])
                ) {
                    unset($affected[$depotFile]);
                    continue;
                }

                // both deletes    = no diff, drop the file
                // delete on left  = add
                // delete on right = delete
                // add/edit combo  = edit
                if ($isDelete($file['left']['action']) && $isDelete($file['right']['action'])) {
                    unset($affected[$depotFile]);
                    continue;
                } elseif ($isDelete($file['left']['action'])) {
                    $action = 'add';
                } elseif ($isDelete($file['right']['action'])) {
                    $action = 'delete';
                } else {
                    $action = 'edit';
                }
            }

            // file only present on left
            if (!isset($file['right'])) {
                // if left hand change was committed, just drop the file
                // (the fact it's missing on the right means it's unchanged)
                if ($left->isSubmitted()) {
                    unset($affected[$depotFile]);
                    continue;
                }

                // since the left hand change is shelved, the absence of a file on
                // the right means whatever was done on the left, has been undone
                // therefore, we 'flip' the diff around
                // add    = delete
                // edit   = edit (edits undone)
                // delete = add
                if ($isAdd($file['left']['action'])) {
                    $action    = 'delete';
                    $diffRight = null;
                } elseif ($isEdit($file['left']['action'])) {
                    // edits going away, put the have-rev on the right
                    $action    = 'edit';
                    $diffRight = '#' . $file['left']['rev'];
                } else {
                    // file coming back, put have-rev on the right and clear the left
                    $action    = 'add';
                    $diffRight = '#' . $file['left']['rev'];
                    $diffLeft  = null;
                }
            }

            // file only present on right
            // if file is added, clear diff-left (nothing to diff against)
            // otherwise diff against have-rev for shelves and previous for commits
            if (!isset($file['left'])) {
                if ($isAdd($file['right']['action'])) {
                    $diffLeft = null;
                } else {
                    $diffLeft = $right->isSubmitted()
                        ? '#' . ($file['right']['rev'] - 1)
                        : '#' . $file['right']['rev'];
                }
            }

            // action should default to the action of the right-hand file
            $action = $action ?: ($file['right'] ? $file['right']['action'] : null);

            // type should default to the right-hand file, but fallback to the left
            $type = $file['right']
                ? $file['right']['type']
                : (isset($file['left']['type']) ? $file['left']['type'] : null);

            // compose the file information to keep and return to the caller
            // we start with basic 'describe' output, and add in some useful bits
            // if we don't have a right-side, then we can't populate certain fields
            $file = array(
                'depotFile' => $file[($file['right'] ? 'right' : 'left')]['depotFile'],
                'action'    => $action,
                'type'      => $type,
                'rev'       => $file['right'] ? $file['right']['rev']      : null,
                'fileSize'  => $file['right'] ? $file['right']['fileSize'] : null,
                'digest'    => $file['right'] ? $file['right']['digest']   : null,
                'isAdd'     => $isAdd($action),
                'isEdit'    => $isEdit($action),
                'isDelete'  => $isDelete($action),
                'diffLeft'  => $diffLeft,
                'diffRight' => $diffRight
            );
            $affected[$depotFile] = $file;
        }

        return $affected;
    }

    /**
     * Gets a list of rendered html for avatars of users in this review.
     *
     * @param   Review  $review     the review to get avatars for
     * @param   string|int          $size   the size of the avatar (e.g. 64, 128) (default=40)
     * @param   bool                $link   optional - link to the user (default=true)
     * @param   bool                $class  optional - class to add to the image
     * @param   bool                $fluid  optional - match avatar size to the container (default=false)
     * @return  array   list of rendered avatars indexed by usernames
     */
    protected function getReviewAvatars(Review $review, $size = 40, $link = true, $class = null, $fluid = false)
    {
        $avatar  = $this->getServiceLocator()->get('viewhelpermanager')->get('avatar');
        $avatars = array();
        foreach ($review->getParticipants() as $user) {
            $avatars[$user] = $avatar($user, $size, $link, $class, $fluid);
        }
        return $avatars;
    }

    /**
     * Helper to ensure that the given review is accessible by the current user.
     *
     * @param   Review  $review     the review to check access for
     * @throws  ForbiddenException  if the current user can't access the review.
     */
    protected function restrictChangeAccess($review)
    {
        // access to the review depends on access to its head change
        if (!$this->getServiceLocator()->get('changes_filter')->canAccess($review->getHeadChange())) {
            throw new ForbiddenException("You don't have permission to access this review.");
        }
    }

    /**
     * Prepare FetchAll options for searching reviews based on a query
     *
     * @param  Parameters   $query  query parameters to build options from
     * @return array        the resulting options array
     */
    protected function getFetchAllOptions(Parameters $query)
    {
        $boolean = new FormBoolean;
        $options = array(
            Review::FETCH_MAXIMUM           => $query->get('max', 50),
            Review::FETCH_AFTER             => $query->get('after'),
            Review::FETCH_TOTAL_COUNT       => true,
            Review::FETCH_KEYWORDS_FIELDS   => array('participants', 'description', 'projects'),
            Review::FETCH_BY_AUTHOR         => $query->get('author'),
            Review::FETCH_BY_CHANGE         => $query->get('change'),
            Review::FETCH_BY_HAS_REVIEWER   => null,
            Review::FETCH_BY_IDS            => $query->get('ids'),
            Review::FETCH_BY_KEYWORDS       => $query->get('keywords'),
            Review::FETCH_BY_PARTICIPANTS   => $query->get('participants'),
            Review::FETCH_BY_PROJECT        => $query->get('project'),
            Review::FETCH_BY_STATE          => $query->get('state'),
            Review::FETCH_BY_TEST_STATUS    => null,
            Review::FETCH_BY_GROUP          => $query->get('group'),
        );

        if ($query->offsetExists('hasReviewers')) {
            $options[Review::FETCH_BY_HAS_REVIEWER] = $boolean->filter($query->get('hasReviewers')) ? '1' : '0';
        }

        if ($query->offsetExists('passesTests')) {
            $options[Review::FETCH_BY_TEST_STATUS] = $boolean->filter($query->get('passesTests')) ? 'pass' : 'fail';
        }

        // eliminate blank values to avoid potential side effects
        return array_filter(
            $options,
            function ($value) {
                return is_array($value) ? count($value) : strlen($value);
            }
        );
    }

    /**
     * Forward the add review action to the given Swarm host
     *
     * @param   string  $url    the url of the Swarm host to forward to
     * @return  JsonModel       a JSON model instance with the response data
     */
    protected function forwardAdd($url)
    {
        $services = $this->getServiceLocator();
        $identity = $services->get('auth')->getIdentity() + array('id' => null, 'ticket' => null);
        $url      = trim($url, '/') . '/review/add';
        $client   = new HttpClient;

        $client->setUri($url)
               ->setMethod(HttpRequest::METHOD_POST)
               ->setAuth($identity['id'], $identity['ticket'])
               ->getRequest()
               ->setPost($this->getRequest()->getPost())
               ->setQuery($this->getRequest()->getQuery());

        // set the http client options; including any special overrides for our host
        $options = $services->get('config') + array('http_client_options' => array());
        $options = (array) $options['http_client_options'];
        if (isset($options['hosts'][$client->getUri()->getHost()])) {
            $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
        }
        unset($options['hosts']);
        $client->setOptions($options);

        // return the remote response as a new JSON model
        $response = $client->dispatch($client->getRequest());
        $this->getResponse()->setStatusCode($response->getStatusCode());
        return new JsonModel(json_decode($response->getBody(), true));
    }
}
