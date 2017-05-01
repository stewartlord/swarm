<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'activity' => array(
        'ignored_users' => array('git-fusion-user')
    ),
    'router' => array(
        'routes' => array(
            'activity' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/activity[/]',
                    'defaults' => array(
                        'controller' => 'Activity\Controller\Index',
                        'action'     => 'index',
                    ),
                ),
            ),
            'activity-rss' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/activity/rss[/]',
                    'defaults' => array(
                        'controller' => 'Activity\Controller\Index',
                        'action'     => 'index',
                        'rss'        => true
                    ),
                ),
            ),
            'activity-stream' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/activity/streams/:stream',
                    'defaults' => array(
                        'controller' => 'Activity\Controller\Index',
                        'action'     => 'index'
                    ),
                ),
            ),
            'activity-stream-rss' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/activity/streams/:stream/rss[/]',
                    'defaults' => array(
                        'controller' => 'Activity\Controller\Index',
                        'action'     => 'index',
                        'rss'        => true
                    ),
                ),
            ),
            'add-activity' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/activity/add[/]',
                    'defaults' => array(
                        'controller' => 'Activity\Controller\Index',
                        'action'     => 'add',
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Activity\Controller\Index' => 'Activity\Controller\IndexController'
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'activity'  => 'Activity\View\Helper\Activity'
        ),
    ),
);
