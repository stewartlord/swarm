<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'avatars' => array(
        'http_url'  => 'http://www.gravatar.com/avatar/{hash}?s={size}&d={default}',
        'https_url' => 'https://secure.gravatar.com/avatar/{hash}?s={size}&d={default}'
    ),
    'security' => array(
        'login_exempt'  => array('login'),  // specify route id's which bypass require_login setting
        'prevent_login' => array(),         // specify user ids which are not permitted to login to swarm
    ),
    'service_manager' => array(
        'factories' => array(
            'auth'      => function ($services) {
                // always use basic-auth credentials if they are specified
                // note: credentials, both basic and session, are not validated here, only retrieved
                $storage = new \Users\Authentication\Storage\BasicAuth($services->get('request'));
                $storage = $storage->read()
                    ? $storage
                    : new \Users\Authentication\Storage\Session(null, null, $services->get('session'));

                return new \Zend\Authentication\AuthenticationService($storage);
            },
            'user'      => function ($services) {
                $auth     = $services->get('auth');
                $p4Admin  = $services->get('p4_admin');
                $identity = (array) $auth->getIdentity() + array('id' => null);

                // if the user exists; return the full object
                if (Users\Model\User::exists($identity['id'], $p4Admin)) {
                    return Users\Model\User::fetch($identity['id'], $p4Admin);
                }

                // user didn't exist; return an empty model (will have a null id)
                return new Users\Model\User($p4Admin);
            }
        ),
    ),
    'router' => array(
        'routes' => array(
            'home' => array(
                'type' => 'Zend\Mvc\Router\Http\Literal',
                'options' => array(
                    'route'    => '/',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'index',
                    ),
                ),
            ),
            'login' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/login[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'login',
                    ),
                ),
            ),
            'logout' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/logout[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'logout',
                    ),
                ),
            ),
            'user' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/user[s]/:user[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'user'
                    ),
                ),
            ),
            'users' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/users[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'users'
                    ),
                ),
            ),
            'follow' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/follow/:type/:id[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'follow',
                        'type'       => null,
                        'id'         => null
                    ),
                ),
            ),
            'unfollow' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/unfollow/:type/:id[/]',
                    'defaults' => array(
                        'controller' => 'Users\Controller\Index',
                        'action'     => 'unfollow',
                        'type'       => null,
                        'id'         => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Users\Controller\Index' => 'Users\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_map' => array(
            'users/index/index'  => __DIR__ . '/../view/users/index/index.phtml',
            'users/index/user'   => __DIR__ . '/../view/users/index/user.phtml',
        ),
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'user'      => 'Users\View\Helper\User',
            'userLink'  => 'Users\View\Helper\UserLink',
            'avatar'    => 'Users\View\Helper\Avatar',
            'avatars'   => 'Users\View\Helper\Avatars'
        ),
    ),
);
