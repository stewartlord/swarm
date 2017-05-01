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
            'imagick' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/imagick?(/(?P<path>.*))?',
                    'spec'     => '/imagick/%path%',
                    'defaults' => array(
                        'controller' => 'Imagick\Controller\Index',
                        'action'     => 'index',
                        'path'       => null
                    ),
                ),
            ),
        ),
    ),
    'xhprof' => array(
        'ignored_routes' => array('imagick')
    ),
    'controllers' => array(
        'invokables' => array(
            'Imagick\Controller\Index' => 'Imagick\Controller\IndexController'
        ),
    ),
);
