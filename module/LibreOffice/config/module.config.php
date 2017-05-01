<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'libreoffice' => array(
        'path' => 'soffice'
    ),
    'xhprof' => array(
        'ignored_routes' => array('libreoffice')
    ),
    'router' => array(
        'routes' => array(
            'libreoffice' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/libreoffice?(/(?P<path>.*))?',
                    'spec'     => '/libreoffice/%path%',
                    'defaults' => array(
                        'controller' => 'LibreOffice\Controller\Index',
                        'action'     => 'index',
                        'path'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'LibreOffice\Controller\Index' => 'LibreOffice\Controller\IndexController'
        ),
    ),
);
