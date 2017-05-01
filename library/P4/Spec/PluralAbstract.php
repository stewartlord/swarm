<?php
/**
 * This class layers support for plural specs such as changes, jobs,
 * users, etc. on top of the singular spec support already present
 * in P4\Spec\SingularAbstract.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4;
use P4\Validate;
use P4\Spec\Exception\Exception;
use P4\Spec\Exception\NotFoundException;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\OutputHandler\Limit;

abstract class PluralAbstract extends SingularAbstract
{
    const ID_FIELD              = null;
    const FETCH_MAXIMUM         = 'maximum';
    const FETCH_AFTER           = 'after';
    const TEMP_ID_PREFIX        = '~tmp';
    const TEMP_ID_DELIMITER     = ".";

    /**
     * Get the id of this spec entry.
     *
     * @return  null|string     the id of this entry.
     */
    public function getId()
    {
        if (array_key_exists(static::ID_FIELD, $this->values)) {
            return $this->values[static::ID_FIELD];
        } else {
            return null;
        }
    }

    /**
     * Set the id of this spec entry. Id must be in a valid format or null.
     *
     * @param   null|string     $id     the id of this entry - pass null to clear.
     * @return  PluralAbstract          provides a fluent interface
     * @throws  \InvalidArgumentException   if id does not pass validation.
     */
    public function setId($id)
    {
        if ($id !== null && !static::isValidId($id)) {
            throw new \InvalidArgumentException("Cannot set id. Id is invalid.");
        }

        // if populate was deferred, caller expects it
        // to have been populated already.
        $this->populate();

        $this->values[static::ID_FIELD] = $id;

        return $this;
    }

    /**
     * Determine if a spec record with the given id exists.
     * Must be implemented by sub-classes because this test
     * is impractical to generalize.
     *
     * @param   string                  $id             the id to check for.
     * @param   ConnectionInterface     $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing record.
     */
    abstract public static function exists($id, ConnectionInterface $connection = null);

    /**
     * Get the requested spec entry from Perforce.
     *
     * @param   string                  $id         the id of the entry to fetch.
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  PluralAbstract          instace of the requested entry.
     * @throws  \InvalidArgumentException   if no id is given.
     */
    public static function fetch($id, ConnectionInterface $connection = null)
    {
        // ensure a valid id is provided.
        if (!static::isValidId($id)) {
            throw new \InvalidArgumentException("Must supply a valid id to fetch.");
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // ensure id exists.
        if (!static::exists($id, $connection)) {
            throw new NotFoundException(
                "Cannot fetch " . static::SPEC_TYPE . " $id. Record does not exist."
            );
        }

        // construct spec instance.
        $spec = new static($connection);
        $spec->setId($id)
             ->deferPopulate();

        return $spec;
    }

    /**
     * Get all entries of this type from Perforce.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                  FETCH_MAXIMUM - set to integer value to limit to the
     *                                                  first 'max' number of entries.
     *                                    FETCH_AFTER - set to an id _after_ which to start collecting entries
     *                                                  note: entries seen before 'after' count towards max.
     *
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  FieldedIterator         all records of this type.
     * @todo    make limit work for depot (in a P4\Spec\Depot sub-class)
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // get command to use
        $command = static::getFetchAllCommand();

        // get command flags for given fetch options.
        $flags = static::getFetchAllFlags($options);

        // fetch all specs.
        // configure a handler to enforce 'after' (skip entries up to and including 'after')
        $after = isset($options[static::FETCH_AFTER]) ? $options[static::FETCH_AFTER] : null;
        if (strlen($after)) {
            $idField = static::ID_FIELD;
            $isAfter = false;
            $handler = new Limit;
            $handler->setFilterCallback(
                function ($data) use ($after, $idField, &$isAfter) {
                    if ($after && !$isAfter) {
                        // id field could be upper or lower case in list output.
                        $id      = isset($data[lcfirst($idField)]) ? $data[lcfirst($idField)] : null;
                        $id      = !$id && isset($data[$idField])  ? $data[$idField]          : $id;
                        $isAfter = ($after == $id);
                        return false;
                    }
                    return true;
                }
            );
            $result = $connection->runHandler($handler, $command, $flags);
        } else {
            $result = $connection->run($command, $flags);
        }

        // expand any sequences present
        $result->expandSequences();

        // convert result data to spec objects.
        $specs = new FieldedIterator;
        foreach ($result->getData() as $data) {
            $spec = static::fromSpecListEntry($data, $flags, $connection);
            $specs[$spec->getId()] = $spec;
        }

        return $specs;
    }

    /**
     * Create a temporary entry.
     *
     * The passed values can, optionally, specify the id of the temp entry.
     * If no id is passed in values, one will be generated following the
     * conventions described in makeTempId().
     *
     * Temp entries are deleted when the connection is closed.
     *
     * @param   array|null              $values             optional - values to set on temp entry,
     *                                                      can include ID
     * @param   function|null           $cleanupCallback    optional - callback to use for cleanup.
     *                                                      signature is:
     *                                                      function($entry, $defaultCallback)
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  PluralAbstract          instace of the temp entry.
     */
    public static function makeTemp(
        array $values = null,
        $cleanupCallback = null,
        ConnectionInterface $connection = null
    ) {
        // normalize to array
        $values = $values ?: array();

        // generate an id if no value for our id field is present
        if (!isset($values[static::ID_FIELD])) {
            $values[static::ID_FIELD] = static::makeTempId();
        }

        // create the temporary instance.
        $temp = new static($connection);
        $temp->set($values)->save();

        // remove the temp entry when the connection terminates.
        $defaultCallback = static::getTempCleanupCallback();
        $temp->getConnection()->addDisconnectCallback(
            function ($connection) use ($temp, $cleanupCallback, $defaultCallback) {
                try {
                    // use the passed callback if valid, fallback to the default callback
                    if (is_callable($cleanupCallback)) {
                        $cleanupCallback($temp, $defaultCallback);
                    } else {
                        $defaultCallback($temp);
                    }
                } catch (\Exception $e) {
                    P4\Log::logException("Failed to delete temporary entry.", $e);
                }
            }
        );

        return $temp;
    }

    /**
     * Generate a temporary id by combining the id prefix
     * with the current time, pid and a random uniqid():
     *
     *  ~tmp.<unixtime>.<pid>.<uniqid>
     *
     * The leading tilde ('~') places the temporary id at the end of
     * the list.  The unixtime ensures that the oldest ids will
     * appear first (among temp ids), while the pid and uniqid provide
     * reasonable assurance that no two ids will collide.
     *
     * @return  string  an id suitable for use with temporary specs.
     */
    public static function makeTempId()
    {
        return implode(
            static::TEMP_ID_DELIMITER,
            array(
                static::TEMP_ID_PREFIX,
                time(),
                getmypid(),
                uniqid("", true)
            )
        );
    }

    /**
     * Delete this spec entry.
     *
     * @param   array   $params     optional - additional flags to pass to delete
     *                              (e.g. some specs support -f to force delete).
     * @return  PluralAbstract      provides a fluent interface
     * @throws  Exception           if no id has been set.
     */
    public function delete(array $params = null)
    {
        $id = $this->getId();
        if ($id === null) {
            throw new Exception("Cannot delete. No id has been set.");
        }

        // ensure id exists.
        $connection = $this->getConnection();
        if (!static::exists($id, $connection)) {
            throw new NotFoundException(
                "Cannot delete " . static::SPEC_TYPE . " $id. Record does not exist."
            );
        }

        $params = array_merge((array) $params, array("-d", $id));
        $result = $connection->run(static::SPEC_TYPE, $params);

        // should re-populate.
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Get a field's raw value.
     * Extend parent to use getId() for id field.
     *
     * @param   string      $field  the name of the field to get the value of.
     * @return  mixed       the value of the field.
     * @throws  Exception   if the field does not exist.
     */
    public function getRawValue($field)
    {
        if ($field === static::ID_FIELD) {
            return $this->getId();
        }

        // call-through.
        return parent::getRawValue($field);
    }

    /**
     * Set a field's raw value.
     * Extend parent to use setId() for id field.
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  SingularAbstract    provides a fluent interface
     * @throws  Exception           if the field does not exist.
     */
    public function setRawValue($field, $value)
    {
        if ($field === static::ID_FIELD) {
            return $this->setId($value);
        }

        // call-through.
        return parent::setRawValue($field, $value);
    }

    /**
     * Extended to preserve id when values are cleared.
     * Schedule populate to run when data is requested (lazy-load).
     *
     * @param   bool    $reset  optionally clear instance values.
     */
    public function deferPopulate($reset = false)
    {
        if ($reset) {
            $id = $this->getId();
        }

        parent::deferPopulate($reset);

        if ($reset) {
            $this->setId($id);
        }
    }

    /**
     * Provide a callback function to be used during cleanup of
     * temp entries. The callback should expect a single parameter,
     * the entry being removed.
     *
     * @return callable     A callback function with the signature function($entry)
     */
    protected static function getTempCleanupCallback()
    {
        return function ($entry) {
            // remove the temp entry we are responsible for
            $entry->delete();
        };
    }

    /**
     * Check if the given id is in a valid format for this spec type.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\SpecName;
        return $validator->isValid($id);
    }

    /**
     * Extend parent populate to exit early if id is null.
     */
    protected function populate()
    {
        // early exit if populate not needed.
        if (!$this->needsPopulate) {
            return;
        }

        // don't attempt populate if id null.
        if ($this->getId() === null) {
            return;
        }

        parent::populate();
    }

    /**
     * Get raw spec data direct from Perforce. No caching involved.
     * Extends parent to supply an id to the spec -o command.
     *
     * @return  array   $data   the raw spec output from Perforce.
     */
    protected function getSpecData()
    {
        $result = $this->getConnection()->run(
            static::SPEC_TYPE,
            array("-o", $this->getId())
        );
        return $result->expandSequences()->getData(-1);
    }

    /**
     * Given a spec entry from spec list output (e.g. 'p4 jobs'), produce
     * an instance of this spec with field values set where possible.
     *
     * @param   array                   $listEntry      a single spec entry from spec list output.
     * @param   array                   $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface     $connection     a specific connection to use.
     * @return  PluralAbstract          a (partially) populated instance of this spec class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        // most spec list entries have leading lower-case field
        // names which is inconsistent with defined field names.
        // make all field names lead with an upper-case letter.
        $keys      = array_map('ucfirst', array_keys($listEntry));
        $listEntry = array_combine($keys, $listEntry);

        // convert common timestamps to dates
        if (isset($listEntry['Time'])) {
            $listEntry['Date']   = static::timeToDate($listEntry['Time'],   $connection);
            unset($listEntry['Time']);
        }
        if (isset($listEntry['Update'])) {
            $listEntry['Update'] = static::timeToDate($listEntry['Update'], $connection);
            unset($listEntry['Update']);
        }
        if (isset($listEntry['Access'])) {
            $listEntry['Access'] = static::timeToDate($listEntry['Access'], $connection);
            unset($listEntry['Access']);
        }

        // instantiate new spec object and set raw field values.
        $spec = new static($connection);
        $spec->setRawValues($listEntry)
             ->deferPopulate();

        return $spec;
    }

    /**
     * Convert the given unix timestamp into the server's typical date
     * format accounting for the server's current timezone.
     *
     * @param   int|string          $time       the timestamp to convert
     * @param   ConnectionInterface $connection the connection to use
     * @return  string              date in the typical server format
     */
    protected static function timeToDate($time, ConnectionInterface $connection)
    {
        $date = new \DateTime('@' . $time);

        // try and use the p4 info timezone, if that fails fall back to our local timezone
        try {
            $date->setTimeZone($connection->getTimeZone());
        } catch (\Exception $e) {
            // we tried and failed; just let it use php's default time zone
            // note when creating a DateTime from a unix timestamp the timezone will
            // be UTC, we need to explicitly set it to the default time zone.
            $date->setTimeZone(new \DateTimeZone(date_default_timezone_get()));
        }

        return $date->format('Y/m/d H:i:s');
    }

    /**
     * Inverse function to timeToDate(), it converts the given date in server's typical
     * format into a unix timestamp accounting for the server's current timezone.
     *
     * @param  string               $date           date in typical server's format (Y/m/d H:i:s) to convert
     * @param  ConnectionInterface  $connection     the connection to use
     * @return int|false            date in unix timestamp or false if unable to convert
     */
    protected static function dateToTime($date, ConnectionInterface $connection)
    {
        // try and use the p4 info timezone, if that fails fall back to our local timezone
        $dateTimeZone = null;
        try {
            $dateTimeZone = $connection->getTimeZone();
        } catch (\Exception $e) {
            // we tried and failed; just let it use php's default time zone
        }

        $dateTime = $dateTimeZone
            ? \DateTime::createFromFormat('Y/m/d H:i:s', $date, $dateTimeZone)
            : \DateTime::createFromFormat('Y/m/d H:i:s', $date);

        return $dateTime ? (int) $dateTime->format('U') : false;
    }

    /**
     * Produce set of flags for the spec list command, given fetch all options array.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        $flags = array();

        if (isset($options[self::FETCH_MAXIMUM])) {
            $flags[] = "-m";
            $flags[] = (int) $options[self::FETCH_MAXIMUM];
        }

        return $flags;
    }

    /**
     * Get the fetch all command, generally a plural version of the spec type.
     *
     * @return  string  Perforce command to use for fetchAll
     */
    protected static function getFetchAllCommand()
    {
        // derive list command from spec type by adding 's'
        // this works for most of the known plural specs
        return static::SPEC_TYPE . "s";
    }
}
