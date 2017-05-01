<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace ThreeJS;

use Files\Format\Handler as FormatHandler;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Add a preview handler for types supported by ThreeJS
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');

        $formats->addHandler(
            new FormatHandler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) {
                    return in_array($extension, array('dae', 'stl', 'obj'));
                },
                // render-preview callback
                function ($file, $extension, $mimeType) use ($services) {
                    $helpers        = $services->get('ViewHelperManager');
                    $escapeUrl      = $helpers->get('escapeUrl');
                    $escapeHtmlAttr = $helpers->get('escapeHtmlAttr');
                    $url            = $helpers->get('url');
                    $viewUrl        = $url('view', array('path' => trim($file->getDepotFilename(), '/')))
                                    . '?v=' . $escapeUrl('@' . $file->get('headChange'));
                    return '<div class="view img-polaroid threejs" data-url="'
                        .  $viewUrl . '" data-ext="' . $escapeHtmlAttr($extension) . '">'
                        .  '</div>'
                        .  '<script>swarm.threejs.start();</script>';
                }
            ),
            'threejs'
        );
    }
}
