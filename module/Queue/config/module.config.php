<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'queue'  => array(
        'workers'               => 3,
        'worker_lifetime'       => 595,     // 10 minutes (less 5s)
        'worker_task_timeout'   => 1800,    // 30 minutes (max execution time per task)
        'worker_memory_limit'   => '1G'
    ),
    'router' => array(
        'routes' => array(
            'worker' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/queue/worker[/]',
                    'defaults' => array(
                        'controller' => 'Queue\Controller\Index',
                        'action'     => 'worker',
                    ),
                ),
            ),
            'status' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/queue/status[/]',
                    'defaults' => array(
                        'controller' => 'Queue\Controller\Index',
                        'action'     => 'status',
                    ),
                ),
            ),
        ),
    ),
    'xhprof' => array(
        'ignored_routes' => array('worker')
    ),
    'security' => array(
        'login_exempt' => array('worker')
    ),
    'controllers' => array(
        'invokables' => array(
            'Queue\Controller\Index' => 'Queue\Controller\IndexController'
        ),
    ),
    'service_manager' => array(
        'factories' => array(
            'queue' => function ($services) {
                $config = $services->get('config');
                return new Queue\Manager(
                    isset($config['queue']) ? $config['queue'] : null
                );
            },
        ),
    ),
);
