<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\Model;

use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Cache\ArrayReader;
use Record\Cache\ArrayWriter;
use Record\Exception\NotFoundException as RecordNotFoundException;

class User extends \P4\Spec\User
{
    const   FETCH_NO_CACHE  = 'noCache';

    protected $config       = null;

    /**
     * Extends exists to use cache if available.
     *
     * @param   string|array    $id             the id to check for or an array of ids to filter.
     * @param   Connection      $connection     optional - a specific connection to use.
     * @return  bool            true if the given id matches an existing user.
     */
    public static function exists($id, Connection $connection = null)
    {
        // before we muck with things; capture if it's plural or singular mode
        $plural = is_array($id);

        // normalize the input to an array of valid ids
        $ids = array();
        foreach ((array) $id as $value) {
            if (static::isValidId($value)) {
                $ids[] = $value;
            }
        }

        try {
            $users           = static::getCachedUsers($connection);
            $connection      = $connection ?: static::getDefaultConnection();
            $isCaseSensitive = $connection->isCaseSensitive();

            $filtered = array();
            foreach ($ids as $id) {
                // if we are talking to a case-insensitive server, we should be case-insensitive too
                if ($isCaseSensitive ? isset($users[$id]) : $users->noCaseLookup($id) !== false) {
                    $filtered[] = $id;
                }
            }
            return $plural ? $filtered : count($filtered) != 0;

        } catch (ServiceNotFoundException $e) {
            $users = parent::fetchAll(
                array(
                    static::FETCH_BY_NAME => $ids,
                    static::FETCH_MAXIMUM => count($ids)
                ),
                $connection
            );

            return $plural ? $users->invoke('getId') : $users->count() != 0;
        }
    }

    /**
     * Extends fetch to use cache if available.
     *
     * @param   string          $id         the id of the entry to fetch.
     * @param   Connection      $connection optional - a specific connection to use.
     * @return  PluralAbstract  instance of the requested entry.
     * @throws  \InvalidArgumentException   if no id is given.
     */
    public static function fetch($id, Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();

        try {
            $users = static::getCachedUsers($connection);
        } catch (ServiceNotFoundException $e) {
            return parent::fetch($id, $connection);
        }

        // if we are talking to a case-insensitive server, we should be case-insensitive too
        $key = $connection->isCaseSensitive() ? $id : $users->noCaseLookup($id);

        // if we have a cached user, clone it, give it a connection and return
        if (isset($users[$key])) {
            $user = clone $users[$key];
            $user->setConnection($connection);
            return $user;
        }

        throw new SpecNotFoundException("Cannot fetch user $id. Record does not exist.");
    }

    /**
     * Extends fetchAll to use cache if available.
     *
     * @param   array       $options    optional - array of options to augment fetch behavior.
     *                                  supported options are:
     *
     *                                  FETCH_MAXIMUM - set to integer value to limit to the
     *                                                  first 'max' number of entries.
     *                                  FETCH_BY_NAME - set to user name pattern (e.g. 'jdo*'),
     *                                                  can be a single string or array of strings.
     *                                 FETCH_NO_CACHE - set to true to avoid using the cache.
     *
     * @param   Connection  $connection optional - a specific connection to use.
     * @return  FieldedIterator         all matching records of this type.
     */
    public static function fetchAll($options = array(), Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        $options    = (array) $options + array(
            static::FETCH_MAXIMUM   => null,
            static::FETCH_BY_NAME   => null,
            static::FETCH_NO_CACHE  => null
        );

        // optionally avoid the cache
        if ($options[static::FETCH_NO_CACHE]) {
            return parent::fetchAll($options, $connection);
        }

        try {
            $users = static::getCachedUsers($connection);
        } catch (ServiceNotFoundException $e) {
            return parent::fetchAll($options, $connection);
        }

        // each user needs to be cloned and handed a connection
        $result = new FieldedIterator;
        $limit  = $options[static::FETCH_MAXIMUM];
        $names  = $options[static::FETCH_BY_NAME];
        $names  = is_string($names) ? array($names) : $names;
        foreach ($users as $id => $user) {
            // if max limiting, stop when/if we exceed max
            if ($limit && count($result) >= $limit) {
                break;
            }

            // if filtering by name, exclude users that don't match
            if (is_array($names)) {
                $match = false;
                foreach ($names as $name) {
                    // to match p4 behavior, we run $name through preg_quote then use a
                    // preg_replace to make \* into .+ (or .* if its at the end).
                    $pattern = '/^' . preg_quote($name, '/') . '$/';
                    $pattern = preg_replace('/\\\\\*/', '.+', $pattern);
                    $pattern = preg_replace('/\.+$/',   '.*', $pattern);
                    if (preg_match($pattern . (!$connection->isCaseSensitive() ? 'i' : ''), $id)) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            $user = clone $user;
            $user->setConnection($connection);
            $result[$id] = $user;
        }

        return $result;
    }

    /**
     * A convenience method to filter all invalid/non-existent user ids from a passed list.
     *
     * @param   array|string    $users      one or more user ids to filter for validity
     * @param   Connection      $connection optional - a specific connection to use.
     * @return  array           the filtered result
     */
    public static function filter($users, Connection $connection = null)
    {
        // we don't want user ids which contain wildcards, isValidId
        // should remove these and any other wacky input values
        foreach ($users as $key => $user) {
            if (!static::isValidId($user)) {
                unset($users[$key]);
            }
        }

        // if, after filtering, we have no users; simply return
        if (!$users) {
            return $users;
        }

        // leverage fetchAll to do the heavy lifting
        return static::fetchAll(
            array(static::FETCH_BY_NAME => $users),
            $connection
        )->invoke('getId');
    }

    /**
     * Get config record for this user. If there is no config record, make one.
     * Config records are useful for storage of arbitrary user settings.
     *
     * @return  Config  the associated user config record
     */
    public function getConfig()
    {
        if (!$this->config instanceof Config) {
            try {
                $config = Config::fetch($this->getId(), $this->getConnection());
            } catch (RecordNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            if (!isset($config)) {
                $config = new Config($this->getConnection());
                $config->setId($this->getId());
            }

            $this->config = $config;
        }

        return $this->config;
    }

    /**
     * Set the config record for this user.
     *
     * @param   Config  $config     the config record to associate with this user
     * @return  User    provides a fluent interface
     */
    public function setConfig(Config $config)
    {
        $config->setId($this->getId());
        $this->config = $config;

        return $this;
    }

    /**
     * Extends save to store the config record.
     *
     * @return  User    provides a fluent interface
     */
    public function save()
    {
        parent::save();

        if ($this->config instanceof Config) {
            $this->config->setId($this->getId());
            $this->config->save();
        }

        return $this;
    }

    /**
     * Get the user cache. Populate cache if empty.
     *
     * The high-level flow of this is:
     *  - try to read cache, return if that works
     *  - if read fails, try to build cache
     *  - whether write works or not, try to read cache again
     *  - if read fails again, throw.
     *
     * @param   Connection  $connection     optional - a specific connection to use.
     * @return  ArrayReader                 a memory efficient user iterator
     */
    protected static function getCachedUsers(Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        $cache      = $connection->getService('cache');

        // users are cached with an index file, so we can use the streaming reader to save on memory
        // if this fails for any reason, assume that the user cache needs to be (re)built.
        try {
            return $cache->getReader('users');
        } catch (\Exception $e) {
            // we will attempt to rebuild the cache below
        }

        // this can take a while if there are lots of users - let it run for 30m
        $limit = ini_get('max_execution_time');
        ini_set('max_execution_time', 30 * 60);

        // wrap cache rebuild in try/catch so we can make one last attempt at reading
        try {
            $file   = $cache->getFile('users');
            $writer = new ArrayWriter($file, true);
            $writer->createFile();

            $users = static::fetchAll(array(static::FETCH_NO_CACHE => true), $connection);
            foreach ($users as $key => $user) {
                $writer->writeElement($key, $user);
            }

            // need to close file to record array length
            $writer->closeFile();
        } catch (\Exception $writerException) {
            // writer can throw due to a race condition (another process just built the cache)
            // or due to a legitimate problem (such as bad file permissions), either way we
            // try to read again and if that fails then we re-throw this exception
        }

        // hard work is done, restore original time limit
        ini_set('max_execution_time', $limit);

        // return reader for newly cached users
        try {
            return $cache->getReader('users');
        } catch (\Exception $readerException) {
            // we pick the best exception to re-throw below
        }

        // if we get this far we have a writer and/or a reader exception
        // the writer exception is more relevant, so favor it over the reader
        throw isset($writerException) ? $writerException : $readerException;
    }
}
