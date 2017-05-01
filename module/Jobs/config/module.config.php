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
            'job' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/jobs?(/(?P<job>.*))?',
                    'spec'     => '/jobs/%job%',
                    'defaults' => array(
                        'controller' => 'Jobs\Controller\Index',
                        'action'     => 'job',
                        'job'        => null
                    ),
                ),
            ),
            'jobs' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/jobs?(/(?P<job>.*))?',
                    'spec'     => '/jobs/%job%',
                    'defaults' => array(
                        'controller' => 'Jobs\Controller\Index',
                        'action'     => 'job',
                        'job'        => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Jobs\Controller\Index' => 'Jobs\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_map' => array(
            'jobs/index/index'  => __DIR__ . '/../view/jobs/index/job.phtml',
        ),
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
);
