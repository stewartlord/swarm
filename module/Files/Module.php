<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files;

use Files\MimeType;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Add a basic preview handler for primitive (web-safe) types.
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');
        $url         = $services->get('viewhelpermanager')->get('url');
        $events      = $services->get('queue')->getEventManager();

        // attach to archive cleanup event
        $events->attach(
            'task.cleanup.archive',
            function ($event) use ($services) {
                $archiveFile = $event->getParam('id');
                $data        = $event->getParam('data');
                $statusFile  = isset($data['statusFile']) ? $data['statusFile'] : null;

                try {
                    $result = $services->get('archiver')->removeArchive($archiveFile, $statusFile);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            }
        );

        $formats->addHandler(
            new Format\Handler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) use ($url) {
                    $isWebSafeImage = MimeType::isWebSafeImage($mimeType);
                    if ($request
                        && $request->getUri()->getPath() == $url('diff')
                        && $file->isText()
                        && !$isWebSafeImage
                    ) {
                        return false;
                    }
                    return $file->isText() || strpos($mimeType, '/pdf') || $isWebSafeImage;
                },
                // render-preview callback
                function ($file, $extension, $mimeType, $request) use ($services) {
                    $helpers   = $services->get('ViewHelperManager');
                    $url       = $helpers->get('url');
                    $escapeUrl = $helpers->get('escapeUrl');
                    $viewUrl   = $url('view', array('path' => trim($file->getDepotFilename(), '/')))
                        . '?v=' . $escapeUrl($file->getRevspec());

                    if (strpos($mimeType, '/pdf')) {
                        return '<div class="view view-pdf img-polaroid">'
                            .  '<object width="100%" height="100%" type="application/pdf" data="' . $viewUrl . '">'
                            .  '<p>It appears you don\'t have a pdf plugin for this browser.</p>'
                            .  '</object>'
                            . '</div>';
                    }

                    if (MimeType::isWebSafeImage($mimeType)) {
                        return '<div class="view view-image img-polaroid pull-left">'
                             .  '<img src="' . $viewUrl . '">'
                             . '</div>';
                    }

                    // making it this far means that the file must be text
                    $fileSize   = $helpers->get('fileSize');
                    $escapeHtml = $helpers->get('escapeHtml');
                    $isPlain    = $extension === 'txt' || !strlen($extension);
                    $maxSize    = 1048576; // 1MB
                    $contents   = $file->getDepotContents(
                        array(
                            $file::UTF8_CONVERT  => true,
                            $file::UTF8_SANITIZE => true,
                            $file::MAX_FILESIZE  => $maxSize
                        ),
                        $cropped
                    );

                    return '<pre class="view view-text prettyprint linenums '
                         .  ($isPlain ? 'nocode' : 'lang-' . $extension)
                         . '">'
                         .  $escapeHtml($contents)
                         .  ($cropped ? '<span class="snip">Snip (&gt;' . $fileSize($maxSize) . ')</span>' : '')
                         . '</pre>';
                }
            ),
            'default'
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
