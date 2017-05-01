<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\Controller;

use Groups\Model\Group;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Spec\Exception\NotFoundException;
use Projects\Model\Project;
use Users\Authentication;
use Users\Model\User;
use Zend\Authentication\Result;
use Zend\InputFilter\InputFilter;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        // not much to do, let the template handle it.
    }

    public function userAction()
    {
        $services    = $this->getServiceLocator();
        $p4Admin     = $services->get('p4_admin');
        $user        = $this->getEvent()->getRouteMatch()->getParam('user');
        $currentUser = $services->get('user');

        try {
            $user = User::fetch($user, $p4Admin);
        } catch (NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // turn our exception into a more appropriate 404 if
        // we cannot locate the requested user
        if (!$user instanceof User) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $config   = $user->getConfig();
        $projects = Project::fetchAll(array(Project::FETCH_BY_MEMBER => $user->getId()), $p4Admin);

        return new ViewModel(
            array(
                'user'          => $user,
                'config'        => $config,
                'projects'      => $projects,
                'following'     => $config->getFollows('user'),
                'followers'     => $config->getFollowers(),
                'userFollows'   => $config->isFollower($currentUser->getId()),
                'isCurrentUser' => $currentUser->getId() == $user->getId()
            )
        );
    }

    public function usersAction()
    {
        $services  = $this->getServiceLocator();
        $p4Admin   = $services->get('p4_admin');
        $group     = $this->getRequest()->getQuery('group');
        $fields    = $this->getRequest()->getQuery('fields');

        $services->get('permissions')->enforce('authenticated');

        // if requested, get only users for a specified group
        $groupUsers = $group ? Group::fetchAllMembers($group, null, null, null, $p4Admin) : null;

        $utf8    = new Utf8Filter;
        $users   = array();
        $options = $group ? array(User::FETCH_BY_NAME => $groupUsers) : null;
        foreach (User::fetchAll($options, $p4Admin) as $user) {
            // though unexpected, some fields (User or FullName) can include invalid UTF-8 sequences
            // so we filter them, otherwise json encoding could crash with an error
            $data   = array();
            $fields = $fields ? (array) $fields : $user->getFields();
            foreach ($fields as $field) {
                $data[$field] = $utf8->filter($user->get($field));
            }
            $users[] = $data;
        }

        return new JsonModel($users);
    }

    public function loginAction()
    {
        $request  = $this->getRequest();
        $services = $this->getServiceLocator();
        $session  = $services->get('session');

        if ($request->isPost()) {
            $config     = $services->get('config') + array('session' => array());
            $p4Admin    = $services->get('p4_admin');
            $auth       = $services->get('auth');
            $translator = $services->get('translator');
            $user       = $request->getPost('user');
            $password   = $request->getPost('password');
            $remember   = $request->getPost('remember');

            // prime validity and default error message.
            // message will only be used if isValid stays false.
            $isValid    = false;
            $error      = $translator->t('Invalid username or password.');

            // clear any/all existing session data on login
            // note we need to explicitly restart the session (it's closed by default)
            $session->start();
            $session->getStorage()->clear();

            // normalize the passed user-id into an array of zero
            // or more 'candidate' accounts. if we are passed
            // an email (anything with an @) find all matching
            // accounts. otherwise, simply fetch the passed id.
            $candidates = array();
            if (strpos($user, '@')) {
                foreach (User::fetchAll(null, $p4Admin) as $candidate) {
                    if ($candidate->getEmail() === $user) {
                        $candidates[] = $candidate->getId();
                    }
                }
            } else {
                $candidates[] = $user;
            }

            // strip out any 'prevent_login' users, we don't want to allow them
            $blocked = isset($config['security']['prevent_login'])
                ? (array) $config['security']['prevent_login']
                : array();
            $candidates = array_diff($candidates, $blocked);

            // loop through all login candidates, stop on first success
            foreach ($candidates as $user) {
                $adapter = new Authentication\Adapter($user, $password, $p4Admin);

                try {
                    // break if we hit a working candidate
                    if ($auth->authenticate($adapter)->getCode() === Result::SUCCESS) {
                        $isValid  = true;
                        $authUser = $user;
                        break;
                    }
                } catch (\Exception $e) {
                    // we skip any failed accounts; better luck next try :)
                }
            }

            // include the logged in version of layout/toolbar in the response
            $toolbar = null;
            if ($isValid) {
                // the remember setting may have changed; ensure the session cookie
                // is set for the appropriate lifetime before we regenerate its id
                $config['session'] += array('remembered_cookie_lifetime' => null, 'cookie_lifetime' => null);
                $session->getConfig()->setStorageOption(
                    'cookie_lifetime',
                    $config['session'][$remember ? 'remembered_cookie_lifetime' : 'cookie_lifetime']
                );

                // regenerate our id since they logged in; this avoids session fixation and also
                // allows any lifetime changes to take affect.
                // note, as the session was already started there's a Set-Cookie entry for it
                // and regenerating would normally add a second. to avoid two entries (harmless
                // but off-putting) we first clear all Set-Cookie headers.
                header_remove('Set-Cookie');
                session_regenerate_id(true);

                // lastly, for cookies, set/clear the remember cookie as needed
                $strict  = isset($config['security']['https_strict']) && $config['security']['https_strict'];
                $request = $services->get('request');
                $https   = $request instanceof \Zend\Http\Request && $request->getUri()->getScheme() == 'https';
                if ($remember) {
                    // note, this cookie sticks around for a year. we don't use the session lifetime
                    // here as you want the user id to fill in when the session expires (if remember
                    // me was checked). if we shared lifetimes with the session, the user id would
                    // never be auto-filled/remembered when you actually needed it.
                    $expires = time() + 365*24*60*60;
                    headers_sent() ?: setcookie('remember', $user, $expires, '/', '', $strict || $https, true);
                } elseif (isset($_COOKIE['remember'])) {
                    headers_sent() ?: setcookie('remember', null,  -1,       '/', '', $strict || $https, true);
                }

                $renderer    = $services->get('viewrenderer');
                $toolbarView = new ViewModel;
                $toolbarView->setTemplate('layout/toolbar');
                $toolbar     = $renderer->render($toolbarView);

                // get authenticated user object and invalidate user cache if
                // authenticated user is not in cache - this most likely means
                // that user has been added to Perforce but the form-commit
                // user trigger was not fired
                if (!User::exists($authUser, $p4Admin)) {
                    try {
                        $p4Admin->getService('cache')->invalidateItem('users');
                    } catch (ServiceNotFoundException $e) {
                        // no cache? nothing to invalidate
                    }
                }
                $authUser = User::fetch($authUser, $p4Admin);
            }

            $avatar = $services->get('viewhelpermanager')->get('avatar');

            // figure out the json model before we close up the session as
            // getting the CSRF token would otherwise re-open/close it.
            $json = new JsonModel(
                array(
                    'isValid'   => $isValid,
                    'error'     => !$isValid ?  $error : null,
                    'toolbar'   => $toolbar  ?: null,
                    'info'      => $isValid  ?  $adapter->getUserP4()->getInfo() : null,
                    'csrf'      => $isValid  ?  $services->get('csrf')->getToken() : null,
                    'user'      => $isValid  ?  array(
                        'id'                => $authUser->getId(),
                        'name'              => $authUser->getFullName(),
                        'email'             => $authUser->getEmail(),
                        'avatar'            => $avatar($authUser, 64),
                        'isAdmin'           => $adapter->getUserP4()->isAdminUser(true),
                        'addProjectAllowed' => $services->get('permissions')->is('projectAddAllowed')
                    ) : null
                )
            );

            // done modifying the session now (remember we explicitly open/close it)
            $session->writeClose();

            return $json;
        }

        // prepare view for login form
        $user    = isset($_COOKIE['remember']) ? $_COOKIE['remember'] : '';
        $partial = $request->getQuery('format') === 'partial';
        $view    = new ViewModel(
            array(
                 'partial'    => $partial,
                 'user'       => $user,
                 'remember'   => strlen($user) != 0,
                 'statusCode' => $this->getResponse()->getStatusCode()
            )
        );
        $view->setTerminal($partial);

        return $view;
    }

    public function logoutAction()
    {
        $services = $this->getServiceLocator();
        $session  = $services->get('session');
        $auth     = $services->get('auth');

        // clear identity and all other session data on logout
        // note we need to explicitly restart the session (it's closed by default)
        $session->start();
        $auth->clearIdentity();
        $session->destroy(array('send_expire_cookie' => true, 'clear_storage' => true));
        $session->writeClose();

        // if a referrer is set and it appears to point at us; i want to go to there
        $request  = $this->getRequest();
        $referrer = $request->getServer('HTTP_REFERER');
        $host     = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        if ($referrer && stripos($referrer, $host) === 0) {
            return $this->redirect()->toUrl($referrer);
        }

        // no referrer or it wasn't us, simply go home
        return $this->redirect()->toRoute('home');
    }

    public function followAction($unfollow = false)
    {
        // only allow logged in users to follow/unfollow
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');

        $p4Admin  = $services->get('p4_admin');
        $user     = $services->get('user');
        $user->setConnection($p4Admin);

        // validate the type and id of the thing to follow
        $type     = $this->getEvent()->getRouteMatch()->getParam('type');
        $id       = $this->getEvent()->getRouteMatch()->getParam('id');
        $filter   = $this->getFollowFilter($p4Admin);
        $isValid  = $filter->setData(array('type' => $type, 'id' => $id))->isValid();

        // if this is not a post, indicate if the current user is
        // following the specified thing (type/id) or not.
        if (!$this->getRequest()->isPost()) {
            $config = $user->getConfig();
            return new JsonModel(array('isFollowing' => $config->isFollowing($id, $type)));
        }

        // add follow entry and save user's config if valid
        if ($isValid) {
            $config = $user->getConfig();
            if ($unfollow) {
                $config->removeFollow($id, $type)->save();
            } else {
                $config->addFollow($id, $type)->save();
            }
        }

        return new JsonModel(
            array(
                'isValid'  => $isValid,
                'messages' => $filter->getMessages()
            )
        );
    }

    public function unfollowAction()
    {
        // follow will enforce permissions
        return $this->followAction(true);
    }

    /**
     * Get filter for follow input data.
     *
     * @return  InputFilter     filter for new following record
     */
    protected function getFollowFilter(Connection $p4)
    {
        $filter = new InputFilter;

        // declare type field
        $filter->add(
            array(
                'name'          => 'type',
                'required'      => true,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                if (!in_array($value, array('user', 'project'))) {
                                    return "Follow type ('$value') must be either 'user' or 'project'.";
                                }
                                return true;
                            }
                        )
                    )
                )
            )
        );

        // declare user/project id field
        $filter->add(
            array(
                'name'          => 'id',
                'required'      => true,
                'validators'    => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) use ($filter, $p4) {
                                $type = $filter->getValue('type');
                                if ($type == 'user' && !User::exists($value, $p4)) {
                                    return "User ('$value') does not exist.";
                                }
                                if ($type == 'project' && !Project::exists($value, $p4)) {
                                    return "Project ('$value') does not exist.";
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
}
