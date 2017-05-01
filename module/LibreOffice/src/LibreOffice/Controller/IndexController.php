<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace LibreOffice\Controller;

use P4\File\File;
use P4\File\Exception\NotFoundException as FileNotFoundException;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getServiceLocator();
        $config   = $services->get('config');
        $p4       = $services->get('p4');
        $route    = $this->getEvent()->getRouteMatch();
        $path     = trim($route->getParam('path'), '/');
        $request  = $this->getRequest();
        $version  = $request->getQuery('v');
        $version  = ctype_digit($version) ? '#' . $version : $version;

        try {
            $file = File::fetch('//' . $path . $version, $p4);
        } catch (FileNotFoundException $e) {
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
            $pdfFile = $cacheFile;
        } else {
            // write depot file to a temp file so we can convert it with LibreOffice
            try {
                $tempFile = tempnam($cacheDir, 'libreoffice');
                $p4->run('print', array('-o', $tempFile, $file->getFilespec()));

                // to avoid deadlocking and failures from concurrent libreoffice processes,
                // isolate each invocation under a unique 'UserInstallation' folder
                $userDir = $cacheDir . '/user-' . getmypid();
                mkdir($userDir, 0700, true);

                // attempt to convert the file
                $soffice = $config['libreoffice']['path'];
                $command = escapeshellarg($soffice)
                         . ' ' . escapeshellarg('-env:UserInstallation=file://' . $userDir)
                         . ' --headless --invisible --norestore --nologo --nolockcheck --nodefault'
                         . ' --convert-to pdf --outdir '
                         . escapeshellarg($cacheDir) . ' '
                         . escapeshellarg($tempFile);

                // HOME shedanigans
                // Because LibreOffice writes its temp file under the home directory,
                // (as well as other cruft), we want to set HOME to the user directory.
                // Before we do, save it, and then set it back after the call.
                $oldHome = getenv('HOME');
                putenv('HOME=' . $userDir);
                exec($command, $output, $result);
                putenv('HOME=' . $oldHome);

                // clean up user installation folder (otherwise they would accumulate)
                $this->removeDirectory($userDir);

                // check for failure (non-zero exit or non-existent file)
                if ($result || !is_file($tempFile . '.pdf')) {
                    throw new \Exception(
                        "Failed to convert '" . $file->getBasename() . "' to pdf. " .
                        "Exit status: " . $result . ". Output: " . implode(" ", $output)
                    );
                }

                rename($tempFile . '.pdf', $cacheFile);
                $pdfFile = $cacheFile;
                unlink($tempFile);
            } catch (\Exception $e) {
                unlink($tempFile);
                throw $e;
            }
        }

        header('Content-Type: application/pdf');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($pdfFile));
        header('Content-Disposition: filename="' . pathinfo($path, PATHINFO_FILENAME) . '.pdf"');

        // flush output unless we are testing
        if (!$request->isTest) {
            ob_clean();
            flush();
        }

        readfile($pdfFile);

        // exit unless we are in the testing environment - in this case just return
        if ($request->isTest) {
            return $this->response;
        }
        exit;
    }

    /**
     * Get the path to write converted files to. Ensure directory is writable.
     *
     * @return  string  the cache directory to write to
     * @throws  \RuntimeException   if the directory cannot be created or made writable
     */
    protected function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/libreoffice';
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

    /**
     * Recursively remove a directory and all of it's file contents.
     *
     * @param  string   $directory   The directory to remove.
     * @param  bool     $recursive   when true, recursively delete directories.
     * @param  bool     $removeRoot  when true, remove the root (passed) directory too
     */
    protected function removeDirectory($directory, $recursive = true, $removeRoot = true)
    {
        if (is_dir($directory)) {
            $files = new \RecursiveDirectoryIterator($directory);
            foreach ($files as $file) {
                if ($files->isDot()) {
                    continue;
                }
                if ($file->isFile()) {
                    chmod($file->getPathname(), 0777);
                    @unlink($file->getPathname());
                } elseif ($file->isDir() && $recursive) {
                    $this->removeDirectory($file->getPathname(), true, true);
                }
            }

            if ($removeRoot) {
                chmod($directory, 0777);
                @rmdir($directory);
            }
        }
    }
}
