<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'short_links' => array(
        'hostname' => null, // a dedicated host for short links - defaults to standard host
    ),
    'router' => array(
        'routes' => array(
            'short-link' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/l[/:link][/]',
                    'defaults' => array(
                        'controller' => 'ShortLinks\Controller\Index',
                        'action'     => 'index',
                        'link'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'ShortLinks\Controller\Index' => 'ShortLinks\Controller\IndexController'
        ),
    ),
);
