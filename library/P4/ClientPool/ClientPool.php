<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\ClientPool;

use P4\ClientPool\Exception;
use P4\Model\Connected\ConnectedAbstract;
use P4\Spec\Depot;
use P4\Uuid\Uuid;

/**
 * Manages a pool of client workspaces.
 */
class ClientPool extends ConnectedAbstract
{
    const MANAGEMENT_LOCK   = 'manage';
    const LOCK_EXTENSION    = '.lock';
    const SPIN_DELAY        = 50000;        // delay between locking attempts (50ms)

    protected $root         = null;
    protected $prefix       = null;
    protected $max          = 10;

    protected $handles      = array();
    protected $clients      = array();

    /**
     * Set the maximum number of clients to provision.
     *
     * Setting to 0 or null will leave it unlimited.
     *
     * @param   int|null        $max    the maximum number of clients to provision
     * @return  ClientPool      to maintain a fluent interface
     */
    public function setMax($max)
    {
        $this->max = $max;
        return $this;
    }

    /**
     * Retrieves the max client limit.
     *
     * @return int|null     the max number of clients 0/null for unlimited
     */
    public function getMax()
    {
        return $this->max;
    }

    /**
     * Specify the prefix used for client ids.
     *
     * @param   string|null $prefix     the prefix to apply to client ids (e.g. 'swarm-')
     * @return  ClientPool  to maintain a fluent interface
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Retreives the prefix used for client ids.
     *
     * @return  string   the prefix to apply to client ids (e.g. 'swarm-')
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Specify the root folder to store client workspaces and locks under.
     * Each generated client will store its data in a sub-folder named for
     * the client id. Also, client locks (<client-id>.lock) and the management
     * lock will be stored in this folder.
     *
     * @param   string|null     $root   the root folder to use for client workspaces
     * @return  ClientPool      to maintain a fluent interface
     */
    public function setRoot($root)
    {
        $this->root = $root !== null ? rtrim($root, '/') : null;
        return $this;
    }

    /**
     * The root folder for client workspaces. See setRoot for details.
     *
     * @return  string|null     the root folder to use for client workspaces
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * Will lock a client workspace for this thread to use and return the id.
     *
     * By default, the thread will just be given the existing locked client if one
     * is already being held. Getting multiple clients in a single client can be
     * achieved via the $reuse flag.
     *
     * It isn't guaranteed the returned client will have an up to date view map.
     * If you plan to sync files its advised you run 'reset' against the client first.
     *
     * @param   bool        $reuse      optional - if a client has already been requested by
     *                                  this thread its returned again if reuse is true (default).
     *                                  specifying reuse false will get an additional client.
     * @param   bool        $blocking   optional - by default, we will wait indefinitely to get a
     *                                  client. if false is passed we'll return false if we cannot
     *                                  get/add a client on our first attempt.
     * @return  bool|string             the client id to use or false if we were non-blocking and
     *                                  a client couldn't be locked/added on our first attempt.
     * @throws  Exception               if the root hasn't been set or file permissions prevent opening locks
     */
    public function grab($reuse = true, $blocking = true)
    {
        if (!$this->root) {
            throw new Exception(
                'No root has been set, unable to get client.'
            );
        }

        // if our root isn't already present, attempt to create it
        if (!is_dir($this->root)) {
            mkdir($this->root, 0700, true);
        }

        // if re-use is allowed and we have client(s) already simply return the first one
        if ($this->handles && $reuse) {
            reset($this->handles);
            return key($this->handles);
        }

        // we'll keep looping until we can successfully get a client
        // (unless non-blocking in which case we try once and give up).
        // first, we try to get a lock on an existing client; if that
        // fails we'll take a management lock and add a new client if
        // the server isn't already at 'max' clients.
        $p4 = $this->getConnection();
        while (1) {
            // retrieve the known locks, barring the management lock.
            $locks = glob($this->root . '/' . $this->prefix . '*' . static::LOCK_EXTENSION);
            $locks = array_map('basename', $locks);
            $locks = array_diff($locks, array(static::MANAGEMENT_LOCK . static::LOCK_EXTENSION));

            // attempt to get a lock on any existing client
            foreach ($locks as $lock) {
                $file = @fopen($this->root . '/' . $lock, 'c');

                if ($file === false) {
                    throw new Exception(
                        'Unable to open client pool lock, this likely indicates file permission problems'
                    );
                }

                // if we can get the lock, extract client id, record handle and return!
                if (flock($file, LOCK_EX | LOCK_NB)) {
                    $id = basename($lock, static::LOCK_EXTENSION);

                    // don't let handle fall out of scope or the lock will release.
                    $this->handles[$id] = $file;

                    // push connection's old client onto the clients
                    // array and set the grabbed client on it.
                    $this->clients += array(spl_object_hash($p4) => array());
                    $this->clients[spl_object_hash($p4)][] = array(
                        'old' => $p4->getClient(),
                        'new' => $id
                    );
                    $p4->setClient($id);

                    // if the client doesn't exist call reset to create it
                    $info = $p4->getInfo();
                    if ($info['clientName'] == '*unknown*') {
                        $this->reset();
                    }

                    return $id;
                }

                // didn't get a lock; close file handle
                fclose($file);
            }

            // if we aren't already maxed out on workspaces try and add one
            if (!$this->max || count($locks) < $this->max) {
                // if we are able to add a client, we continue which will loop
                // us around and attempt to lock our new client without delay.
                if ($this->provision()) {
                    continue;
                }
            }

            // if we were told to not be blocking; time to bail
            if (!$blocking) {
                return false;
            }

            // looks like we can't add another workspace and they are all taken.
            // sleep a bit and start all over.
            usleep(static::SPIN_DELAY);
        }
    }

    /**
     * Release previously locked client(s). It is recommended you release your
     * client as soon as you are done with it.
     *
     * @return  ClientPool      to maintain a fluent interface
     */
    public function release()
    {
        // if we have an entry for this object with this current client value
        // at the top of the stack set back the original client id.
        $client = null;
        $p4     = $this->getConnection();
        $hash   = spl_object_hash($p4);
        $client = $p4->getClient();
        if (isset($this->clients[$hash]) && $this->clients[$hash]) {
            $historic = array_pop($this->clients[$hash]);
            if ($p4->getClient() == $historic['new']) {
                $p4->setClient($historic['old']);
            }
        }

        // deal with closing handle(s)
        foreach ($this->handles as $key => $handle) {
            if ($client && $client != $key) {
                continue;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
            unset($this->handles[$key]);
        }

        return $this;
    }

    /**
     * Resets the passed client identifier to the correct view, root and host.
     * If the specified client doesn't already exist it will be created.
     *
     * @param   bool                $clearFiles     optional - if true, default, will attempt to revert and remove
     *                                              files. if false, only the client settings are reset.
     * @param   string|null|bool    $stream         optional - a specific stream to point the client at
     *                                              if null/false (default) the client will map all depots.
     * @param   bool                $mapAllDepots   optional - include all mappable depots (excludes archive and unload)
    *                                               by default this is false and only local and stream depots are mapped
     * @return  ClientPool          to maintain a fluent interface
     */
    public function reset($clearFiles = true, $stream = null, $mapAllDepots = false)
    {
        $p4     = $this->getConnection();
        $client = $p4->getClient();
        $root   = $this->root . '/' . $client;
        $view   = array();

        // generate a view which includes all depots if $mapAllDepots is true, otherwise only local and stream depots
        if (!$stream) {
            foreach (Depot::fetchAll(null, $p4) as $depot) {
                $type = $depot->get('Type');

                // exclude 'archive' and 'unload' depots as these cannot be mapped in client view
                if ($type === 'archive' || $type === 'unload') {
                    continue;
                }

                if ($mapAllDepots || $type == 'local' || $type == 'stream') {
                    $view[] = '"//' . $depot->getId() . '/..." "//' . $client . '/' . $depot->getId() . '/..."';
                }
            }
        }

        // force the client to have current/correct settings
        $data = $p4->run('client', array('-o', $client))->expandSequences()->getData(-1);
        $p4->run(
            'client',
            '-i',
            array(
                'Host'   => '',
                'Root'   => $root,
                'View'   => $view,
                'Stream' => $stream
            ) + $data
        );

        // ensure the root folder and lock file exist
        is_dir($root) ?: mkdir($root);

        // clear files if needed and return
        return $clearFiles ? $this->clearFiles($p4) : $this;
    }

    /**
     * Revert and remove any file in this client.
     *
     * @return  ClientPool          to maintain a fluent interface
     */
    public function clearFiles()
    {
        // revert and flush (sync none without removing local files) the client
        $p4 = $this->getConnection();
        $p4->run('revert', array('-k', '//...'));
        $p4->run('flush', '//...#none');

        // remove the contents of the client
        $this->removeDirectory($this->root . '/' . $p4->getClient(), true, false);

        return $this;
    }

    /**
     * Takes a management lock and defines a new client id if the server isn't
     * already at 'max' clients.
     *
     * Note, the client won't actually exist in perforce until someone calls
     * 'reset' on it. This process will occur automatically the first time
     * the new client is 'grabbed'.
     *
     * @return  bool        true if client was provisioned, false otherwise
     * @throws  \Exception  if errors occur running revert/flush during hard reset
     * @throws  Exception   if management lock file cannot be opened (most likely due to file permissions)
     */
    protected function provision()
    {
        $file = @fopen($this->root . '/' . static::MANAGEMENT_LOCK . static::LOCK_EXTENSION, 'c');

        // if we lacked rights to open the file, bail
        if ($file === false) {
            throw new Exception(
                'Unable to open client pool management lock, this likely indicates file permission problems'
            );
        }

        $lock = flock($file, LOCK_EX | LOCK_NB);

        // if another process is already managing; can't provision bail out
        if (!$lock) {
            flock($file, LOCK_UN);
            fclose($file);
            return false;
        }

        // get the workspace list now that we have a lock to take an accurate counts
        $locks = glob($this->root . '/' . $this->prefix . '*' . static::LOCK_EXTENSION);
        unset($locks[array_search($this->root . '/' . static::MANAGEMENT_LOCK . static::LOCK_EXTENSION, $locks)]);

        // if we now appear to be at/above max just release lock and return failure
        if ($this->max && count($locks) >= $this->max) {
            flock($file, LOCK_UN);
            fclose($file);
            usleep(5000);
            return false;
        }

        // generate a new client identifier and touch the lock to define it
        $id = $this->prefix . new Uuid;
        touch($this->root . '/' . $id . static::LOCK_EXTENSION);

        // release our management lock and return success
        flock($file, LOCK_UN);
        fclose($file);
        return true;
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
            // if directory is a symbolic link, there is no point of trying to delete files inside
            // try to delete the link if $removeRoot is true, otherwise leave it
            if (is_link($directory)) {
                return $removeRoot && @unlink($directory);
            }

            $files = new \RecursiveDirectoryIterator($directory);
            foreach ($files as $file) {
                if ($files->isDot()) {
                    continue;
                }
                if ($file->isFile()) {
                    if (!$file->isLink()) {
                        chmod($file->getPathname(), 0777);
                    }
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
