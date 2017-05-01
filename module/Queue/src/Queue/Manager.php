<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Queue;

use P4\Uuid\Uuid;
use Zend\EventManager\EventManager;

/**
 * A basic queue manager.
 *
 * There is a very simple script under the public folder (queue.php) that adds
 * tasks to the queue. The queue is just a directory of files where each file
 * represents a task. The files are named using microtime so that they list in
 * the order they are added. The contents of the file are the details of the
 * task. We expect task data to take the form of 'type,id' (e.g. change,54321).
 *
 * Workers process tasks in the queue. They are invoked via the worker action
 * of the index controller. It is expected that you will setup a cronjob to
 * kick off a worker periodically (e.g. every minute). A limited number of
 * workers can run at a time (3 by default). When a worker starts it tries to
 * grab a slot - each slot is a lock file. If no slots are open, the worker
 * shuts down. The cron and the slots together ensure that we are always trying
 * to process tasks in the queue, but we don't exceed the max worker setting.
 */
class Manager
{
    const   DEFAULT_WORKERS         = 3;
    const   DEFAULT_LIFETIME        = 600;  // 10 minutes
    const   DEFAULT_TASK_TIMEOUT    = 1800; // 30 minutes
    const   DEFAULT_MEMORY_LIMIT    = '1G';

    protected $config               = null;
    protected $handles              = array();
    protected $events               = null;

    public function __construct(array $config = null)
    {
        $this->config = $config + array(
            'path'                  => DATA_PATH . '/queue',
            'workers'               => static::DEFAULT_WORKERS,
            'worker_lifetime'       => static::DEFAULT_LIFETIME,
            'worker_task_timeout'   => static::DEFAULT_TASK_TIMEOUT,
            'worker_memory_limit'   => static::DEFAULT_MEMORY_LIMIT
        );
    }

    /**
     * Get the queue config.
     *
     * @return  array   normalized queue config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Attempt to get a worker slot. We limit the active workers via flock.
     *
     * @return  int|false   the slot number or false if no open slots.
     */
    public function getWorkerSlot()
    {
        $config = $this->getConfig();
        $path   = $config['path'] . '/workers';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \Exception("Unable to create workers directory.");
        }
        for ($slot = 1; $slot <= (int) $config['workers']; $slot++) {
            $file = fopen($path . '/' . $slot, 'c');
            $lock = flock($file, LOCK_EX | LOCK_NB);
            if ($lock) {
                // don't let handle fall out of scope or the lock will release.
                $this->handles[$slot] = $file;
                return $slot;
            }
        }

        return false;
    }

    /**
     * Verifies we still have the specified worker slot.
     * If we never had the slot or have released it this returns false.
     * Further, if we had the slot but someone has deleted the worker lock this returns false.
     *
     * @param   int     $slot   the slot to test
     * @return  bool    true if we have the slot locked; false otherwise
     */
    public function hasWorkerSlot($slot)
    {
        // if we don't have a lock, we don't have the slot
        if (!ctype_digit((string) $slot) || !isset($this->handles[$slot]) || !is_resource($this->handles[$slot])) {
            return false;
        }

        // figure out the path to the specified lock
        $config = $this->getConfig();
        $path   = $config['path'] . '/workers';
        $file   = $path . '/' . $slot;

        // we don't want caching to burn us, clear the stat cache for this item
        clearstatcache(true, $file);

        // we think we have a lock so status is based on file existence
        return file_exists($file);
    }

    /**
     * Release the lock held on the given worker slot. By default, only releases
     * slots we hold. If force is specified even locks held by someone else will
     * be marked for release by deleting the file.
     *
     * @param   int     $slot   the slot number to release
     * @param   bool    $force  optional, if true release even if we don't hold the slot
     * @return  bool    true if we released the slot, false otherwise
     */
    public function releaseWorkerSlot($slot, $force = false)
    {
        if (!isset($this->handles[$slot]) || !is_resource($this->handles[$slot])) {
            // no lock and no force == no go
            if (!$force) {
                return false;
            }

            // figure out the path to the specified lock
            $config = $this->getConfig();
            $path   = $config['path'] . '/workers';
            $file   = fopen($path . '/' . $slot, 'c');

            // if the file opens ok but we cannot get a lock, release it by unlinking
            if ($file && !flock($file, LOCK_EX | LOCK_NB)) {
                unlink($file);
                return true;
            }

            // looks like the file didn't exist or wasn't locked, return failure
            return false;
        }

        // this is one of our locked slots, simply unlock it
        flock($this->handles[$slot], LOCK_UN);
        fclose($this->handles[$slot]);
        unset($this->handles[$slot]);

        return true;
    }

    /**
     * Grab a task from the queue. To avoid multiple workers processing the
     * same task, we lock it first, then read its data and remove it.
     *
     * @return  false|array     an array containing the task file (name), time, type, id and data
     *                          or false if there are no tasks to grab.
     */
    public function grabTask()
    {
        $config  = $this->getConfig();
        $entries = scandir($config['path']);
        foreach ($entries as $entry) {
            // only consider files that look right
            $entry = $config['path'] . '/' . $entry;
            if (!$this->isTaskFile($entry)) {
                continue;
            }

            // ignore 'future' tasks (time > now)
            if ($this->isFutureTask($entry)) {
                continue;
            }

            // is this one up for grabs - can we lock it?
            // even if we lock it, we need to double check it exists
            // otherwise it could be recently consumed by another worker.
            $file = @fopen($entry, 'r');
            $lock = $file && flock($file, LOCK_EX | LOCK_NB);
            clearstatcache(false, $entry);
            if ($lock && is_file($entry)) {
                // got it, consume and destroy!
                $task = $this->parseTaskFile($entry, $file);

                // don't process zombie tasks (ones we are unable to delete)
                // as we'd just spin processing them forever
                if (!unlink($entry)) {
                    throw new \RuntimeException(
                        "Non-deletable task encountered $file, please fix file permissions to continue task processing."
                    );
                }

                // release our lock and close the file handle
                flock($file, LOCK_UN);
                fclose($file);

                if ($task) {
                    return $task;
                }
            }
        }

        // no tasks for us.
        return false;
    }

    /**
     * Parse the contents of a task file.
     * We're expecting data in the form of: 'type,id[\n{JSON}]'
     *
     * @param   string          $file       the name of the task file
     * @param   resource        $handle     optional - a file handle to use.
     * @return  false|array     an array containing the task file (name), time, type, id and data
     *                          or false if the file could not be parsed.
     */
    public function parseTaskFile($file, $handle = null)
    {
        if (!$handle) {
            $handle = @fopen($file, 'r');
            if (!$handle) {
                return false;
            }
        }

        // first line is type,id, the rest is (optionally) json
        // we limit the first line to 1KB and the rest to 1MB.
        $info = fgets($handle, 1024);
        $json = fread($handle, 1024 * 1024);

        // skip if task file exceeded limits
        if (!feof($handle) || (strlen($json) && substr($info, -1) !== "\n")) {
            return false;
        }

        $info = explode(',', trim($info), 2);
        $json = json_decode(trim($json), true);
        $task = array(
            'file' => $file,
            'time' => (int) ltrim(substr(basename($file), 0, -7), 0),
            'type' => isset($info[0]) ? $info[0] : null,
            'id'   => isset($info[1]) ? $info[1] : null,
            'data' => (array) $json
        );

        // tasks must have at least a type
        if (!strlen($task['type'])) {
            return false;
        }

        return $task;
    }

    /**
     * Add a task to the queue.
     *
     * @param   string          $type   the type of task to process (e.g. 'change')
     * @param   string|int      $id     the relevant identifier (e.g. '12345')
     * @param   array|null      $data   optional - additional task details
     * @param   int|float|null  $time   influence name of queue file (defaults to microtime)
     *                                  future tasks (time > now) aren't grabbed until time <= now
     * @return  bool            true if queued successfully, false otherwise
     */
    public function addTask($type, $id, array $data = null, $time = null)
    {
        $time   = $time ?: microtime(true);
        $config = $this->getConfig();
        if (!is_dir($config['path'])) {
            mkdir($config['path'], 0700);
        }

        // 1000 attempts to get a unique filename.
        // @codingStandardsIgnoreStart
        $path = $config['path'] . '/' . sprintf('%015.4F', $time) . '.';
        for ($i = 0; $i < 1000 && !($file = @fopen($path . $i, 'x')); $i++);
        // @codingStandardsIgnoreEnd

        if ($file) {
            // contents take the form of type,id[\n{JSON}]
            fwrite($file, $type . "," . $id . ($data ? "\n" . json_encode($data) : ""));
            fclose($file);
            return true;
        }

        return false;
    }

    /**
     * Get a count of the active workers.
     *
     * @return  int     the number of active workers (locked slots)
     */
    public function getWorkerCount()
    {
        $workers = 0;
        $config  = $this->getConfig();
        $path    = $config['path'] . '/workers';
        $dir     = is_dir($path) ? opendir($path) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            // workers are purely numeric files
            $entry = $path . '/' . $entry;
            if (!preg_match('/\/[0-9]+$/', $entry) || !is_file($entry)) {
                continue;
            }

            // active workers lock their file.
            $file = fopen($entry, 'r');
            $lock = flock($file, LOCK_EX | LOCK_NB);
            if (!$lock) {
                $workers++;
            }
        }

        return $workers;
    }

    /**
     * Get a count of queued tasks.
     *
     * @return  int     the number of tasks in the queue.
     */
    public function getTaskCount()
    {
        $counts = $this->getTaskCounts();
        return $counts['total'];
    }

    /**
     * Get a count of queued tasks broken out into current, future and total.
     *
     * @return  array   task counts (current/future/total)
     */
    public function getTaskCounts($excludeFuture = false)
    {
        $counts = array('current' => 0, 'future' => 0, 'total' => 0);
        $config = $this->getConfig();
        $dir    = is_dir($config['path']) ? opendir($config['path']) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            if ($this->isTaskFile($config['path'] . '/' . $entry)) {
                $counts['current'] += $this->isFutureTask($entry) ? 0 : 1;
                $counts['future']  += $this->isFutureTask($entry) ? 1 : 0;
                $counts['total']++;
            }
        }

        return $counts;
    }

    /**
     * Get a list of queued task files.
     *
     * @return  array   list of task filenames (absolute paths).
     */
    public function getTaskFiles()
    {
        $tasks  = array();
        $config = $this->getConfig();
        $dir    = is_dir($config['path']) ? opendir($config['path']) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            $entry = $config['path'] . '/' . $entry;
            if ($this->isTaskFile($entry)) {
                $tasks[] = $entry;
            }
        }

        return $tasks;
    }

    /**
     * Get the event manager for triggering or attaching to events
     * such as task.change, worker.startup, etc.
     *
     * @return EventManager the event manager instance.
     */
    public function getEventManager()
    {
        $this->events = $this->events ?: new EventManager;

        return $this->events;
    }

    /**
     * Returns all queue tokens defined for this swarm install.
     * Normally we anticipate only having one token.
     *
     * If there are no existing tokens one be automatically added
     * and the value returned.
     *
     * @return  array   the queue token(s) defined for this instance
     */
    public function getTokens()
    {
        // ensure the token folder exists
        $config = $this->getConfig();
        $path   = $config['path'] . '/tokens';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        // get all files/tokens under the path
        $tokens = array();
        $handle = opendir($path);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open queue tokens path '$path'. Check file permissions.");
        }
        while (false !== ($entry = readdir($handle))) {
            if (is_file($path . '/' . $entry)) {
                $tokens[] = $entry;
            }
        }

        // if we couldn't find an existing token lets make one
        if (!$tokens) {
            $token    = strtoupper(new Uuid);
            touch($path . '/' . $token);
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Check if the given file name looks like a task file
     * (e.g. 1355957340.2225.1)
     *
     * @param   string  $file   the file to check
     * @return  bool    true if file looks like a task
     */
    protected function isTaskFile($file)
    {
        return (bool) preg_match('/\/[0-9]{10}\.[0-9]{4}\.[0-9]{0,4}$/', $file)
            && is_file($file);
    }

    /**
     * Check if the file name represents a future task
     *
     * @param   string  $file   the file to check
     * @return  bool    true if file's timestamp is in the future
     */
    protected function isFutureTask($file)
    {
        // we use microtime() instead of time() here because sometimes they disagree.
        $time = (int) ltrim(substr(basename($file), 0, -7), 0);
        return $time > (int) microtime(true);
    }
}
