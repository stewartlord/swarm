<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files;

use Application\Permissions\Protections;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\CommandException;
use P4\File\File;
use P4\OutputHandler\Limit;
use P4\Spec\Client;
use Zend\Filter\Compress;
use Zend\Filter\Compress\CompressionAlgorithmInterface;
use Zend\Json\Json;

class Archiver
{
    protected $adapter        = null;
    protected $options        = array();
    protected $filesInfoCache = array();
    protected $protections    = null;
    protected $archiveClient  = null;

    /**
     * Set protections for this instance. These protections (if present) will be applied when
     * calculating files info and when creating archives (i.e. files lacking read access will
     * be filtered out).
     *
     * @param   Protections|null    $protections    optional - protections to apply when creating
     *                                              archives and/or calculating files info
     * @return  Archiver            provides fluent interface
     */
    public function setProtections(Protections $protections = null)
    {
        $this->protections = $protections;
        return $this;
    }

    /**
     * Set options for the compressor.
     *
     * @param   array       $options    list of options for the compressor
     * @return  Archiver    provides fluent interface
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Returns one or all options set on this instance.
     *
     * @param   string      $option     optional - option to return
     * @return  mixed
     */
    public function getOptions($option = null)
    {
        if ($option === null) {
            return $this->options;
        }

        return array_key_exists($option, $this->options)
            ? $this->options[$option]
            : null;
    }

    /**
     * Set adapter for the compressor.
     *
     * @param   string      $adapter    compressor adapter
     * @return  Archiver    provides fluent interface
     */
    public function setAdapter($adapter)
    {
        $this->adapter = $adapter;
        return $this;
    }

    /**
     * Return true if this archiver can create archives.
     *
     * @return  boolean     true if this archiver can create archives, false otherwise
     */
    public function canArchive()
    {
        try {
            return $this->getCompressor()->getAdapter()->hasZip();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the digest, total size and count of files under the given filespec.
     * Files info is cached in memory (to avoid running the 'p4 sizes' command multiple
     * times as we anticipate the info might be needed in several places).
     *
     * @param   string              $filespec   filespec to get digest/size/count for
     * @param   Connection          $p4         Perforce connection
     * @return  array               list with properties of files under the given $filespec
     *                               digest - unique hash of all files in the filespec
     *                               size   - total size of all files under the filespec (in bytes)
     *                               count  - number of files matching the filespec
     * @throws  \InvalidArgumentException   if given $filespec contains no files
     */
    public function getFilesInfo($filespec, Connection $p4)
    {
        // remember the original client
        $client = $p4->getClient();

        try {
            $archiveClient = $this->grabArchiveClient($p4);
            $filespec      = (string) $filespec;
            $cacheKey      = md5(
                serialize(
                    array(
                        $filespec,
                        $p4->getUser(),
                        $p4->getPort(),
                        $archiveClient->getView()
                    )
                )
            );

            // if we have a cache hit, relase client and exit
            if (isset($this->filesInfoCache[$cacheKey])) {
                $this->releaseArchiveClient($p4);
                return $this->filesInfoCache[$cacheKey];
            }

            $hash    = hash_init('md5');
            $size    = 0;
            $count   = 0;
            $handler = new Limit;
            $handler->setOutputCallback(
                function ($data, $type) use ($hash, &$size, &$count) {
                    hash_update($hash, serialize($data));
                    $size += (int) $data['fileSize'];
                    $count++;

                    return Limit::HANDLER_HANDLED;
                }
            );

            // we add the filespec to the hash because different filespecs can have the same
            // set of files, but produce zip files that unpack to different folders
            hash_update($hash, $filespec);

            // if $filespec refers to the original client, replace the client name with name of the archive client
            if ($client) {
                $filespec = preg_replace('#^//' . $client . '/#', '//' . $archiveClient->getId() . '/', $filespec);
            }

            // convert $filespec to client syntax as 'p4 sizes' doesn't apply view mapping restrictions
            // if $filespec is in depot syntax
            // 'p4 where' expects plain file, so we temporarily strip revspec and then add it back
            $clientFilespec  = $p4->run('where', array(File::stripRevspec($filespec)))->getData(0, 'clientFile');
            $clientFilespec .= File::extractRevspec($filespec);

            // run 'sizes' command via our output handler, it will update the files digest
            // iteratively via the active hashing context
            $p4->runHandler($handler, 'sizes', array($clientFilespec));
        } catch (\Exception $e) {
            // temporarily ignore the exception, we will handle it later after we release the client
        }

        $this->releaseArchiveClient($p4);

        // if badness occurred or we didn't detect any files, handle it now after we released the client
        if (isset($e) && strpos($e->getMessage(), '- must refer to client') === false) {
            throw $e;
        }
        if (isset($e) || !$count) {
            throw new \RuntimeException("Filespec '$filespec' contains no files.");
        }

        $this->filesInfoCache[$cacheKey] = array(
            'digest' => hash_final($hash),
            'size'   => $size,
            'count'  => $count
        );

        return $this->filesInfoCache[$cacheKey];
    }

    /**
     * Archive files under the given filespec.
     *
     * @param   string                      $filespec       Perforce specification of file(s) to archive
     * @param   string                      $targetFile     archive filename
     * @param   string                      $statusFile     filename to hold archive status, errors, success
     * @param   Connection                  $p4             connection of the user attempting to archive
     * @return  Archiver                    provide fluent interface
     * @throws  \InvalidArgumentException   if target and/or status file are not set
     * @throws  \RuntimeException           if we cannot open/lock the archive lock file or if the archive
     *                                      was created under unexpected location and we can't move it into
     *                                      the requested $targetFile
     */
    public function archive($filespec, $targetFile, $statusFile, Connection $p4)
    {
        $filespec   = (string) $filespec;
        $compressor = $this->getCompressor();
        $filesInfo  = $this->getFilesInfo($filespec, $p4);
        $totalCount = $filesInfo['count'];
        $client     = $p4->getClient();

        if (!strlen($targetFile) || !strlen($statusFile)) {
            throw new \InvalidArgumentException("Both target and status files must be set.");
        }

        // get exclusive lock for the target (if the archive process is in flight, execution of
        // this code will be blocked until the lock is released)
        $lockHandle = @fopen($targetFile . '.lock', 'c');
        if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
            throw new \RuntimeException("Cannot open and/or lock file for the archive '$targetFile'.");
        }

        try {
            // now we have the exclusive lock, check the status - if it indicates that the archive has
            // already been successfully built, just return it, otherwise we will build a new one
            $status = $this->hasStatus($statusFile)
                ? $this->getStatus($statusFile)
                : null;

            if ($status && $status['success'] && file_exists($targetFile)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                return $targetFile;
            }

            // no cached copy available, time to build the archive
            $this->writeStatus($statusFile, array('phase' => 'setup'));

            // grab archive client and set it on the $p4 connection
            $archiveClient = $this->grabArchiveClient($p4);

            // if $filespec refers to a client, replace the client name with name of the archive client
            if ($client) {
                $filespec = preg_replace('#^//' . $client . '/#', '//' . $archiveClient->getId() . '/', $filespec);
            }

            // sync files to archive via output handler so we can track the progress
            $archiver = $this;
            $count    = 0;
            $handler  = new Limit;
            $handler->setOutputCallback(
                function ($data, $type) use ($archiver, $statusFile, $totalCount, &$count) {
                    // update status once every 10 files
                    if (++$count % 10 === 0) {
                        $archiver->writeStatus(
                            $statusFile,
                            array(
                                'phase'    => 'sync',
                                'progress' => min(50, $count / $totalCount * 50)  // 0-50% of overall progress
                            )
                        );
                    }

                    return Limit::HANDLER_HANDLED;
                }
            );

            $this->writeStatus($statusFile, array('phase' => 'sync'));
            try {
                $p4->runHandler($handler, 'sync', array('-p', $filespec));
            } catch (CommandException $e) {
                if (strpos($e->getMessage(), 'pending changelist') !== false) {
                    unset($e);
                    $change = preg_replace('/[^0-9]*/', '', File::extractRevspec($filespec));
                    $p4->runHandler($handler, 'unshelve', array('-s', $change));
                } else {
                    throw $e;
                }
            }

            // get path to filespec in local filesystem (remove trailing dots if present)
            $path = $p4->run('where', array(File::stripRevspec($filespec)))->getData(0, 'path');
            $path = File::stripWildcards($path);

            // compress files in $path (use output callback to track the progress if available)
            $count   = 0;
            $adapter = $compressor->getAdapter();
            $adapter->setArchive($targetFile);
            if (method_exists($adapter, 'setOutputCallback')) {
                $adapter->setOutputCallback(
                    function ($line) use ($archiver, $statusFile, $totalCount, &$count) {
                        // update status once every 10 files
                        if (++$count % 10 === 0) {
                            $archiver->writeStatus(
                                $statusFile,
                                array(
                                    'phase'    => 'compress',
                                    'progress' => min(50, $count / $totalCount * 50) + 50 // 50-100% of overall progress
                                )
                            );
                        }
                    }
                );
            }

            $this->writeStatus($statusFile, array('phase' => 'compress', 'progress' => 50));
            $archiveFile = $compressor->filter($path);

            // if the compressor created archive under a different file than requested, try to move it
            // into the requested location
            if ($archiveFile !== $targetFile && !@rename($archiveFile, $targetFile)) {
                // making it here means that archive was created under unexpected path and we can't move it
                // into the expected location; attempt to remove the archive and throw an exception
                unlink($archiveFile);
                throw new \RuntimeException("Cannot create archive under requested '$targetFile' filename.");
            }
        } catch (\Exception $e) {
        }

        // clear files that we have synced (as they can potentially take a lot of space), release
        // the client and unlock the lock file for the archive
        $this->releaseArchiveClient($p4, true);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        // if badnesses occurred re-throw now that we have released our client/archive locks
        if (isset($e)) {
            $this->writeStatus($statusFile, array('error' => $e->getMessage()));
            throw $e;
        }

        // all done, update status
        $this->writeStatus($statusFile, array('success' => true, 'progress' => 100));
        return $this;
    }

    /**
     * Removes the specified archive, lock and status files. Lock and status files are removed
     * after obtaining exclusive locks to them (in this order). This method will throw an
     * exception if any of the archive, lock and/or status files exist but cannot be removed.
     *
     * @param   string  $archiveFile    archive file to remove
     * @param   string  $statusFile     status file to remove
     * @throws  RuntimeException        if archive/lock/status file exists but cannot be removed
     */
    public function removeArchive($archiveFile, $statusFile)
    {
        $lockFile = $archiveFile . '.lock';

        // remember what files to remove exist
        $archiveLockExists = file_exists($lockFile);
        $archiveExists     = file_exists($archiveFile);
        $statusExists      = file_exists($statusFile);

        // try to open and lock the archive-lock/status files
        $archiveLockHandle = $archiveLockExists ? fopen($lockFile,   'r')           : false;
        $archiveLock       = $archiveLockHandle ? flock($archiveLockHandle, LOCK_EX) : false;
        $statusHandle      = $statusExists      ? fopen($statusFile, 'r')           : false;
        $statusLock        = $statusHandle      ? flock($statusHandle,      LOCK_EX) : false;

        // attempt to remove the archive file if we were able to lock the archive-lock file
        // attempt to remove the archive-lock/status files if we were able to lock them
        $archiveDeleted     = $archiveLock ? unlink($archiveFile) : false;
        $archiveLockDeleted = $archiveLock ? unlink($lockFile)    : false;
        $statusDeleted      = $statusLock  ? unlink($statusFile)  : false;

        // release locks and handles to status/archive-lock files (in this order)
        $statusLock        && flock($statusHandle, LOCK_UN);
        $statusHandle      && fclose($statusHandle);
        $archiveLock       && flock($archiveLockHandle, LOCK_UN);
        $archiveLockHandle && fclose($archiveLockHandle);

        // throw an exception listing all files we failed to remove (if any)
        $undeletedFiles = array_filter(
            array(
                $archiveExists     && !$archiveDeleted     ? $archiveFile : null,
                $archiveLockExists && !$archiveLockDeleted ? $lockFile    : null,
                $statusExists      && !$statusDeleted      ? $statusFile  : null
            )
        );
        if ($undeletedFiles) {
            throw new \RuntimeException(
                "Unable to remove the following files: '" . implode("', '", $undeletedFiles). "'. Check permissions."
            );
        }
    }

    /**
     * Return true if we can get status from the given status file, false otherwise.
     *
     * @param   string      $filename   status filename
     * @return  boolean     true if status from a given file can be read, false otherwise
     */
    public function hasStatus($filename)
    {
        try {
            $this->getStatus($filename);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Return normalized status read from the given status file.
     * Status is returned only if the status file exists and we can
     * get a shared lock to it, otherwise throws an exception.
     *
     * @param   string              $filename   status filename
     * @return  array|false         status or false if status file doesn't
     *                              exist or cannot be locked for reading
     * @throws  \RuntimeException   if status cannot be read from the status file
     */
    public function getStatus($filename)
    {
        $handle = @fopen($filename, 'r');
        if ($handle && flock($handle, LOCK_SH)) {
            // clear file status cache to prevent returning cached filesize value on the next line
            clearstatcache();
            $status = fread($handle, filesize($filename));
            flock($handle, LOCK_UN);
            fclose($handle);

            return $this->normalizeStatus(Json::decode($status, true));
        }

        throw new \RuntimeException("Cannot read status from the file '$filename'.");
    }

    /**
     * Write status to the status file. Status file will be exclusively locked
     * when writing status.
     *
     * @param   string              $filename   status filename to write status at
     * @param   array               $status     status to write
     * @throws  \RuntimeException   if status cannot be written into the status file
     */
    public function writeStatus($filename, array $status)
    {
        $handle = @fopen($filename, 'c');
        if ($handle && flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fwrite($handle, Json::encode($this->normalizeStatus($status)));
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        throw new \RuntimeException("Cannot write status to the file '$filename'.");
    }

    /**
     * Helper method to get normalized status from the given array.
     * Returning array is guaranteed to have following keys:
     *  phase    - a label for the current phase of archiving
     *  error    - status error message, null by default
     *  success  - flag indicating if the archive was successfully created
     *  progress - overall progress in %
     *
     * @param   array   $status     status to normalize
     * @return  array   normalized $status
     */
    protected function normalizeStatus(array $status)
    {
        return $status + array(
            'phase'    => null,
            'error'    => null,
            'success'  => false,
            'progress' => 0
        );
    }

    /**
     * Get the compressor instance.
     *
     * @return  Compress            compressor instance
     * @throws  \RuntimeException   if compressor cannot be created or if compressor's
     *                              adapter doesn't implement 'setArchive' method as we
     *                              rely on it when compressing files
     */
    protected function getCompressor()
    {
        $adapter = $this->adapter;

        // throw if we don't have adapter
        if ($adapter === null) {
            throw new \RuntimeException("No compress adapter is set.");
        }

        $compressor = new Compress(
            array(
                'adapter' => $adapter,
                'options' => $this->options
            )
        );

        // we rely on the compressor's adapter having the setArchive() method when
        // compressing files, so we throw exception here if that method is not available
        if (!method_exists($compressor->getAdapter(), 'setArchive')) {
            throw new \RuntimeException("Adapter must implement 'setArchive' method.");
        }

        return $compressor;
    }


    /**
     * Grabs client for the given connection and tweaks its view to include mappings
     * from the protections. If the passed connection already has a client, its view
     * mapping will be preserved.
     *
     * @param   Connection  $p4     connection to grab client for
     * @return  Client      archive client we grabbed and set on $p4
     */
    protected function grabArchiveClient(Connection $p4)
    {
        // remember the original client
        $client = $p4->getClient();

        // grab a new client from pool
        // we remember the grabbed client id so it can be properly released later via releaseArchiveClient() method
        $clients             = $p4->getService('clients');
        $this->archiveClient = $clients->grab();

        // reset client (include all depots in view mapping)
        $clients->reset(true, null, true);

        // if the connection had a client, clone its view and set in on our grabbed client
        // since these two clients have different names, we touch up the cloned view to
        // update its 'client' half with the proper client name
        $archiveClient = Client::fetch($this->archiveClient);
        if ($client) {
            $view = Client::fetch($client)->getView();
            $archiveClient->setView($view)->touchUpView();
        }

        // update client view to include mappings from the protections (if set)
        $view = $this->protections
            ? $this->protections->limitView($archiveClient->getView())
            : $archiveClient->getView();

        // if merging protections resulted in an empty view, it means user has no access
        // to any of the files, bail
        if (!$view) {
            throw new \RuntimeException("No access to files.");
        }

        // set a flag to leave all files writeable on the client (we don't want files in
        // the archive to be read-only)
        $archiveClient->setOptions(
            array_merge(array_diff($archiveClient->getOptions(), array('noallwrite')), array('allwrite'))
        );

        // save the view and return
        $archiveClient->setView($view)->save();
        return $archiveClient;
    }

    /**
     * Release and optionaly reset files on archive client previously grabbed and set on $p4.
     *
     * @param   Connection  $p4             connection to release client on
     * @param   boolean     $resetFiles     optional - if true, then client files will also be cleared
     *                                      false by default
     */
    protected function releaseArchiveClient(Connection $p4, $clearFiles = false)
    {
        // if we haven't previously grabbed archive client or $p4 has a different one, bail
        if (!$this->archiveClient || $this->archiveClient !== $p4->getClient()) {
            return;
        }

        $clients = $p4->getService('clients');
        if ($clearFiles) {
            $clients->clearFiles();
        }
        $clients->release();

        $this->archiveClient = null;
    }
}
