<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Record\File;

use P4\Connection\Exception\CommandException;
use P4\File\File;
use P4\Model\Connected\ConnectedAbstract;

/**
 * Simplified handler for reading and writing files to a special depot storage location.
 */
class FileService extends ConnectedAbstract
{
    protected $config;

    /**
     * Retrieve contents from a file in the depot
     *
     * @param  string   $filespec   file location (either absolute depot path or relative to base_path)
     * @return string               the contents of the file
     */
    public function read($filespec)
    {
        return $this->getFile($filespec)->getDepotContents();
    }

    /**
     * Stream the contents to STDOUT
     *
     * @param   string  $filespec   file location (either absolute depot path or relative to base_path)
     * @return  File                a file instance
     */
    public function stream($filespec)
    {
        return $this->getFile($filespec)->streamDepotContents();
    }

    /**
     * Manipulate a file in the depot using an anonymous function. This is used for writing from strings and
     * local files, and also for deleting from the depot.
     *
     * Example invocation:
     *
     *      $this->manipulateFile(
     *          $filespec,
     *          function ($file) use ($filespec) {
     *              $file->delete();
     *              return "Deleted: " . $filespec;
     *          }
     *      );
     *
     * The returned string is used for the submit message when the changes are applied.
     *
     * @param string    $filespec   full or partial p4 filespec (partial filespecs will be absolutized)
     * @param \Closure  $callback   anonymous function that accepts a $file parameter and performs some action on it.
     *                              must return a string to use as a submit message.
     */
    protected function manipulateFile($filespec, \Closure $callback)
    {
        $p4   = $this->getConnection();
        $pool = $p4->getService('clients');
        $pool->grab();

        try {
            $pool->reset(true);

            $file = new File($p4);
            $file->setFilespec($this->absolutize($filespec));

            $message = $callback($file);

            $file->submit($message);
        } catch (\Exception $e) {
        }

        try {
            $pool->clearFiles();
        } catch (\Exception $clearFilesException) {
        }

        $pool->release();

        // exceptions in the callback take priority over clearFiles exceptions
        if (isset($e)) {
            throw $e;
        }

        if (isset($clearFilesException)) {
            throw $clearFilesException;
        }
    }

    /**
     * Write raw data to a file in the Depot
     *
     * @param   string  $filespec   file location (either absolute depot path or relative to base_path)
     * @param   string  $data       the data to be written
     */
    public function write($filespec, $data)
    {
        return $this->manipulateFile(
            $filespec,
            function ($file) use ($data, $filespec) {
                $file->setLocalContents($data);
                $file->add();

                return "Added: " . $filespec;
            }
        );
    }

    /**
     * Copies the specified file to a local client workspace, then writes it to the depot.
     *
     * @param   string  $filespec       file location (either absolute depot path or relative to base_path)
     * @param   string  $location       the location of the file on the local filesystem
     * @param   bool    $move           optional - move the file from $location to the active client (default: false)
     */
    public function writeFromFile($filespec, $location, $move = false)
    {
        return $this->manipulateFile(
            $filespec,
            function ($file) use ($filespec, $location, $move) {
                $localFilename = $file->getLocalFilename();
                $file->createLocalPath();

                if ($move && !@rename($location, $localFilename)) {
                    throw new \RuntimeException("Unable to move file: " . $location);
                }

                if (!$move && !@copy($location, $localFilename)) {
                    throw new \RuntimeException("Unable to copy file: " . $location);
                }

                // @todo make this work for files that already exist at $filespec
                $file->add();

                return "Added: " . $filespec;
            }
        );
    }

    /**
     * Delete a file in the Depot
     *
     * @param   string  $filespec   file location (either absolute depot path or relative to base_path)
     */
    public function delete($filespec)
    {
        return $this->manipulateFile(
            $filespec,
            function ($file) use ($filespec) {
                $file->delete();
                return "Deleted: " . $filespec;
            }
        );
    }

    /**
     * Get an instance of File from the Depot
     *
     * @param   string  $filespec   file location (either absolute depot path or relative to base_path)
     * @return  File                a file instance
     * @throws  P4\File\Exception\NotFoundException;
     */
    public function getFile($filespec)
    {
        $path = $this->absolutize($filespec);
        return File::fetch($path, $this->getConnection(), true);
    }

    /**
     * Take a filespec and resolve it to a absolute location in the depot.
     * If the filespec is already absolute, it will be returned as-is.
     *
     * @param   string  $filespec       file location (either absolute depot path or relative to base_path)
     * @return  string                  the full path to the depot location of the file
     */
    public function absolutize($filespec)
    {
        if (is_null($filespec) || $filespec === '' || !strlen(trim($filespec, '/'))) {
            throw new \InvalidArgumentException('FileService::absolutize($filespec) requires a non-empty filespec');
        }

        if (substr($filespec, 0, 2) == '//') {
            $path = $filespec;
        } else {
            $path = $this->getBasePath() . '/' . $filespec;
        }

        return $path;
    }

    /**
     * Configure the file service.
     *
     * Expects an array:
     *
     *   array(
     *     'base_path' => '//.swarm'
     *   )
     *
     * @param $config   array containing 'base_path' key for storage location
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return  array   the file service configuration array
     */
    public function getConfig()
    {
        return (array) $this->config + array('base_path' => null);
    }

    /**
     * Get the base location for writing files to.
     *
     * @return string   the base depot path (defaults to "//.swarm" if not set)
     */
    public function getBasePath()
    {
        if (!isset($this->config['base_path']) || strlen(trim($this->config['base_path'], '/')) == 0) {
            throw new \Exception('Administrator must set $config[\'depot_storage\'][\'base_path\']');
        }

        return rtrim($this->config['base_path'], '/');
    }

    /**
     * Check if given $path is writable
     *
     * @param   string  $path       the path to check for writability
     * @return  bool                whether $path is writable or not
     */
    public function isWritable($path)
    {
        try {
            $result = $this->getConnection()->run("protects", array("-m", $this->absolutize($path)));
        } catch (CommandException $e) {
            if (strpos($e->getMessage(), 'must refer to client')) {
                return false;
            }

            if (strpos($e->getMessage(), 'Protections table is empty')) {
                return true;
            }

            throw $e;
        }

        return in_array($result->getData(0, "permMax"), array('write', 'super', 'admin'));
    }
}
