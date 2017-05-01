<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Record\Lock;

use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Log\Logger;

class Lock
{
    const KEY_PREFIX        = 'swarm-lock-';

    protected $name         = null;
    protected $connection   = null;

    /**
     * Instantiate the lock, set the lock name and the connection to use.
     *
     * @param   string                  $name           a conceptual identifier to coordinate locking
     *                                                  has no significance outside of the lock
     * @param   ConnectionInterface     $connection     a connection to use for this instance
     */
    public function __construct($name, ConnectionInterface $connection)
    {
        $this->name       = $name;
        $this->connection = $connection;
    }

    /**
     * Get the full name of the key that we use for this lock.
     *
     * @return  string  name for the key used by this instance
     */
    public function getKeyName()
    {
        return static::KEY_PREFIX . strtoupper(bin2hex($this->name));
    }

    /**
     * Try to take out a lock.
     *
     * A lock is acquired by incrementing a 'p4 key' corresponding to the $name of this lock.
     * If the value is 1, we have the lock. If the value is > 1, someone already has the lock.
     * If someone else has the lock, wait until it is released. If the lock is not released
     * within $maxWait, then assume it is stale and take it anyway.
     *
     * Locks are released by deleting the 'p4 key'.
     *
     * @param   int     $maxWait    optional - number of seconds to wait before assuming lock is stale
     */
    public function lock($maxWait = 30)
    {
        for ($i = 0; $i < (int) $maxWait; $i++) {
            if ($this->getLock()) {
                break;
            }
            sleep(1);
        }

        return $this;
    }

    /**
     * Release the lock by removing the 'p4 key'.
     *
     * @return  Lock    provides fluent interface
     */
    public function unlock()
    {
        try {
            $this->connection->run(
                'counter',
                array('-u', '-d', $this->getKeyName())
            );
        } catch (CommandException $e) {
            // if key doesn't exist, log a warning, otherwise rethrow
            if (stripos($e->getMessage(), 'no such counter') !== false) {
                Logger::log(
                    Logger::WARN,
                    "Tried to unlock '" . $this->name . "' when not locked. " . $e->getMessage()
                );
            } else {
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Attempt to acquire a lock by incrementing the associate key.
     * If the resulting value is 1, we got the lock, otherwise we failed.
     *
     * @return  bool    true if lock was acquired, false otherwise
     */
    protected function getLock()
    {
        $result = $this->connection->run(
            'counter',
            array('-u', '-i', $this->getKeyName())
        )->getData(0);

        return (int) $result['value'] === 1;
    }
}
