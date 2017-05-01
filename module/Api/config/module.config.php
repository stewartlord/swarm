<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'router' => array(
        'routes' => array(
            'api' => array(
                'type' => 'literal',
                'options' => array(
                    'route' => '/api',
                ),
                'may_terminate' => false,
                'child_routes' => array(
                    'version' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/[:version/]version[/]',
                            'constraints' => array('version' => 'v(2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Index',
                                'action'     => 'version'
                            ),
                        ),
                    ),
                    'activity' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => array(
                            'route' => '/:version/activity[/]',
                            'constraints' => array('version' => 'v(2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Activity',
                            ),
                        ),
                    ),
                    'projects' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => array(
                            'route' => '/:version/projects[/:id][/]',
                            'constraints' => array('version' => 'v(2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Projects',
                            ),
                        ),
                    ),
                    'groups' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/groups[/:id][/]',
                            'constraints' => array('version' => 'v2'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Groups',
                            ),
                        ),
                    ),
                    'reviews' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews[/:id][/]',
                            'constraints' => array('version' => 'v(2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                            ),
                        ),
                    ),
                    'reviews/changes' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/:id/changes[/]',
                            'constraints' => array('version' => 'v(2|1(\.[1-2])?)'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'addChange',
                            ),
                        ),
                    ),
                    'reviews/state' => array(
                        'type' => 'Zend\Mvc\Router\Http\Segment',
                        'options' => array(
                            'route' => '/:version/reviews/:id/state[/]',
                            'constraints' => array('version' => 'v2'),
                            'defaults' => array(
                                'controller' => 'Api\Controller\Reviews',
                                'action'     => 'state',
                            ),
                        ),
                    ),
                    'notfound' => array(
                        'type' => 'Zend\Mvc\Router\Http\Regex',
                        'priority' => -100,
                        'options' => array(
                            'regex' => '/(?P<path>.*)|$',
                            'spec'  => '/%path%',
                            'defaults' => array(
                                'controller' => 'Api\Controller\Index',
                                'action'     => 'notFound',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Api\Controller\Activity' => 'Api\Controller\ActivityController',
            'Api\Controller\Index'    => 'Api\Controller\IndexController',
            'Api\Controller\Projects' => 'Api\Controller\ProjectsController',
            'Api\Controller\Reviews'  => 'Api\Controller\ReviewsController',
            'Api\Controller\Groups'   => 'Api\Controller\GroupsController',
        ),
    ),
);
