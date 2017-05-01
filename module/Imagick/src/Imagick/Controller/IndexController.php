<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Imagick\Controller;

use P4\File\File;
use P4\File\Exception\NotFoundException;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getServiceLocator();
        $p4       = $services->get('p4');
        $route    = $this->getEvent()->getRouteMatch();
        $path     = trim($route->getParam('path'), '/');
        $request  = $this->getRequest();
        $version  = $request->getQuery('v');
        $version  = ctype_digit($version) ? '#' . $version : $version;

        try {
            $file = File::fetch('//' . $path . $version, $p4);
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // to prevent data leaks, we must verify that the file is not a symbolic link
        if ($file->isSymlink()) {
            $this->response->setStatusCode(Response::STATUS_CODE_415);
            return $this->response;
        }

        // if we have previously converted this file, use cached copy
        // otherwise, attempt to convert the file and write to cache
        $cacheDir  = $this->getCacheDir();
        $cacheFile = $cacheDir . '/' . md5($path . $version . $file->getStatus('headTime'));
        if (is_readable($cacheFile)) {
            $imageFile = $cacheFile;
        } else {
            // write depot file to a temp file so we can load it into imagick
            try {
                $tempFile = tempnam($cacheDir, 'imagick');
                $p4->run('print', array('-o', $tempFile, $file->getFilespec()));

                // attempt to decode the file, if it fails try again with an explicit type
                try {
                    $image = new \Imagick($tempFile);
                } catch (\ImagickException $e) {
                    $image = new \Imagick(strtoupper($file->getExtension()) . ':' . $tempFile);
                }
                $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $image->setImageFormat('png');
                $image->writeimage($cacheFile);

                $imageFile = $cacheFile;
                unlink($tempFile);
            } catch (\Exception $e) {
                unlink($tempFile);
                throw $e;
            }
        }

        header('Content-Type: image/png');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($imageFile));
        header('Content-Disposition: filename="' . pathinfo($path, PATHINFO_FILENAME) . '.png"');

        // flush output unless we are testing
        if (!$request->isTest) {
            ob_clean();
            flush();
        }

        readfile($imageFile);

        // exit unless we are in the testing environment - in this case just return
        if ($request->isTest) {
            return $this->response;
        }
        exit;
    }

    /**
     * Get the path to write converted images to. Ensure directory is writable.
     *
     * @return  string  the cache directory to write to
     * @throws  \RuntimeException   if the directory cannot be created or made writable
     */
    protected function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/imagick';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }

        return $dir;
    }
}
