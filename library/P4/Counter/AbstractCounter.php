<?php
/**
 * Abstracts operations against Perforce counters.
 *
 * This class is somewhat unique as calling set will immediately write the new value
 * to perforce; no separate save step is required.
 * When reading values out we do attempt to use cached results, to ensure you read
 * out the value directly from perforce set $force to true when calling get.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Counter;

use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Counter\Exception\NotFoundException;
use P4\Model\Connected;
use P4\Exception;
use P4\OutputHandler\Limit;

abstract class AbstractCounter extends Connected\ConnectedAbstract
{
    const       FETCH_MAXIMUM   = 'maximum';
    const       FETCH_BY_NAME   = 'name';
    const       FETCH_AFTER     = 'after';

    // flags can be specified by the implementer. they will be included for
    // all calls to 'p4 counter' or 'p4 counters' (e.g. -u to swap to keys)
    protected static $flags     = array();

    protected $id               = null;
    protected $value            = null;

    /**
     * Get the id of this counter.
     *
     * @return  null|string     the id of this entry.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the id of this counter. Id must be in a valid format or null.
     *
     * @param   null|string     $id     the id of this entry - pass null to clear.
     * @return  Counter                 provides a fluent interface
     * @throws  \InvalidArgumentException   if id does not pass validation.
     */
    public function setId($id)
    {
        if ($id !== null && !static::isValidId($id)) {
            throw new \InvalidArgumentException("Cannot set id. Id is invalid.");
        }

        $this->id    = $id;
        $this->value = null;

        return $this;
    }

    /**
     * Determine if the given counter id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing counter.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $counters = static::fetchAll(
            array(static::FETCH_BY_NAME => $id),
            $connection
        );

        return in_array($id, $counters->invoke('getId'));
    }

    /**
     * Get the requested counter from Perforce.
     *
     * @param   string                  $id         the id of the counter to fetch.
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  Counter                 instace of the requested counter.
     * @throws  \InvalidArgumentException   if invalid id is given.
     * @throws  NotFoundException           if record cannot be located
     */
    public static function fetch($id, ConnectionInterface $connection = null)
    {
        // ensure a valid id is provided.
        if (!static::isValidId($id)) {
            throw new \InvalidArgumentException("Must supply a valid id to fetch.");
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        $counters = static::fetchAll(
            array(static::FETCH_BY_NAME => $id),
            $connection
        );

        // be defensive; we only expect one result but ensure its the correct id
        foreach ($counters as $counter) {
            if ($counter->getId() == $id) {
                return $counter;
            }
        }

        // if we made it here we couldn't find the counter so throw
        throw new NotFoundException(
            "Cannot fetch entry. Id does not exist."
        );
    }

    /**
     * Get all Counters from Perforce.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                                   Note: Max limit is imposed client side on <2013.1.
     *                                   FETCH_BY_NAME - set to string value to limit to counters
     *                                                   matching the given name/pattern.
     *                                     FETCH_AFTER - set to an id _after_ which we start collecting
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  Connected\Iterator      all counters matching passed option(s).
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // check if the server supports filtering counters (-e) or max (-m)
        $supportsFilter = $connection->isServerMinVersion('2010.1');
        $supportsMax    = $connection->isServerMinVersion('2013.1');

        // normalize options and pull them out
        $options += array(static::FETCH_MAXIMUM => 0, static::FETCH_BY_NAME => null, static::FETCH_AFTER => null);
        $max      = (int) $options[static::FETCH_MAXIMUM];
        $after    = $options[static::FETCH_AFTER];
        $isAfter  = false;
        $filter   = $options[static::FETCH_BY_NAME];
        $pattern  = false;

        // configure params starting with default flags for this model
        $params   = static::$flags;

        // use server max limiting if we have no after/filters to interfere and its supported
        if ($max && !$after && (!$filter || $supportsFilter) && $supportsMax) {
            $params[] = '-m';
            $params[] = $max;
        }

        // user server side filtering if possible, fall back to defining regex
        if ($filter && $supportsFilter) {
            $params[] = '-e';
            $params[] = $options[static::FETCH_BY_NAME];
        } elseif ($filter) {
            $pattern = preg_quote($options[static::FETCH_BY_NAME]);
            $pattern = '/^' . str_replace('\*', '.*', $pattern) . '$/';
        }

        // configure a handler to enforce limit, after and filter
        $handler = new Limit;
        $handler->setMax($max);
        $handler->setFilterCallback(
            function ($data) use ($pattern, $after, &$isAfter) {
                // skip entries which fail our pattern
                if ($pattern && !preg_match($pattern, $data['counter'])) {
                    return false;
                }

                // if we have an 'after' and haven't seen it check and skip
                if ($after && !$isAfter) {
                    $isAfter = ($after == $data['counter']);
                    return false;
                }

                // made it this far its a good entry don't filter it
                return true;
            }
        );

        // convert result data to counter objects.
        $result   = $connection->runHandler($handler, 'counters', $params);
        $counters = new Connected\Iterator;
        foreach ($result->getData() as $data) {
            // populate a counter and add it to the iterator
            try {
                $counter = new static($connection);
                $counter->setId($data['counter']);
                $counter->value = $data['value'];
            } catch (\InvalidArgumentException $e) {
                // assume id was invalid - ignore.
                continue;
            }

            $counters[] = $counter;
        }

        return $counters;
    }

    /**
     * Get counter's value.
     *
     * If a cached value is available it will, by default, be used. If you pass
     * true as the $force param you can force the current value to always be
     * read out from the perforce server.
     *
     * @param   bool    $force      optional - false (default) allow cached value
     *                              true ensure current value is read from p4d
     * @return  mixed   the value of the counter.
     */
    public function get($force = false)
    {
        // if we have a cached value and the caller allows it simply return
        if (!$force && $this->value !== null) {
            return $this->value;
        }

        $id = $this->getId();
        $connection = $this->getConnection();

        // if the ID is not set or the ID doesn't exist in perforce, return null
        if ($id === null || !static::exists($id, $connection)) {
            return null;
        }

        $params   = static::$flags;
        $params[] = $id;
        $result   = $connection->run('counter', $params);
        $data     = $result->getData();
        $value    = $data[0]['value'];

        // cache the value for later
        $this->value = $value;

        return $value;
    }

    /**
     * Increment counters value by 1. If the counter doesn't exist it will be
     * created and assigned the value 1.
     * The update is carried out atomically by the server.
     *
     * @return  string          The counters new value
     * @throws  Exception       If the current value is non-numeric
     */
    public function increment()
    {
        $id = $this->getId();
        if ($id === null) {
            throw new Exception("Cannot increment value. No id has been set.");
        }

        $params   = static::$flags;
        $params[] = '-i';
        $params[] = $id;
        $result   = $this->getConnection()->run('counter', $params);
        $data     = $result->getData();
        $value    = $data[0]['value'];

        // update our value cache
        $this->value = $value;

        return $value;
    }

    /**
     * Delete this counter entry. We intend implementor to provide an
     * actual 'delete' method at which point they can decide if they
     * wan't to expose 'force' or not.
     *
     *
     * @param   bool    $force      optional - force delete the counter.
     * @return  Counter             provides a fluent interface
     * @throws  Exception           if no id has been set.
     */
    protected function doDelete($force = false)
    {
        $id = $this->getId();
        if ($id === null) {
            throw new Exception("Cannot delete. No id has been set.");
        }

        // setup counter command args.
        $params = static::$flags;
        if ($force) {
            $params[] = '-f';
        }
        $params[] = "-d";
        $params[] = $id;

        try {
            $this->getConnection()->run('counter', $params);
        } catch (CommandException $e) {
            if (strpos($e->getMessage(), 'No such counter')) {
                throw new NotFoundException(
                    "Cannot delete entry. Id does not exist."
                );
            }
            throw $e;
        }

        // clear our cached value
        $this->value = null;

        return $this;
    }

    /**
     * Set counters value. The value will be immediately written to perforce.
     * We intend implementor to provide an actual 'set' method at which
     * point they can decide if they wan't to expose 'force' or not.
     *
     * @param   mixed   $value  the value to set in the counter.
     * @param   bool    $force  optional - force set the counter.
     * @return  Counter         provides a fluent interface
     * @throws  Exception       if no Id has been set
     */
    protected function doSet($value, $force = false)
    {
        $id = $this->getId();
        if ($id === null) {
            throw new Exception("Cannot set value. No id has been set.");
        }

        // setup counter command args.
        $params = static::$flags;
        if ($force) {
            $params[] = '-f';
        }
        $params[] = $id;
        $params[] = $value;

        $this->getConnection()->run('counter', $params);

        // update our value cache
        $this->value = $value;

        return $this;
    }

    /**
     * Check if the given id is in a valid format.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new \P4\Validate\CounterName;
        return $validator->isValid($id);
    }
}
