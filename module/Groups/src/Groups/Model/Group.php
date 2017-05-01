<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Groups\Model;

use Groups\Model\Config;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Cache\ArrayReader;
use Record\Cache\ArrayWriter;
use Record\Exception\NotFoundException as RecordNotFoundException;

class Group extends \P4\Spec\Group
{
    const   FETCH_NO_CACHE  = 'noCache';

    protected $config       = null;

    /**
     * Extends parent to allow undefined values to be set.
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  SingularAbstract    provides a fluent interface
     * @throws  Exception           if the field is read-only
     * @todo    remove this when/if we stop using fielded iterator for groups api
     */
    public function setRawValue($field, $value)
    {
        if (!$this->hasField($field)) {
            $this->values[$field] = $value;
            return $this;
        }

        return parent::setRawValue($field, $value);
    }

    /**
     * Extends parent to include adhoc fields.
     *
     * @return  array   a list of field names for this spec.
     * @todo    remove this when/if we stop using fielded iterator for groups api
     */
    public function getFields()
    {
        return array_unique(array_merge(parent::getFields(), array_keys($this->values)));
    }

    /**
     * Creates a new Group object and sets the passed values on it.
     *
     * @param   array       $values         array of values to set on the new group
     * @param   Connection  $connection     connection to set on the new group
     * @param   bool        $setRawValues   if true, then $values will be set as they are (avoids mutators)
     *                                      otherwise $values will be set by using mutators (if available)
     *                                      avoiding mutators might save some performance as it skips validating ids
     *                                      for users, owners and/or subgroups (unnecessary if populating from cache)
     * @return  Group       the populated group
     */
    public static function fromArray($values, Connection $connection = null, $setRawValues = false)
    {
        $group = new static($connection);

        // determine method to set the $values via
        $set = $setRawValues ? 'setRawValues' : 'set';

        // extract config data from $values into the config instance
        $config = new Config($connection);
        $config->$set(isset($values['config']) ? $values['config'] : array());
        unset($values['config']);

        $group->$set($values);

        // set config on the group instance now
        // we do this after setting group values so the config gets the id
        $group->setConfig($config);

        // if you provided an id; we defer populate to allow lazy loading.
        // in practice; we anticipate the object is already fully populated
        // so this really shouldn't make an impact.
        if (isset($values['Group'])) {
            $group->deferPopulate();
        }

        return $group;
    }

    /**
     * Extends exists to use cache if available.
     *
     * @param   string      $id             the id to check for.
     * @param   Connection  $connection     optional - a specific connection to use.
     * @return  bool        true if the given id matches an existing group.
     */
    public static function exists($id, Connection $connection = null)
    {
        try {
            $groups = static::getCachedData($connection);
            return isset($groups[$id]);
        } catch (ServiceNotFoundException $e) {
            return parent::exists($id, $connection);
        }
    }

    /**
     * Just get the list of member ids associated with the passed group.
     *
     * @param   string      $id         the id of the group to fetch members of.
     * @param   array       $options    optional - array of options to augment fetch behavior.
     *                                    FETCH_INDIRECT - used to also list indirect matches.
     * @param   Connection  $connection optional - a specific connection to use.
     * @return  array       an array of member ids
     */
    public static function fetchMembers($id, $options = array(), Connection $connection = null)
    {
        $seen    = array();
        $recurse = function ($id) use (&$recurse, &$seen, $connection) {
            $group     = Group::fetch($id, $connection);
            $users     = $group->getUsers();
            $seen[$id] = true;
            foreach ($group->getSubgroups() as $sub) {
                if (!isset($seen[$sub])) {
                    $users = array_merge($users, $recurse($sub));
                }
            }
            return $users;
        };

        // if indirect fetching is enabled; go recursive
        if (isset($options[static::FETCH_INDIRECT]) && $options[static::FETCH_INDIRECT]) {
            return array_unique($recurse($id));
        }

        return static::fetch($id, $connection)->getUsers();
    }

    /**
     * Get all members of this group recursively.
     * Optimized to avoid hydrating groups and to use the group cache directly.
     *
     * @param   string              $id             id of group to get users in
     * @param   bool                $flip           if true array keys are the group ids (default is false)
     * @param   Iterator|null       $groups         list of groups to use (used when recursing)
     * @param   array|null          $seen           groups we've seen as keys (used when recursing)
     * @param   Connection|null     $connection     optional - connection to use
     * @return  array               flat list of all members
     */
    public static function fetchAllMembers(
        $id,
        $flip = false,
        $groups = null,
        array $seen = null,
        Connection $connection = null
    ) {
        $groups = $groups ?: static::getCachedData($connection);

        if (!isset($groups[$id])) {
            return array();
        }

        $seen      = (array) $seen + array($id => true);
        $group     = $groups[$id];
        $users     = isset($group['Users'])     ? array_flip((array) $group['Users']) : array();
        $subGroups = isset($group['Subgroups']) ? (array) $group['Subgroups']         : array();

        // recursively explore sub-groups, but don't re-evaluate groups we've already seen
        foreach ($subGroups as $subGroup) {
            if (!isset($seen[$subGroup])) {
                $users += static::fetchAllMembers($subGroup, true, $groups, $seen, $connection);
            }
        }

        return $flip ? $users : array_keys($users);
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
            $groups = static::getCachedData($connection);
        } catch (ServiceNotFoundException $e) {
            return parent::fetch($id, $connection);
        }

        // if we have a cached group, turn it into an object
        if (isset($groups[$id])) {
            return static::fromArray($groups[$id], $connection, true);
        }

        throw new SpecNotFoundException("Cannot fetch group $id. Record does not exist.");
    }

    /**
     * Extends fetchAll to use cache if available.
     *
     * @param   array       $options    optional - array of options to augment fetch behavior.
     *                                  supported options are:
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                                   *Note: Limits imposed client side.
     *                                 FETCH_BY_MEMBER - Not supported
     *                                   FETCH_BY_USER - get groups containing passed user (no wildcards).
     *                                  FETCH_INDIRECT - used with FETCH_BY_MEMBER or FETCH_BY_USER
     *                                                   to also list indirect matches.
     *                                   FETCH_BY_NAME - get the named group. essentially a 'fetch'
     *                                                   but performed differently (no wildcards).
     *                                                   *Note: not compatible with FETCH_BY_MEMBER
     *                                                          FETCH_BY_USER or FETCH_INDIRECT
     *                                  FETCH_NO_CACHE - set to true to avoid using the cache.
     *
     * @param   Connection  $connection optional - a specific connection to use.
     * @return  FieldedIterator         all matching records of this type.
     * @throws  \InvalidArgumentException       if FETCH_BY_MEMBER is used
     */
    public static function fetchAll($options = array(), Connection $connection = null)
    {
        // Validate the various options by having parent generate fetch all flags.
        // We don't actually use the flags but the option verification is valuable.
        static::getFetchAllFlags($options);

        if (isset($options[static::FETCH_BY_MEMBER]) && $options[static::FETCH_BY_MEMBER]) {
            throw new \InvalidArgumentException(
                "The User Group model doesn't support FETCH_BY_MEMBER."
            );
        }

        // normalize connection
        $connection = $connection ?: static::getDefaultConnection();

        // optionally avoid the cache
        if (isset($options[static::FETCH_NO_CACHE]) && $options[static::FETCH_NO_CACHE]) {
            return parent::fetchAll($options, $connection);
        }

        // if we have a cache service use it; otherwise let parent handle it
        try {
            $groups = static::getCachedData($connection);
        } catch (ServiceNotFoundException $e) {
            return parent::fetchAll($options, $connection);
        }

        // now that parent is done with options; normalize them
        // if we do this earlier it will cause issues with parent
        $options    = (array) $options + array(
            static::FETCH_MAXIMUM   => null,
            static::FETCH_BY_MEMBER => null,
            static::FETCH_BY_USER   => null,
            static::FETCH_INDIRECT  => null,
            static::FETCH_BY_NAME   => null
        );

        // always going to have an iterator as a result at this point; make it
        $result = new FieldedIterator;

        // Fetch by name is essentially a fetch that returns an iterator
        // handle that case early as it is simple
        if ($options[static::FETCH_BY_NAME]) {
            $id = $options[static::FETCH_BY_NAME];
            if (isset($groups[$id])) {
                $result[$id] = static::fromArray($groups[$id], $connection, true);
            }
            return $result;
        }

        // turn group arrays into objects and apply various filters if present
        $limit    = $options[static::FETCH_MAXIMUM];
        $user     = $options[static::FETCH_BY_USER];
        $indirect = $options[static::FETCH_INDIRECT];
        foreach ($groups as $id => $group) {
            // if max limiting, stop when/if we exceed max
            if ($limit && count($result) >= $limit) {
                break;
            }

            // if filtering by member, exclude groups that don't match
            if ($user && !static::isMember($user, $id, $indirect, $connection)) {
                continue;
            }

            // passes the filters, lets add it to the result
            $result[$id] = static::fromArray($group, $connection, true);
        }

        return $result;
    }

    /**
     * Test if the passed user is a direct (or if recursive is set, even indirect)
     * member of the specified group.
     *
     * @param   string      $user       the user id to check membership for
     * @param   string      $group      the group id we are looking in
     * @param   bool        $recursive  true if we are also checking sub-groups,
     *                                  false for only testing direct membership
     * @param   Connection  $connection optional - a specific connection to use.
     * @param   array|null  $seen       groups we've seen as keys (used when recursing)
     * @return  bool        true if user is a member of specified group (or sub-group if recursive), false otherwise
     * @throws  \InvalidArgumentException   if an invalidly formatted user of group id is passed
     */
    public static function isMember(
        $user,
        $group,
        $recursive = false,
        Connection $connection = null,
        array $seen = null
    ) {
        // do basic input validation
        if (!static::isValidUserId($user)) {
            throw new \InvalidArgumentException(
                'Is Member expects a valid username.'
            );
        }
        if (!static::isValidId($group)) {
            throw new \InvalidArgumentException(
                'Is Member expects a valid group.'
            );
        }

        // try and get the group cache. if we fail, fall back to a live check
        try {
            $groups = static::getCachedData($connection);
        } catch (ServiceNotFoundException $e) {
            $groups = parent::fetchAll(
                array(
                     static::FETCH_BY_MEMBER => $user,
                     static::FETCH_INDIRECT  => $recursive
                ),
                $connection
            );

            return isset($groups[$group]);
        }

        // if the group they asked for doesn't exist, not a member
        if (!isset($groups[$group])) {
            return false;
        }

        // if the user is a direct member; return true
        if (in_array($user, $groups[$group]['Users'])) {
            return true;
        }

        // if recursion is on, check all sub-groups
        // avoid circular references by tracking which groups we've seen
        if ($recursive) {
            $seen = (array) $seen + array($group => true);
            foreach ($groups[$group]['Subgroups'] as $sub) {
                if (!isset($seen[$sub]) && static::isMember($user, $sub, true, $connection, $seen)) {
                    return true;
                }
            }
        }

        // if we make it to the end they aren't a member
        return false;
    }

    /**
     * Get config record for this group. If there is no config record, make one.
     * Config records are useful for storage of arbitrary group settings.
     *
     * @return  Config  the associated group config record
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
     * Set the config record for this group.
     *
     * @param   Config  $config     the config record to associate with this group
     * @return  Group   provides a fluent interface
     */
    public function setConfig(Config $config)
    {
        $config->setId($this->getId());
        $this->config = $config;
        return $this;
    }

    /**
     * Return true if this group has config, false otherwise.
     *
     * @return  boolean     true if this instance has a config, false otherwise
     */
    public function hasConfig()
    {
        return $this->config !== null;
    }

    /**
     * Extends save to store the config record.
     *
     * @param   bool        $editAsOwner        save the group as a group owner
     * @param   bool        $addAsAdmin         add the group as admin
     * @param   Connection  $specConnection     the connection to use when saving the spec
     *                                          this allows one connection for the spec and another for the contfig
     * @return  Group   provides a fluent interface
     */
    public function save($editAsOwner = false, $addAsAdmin = false, Connection $specConnection = null)
    {
        $connection = $this->getConnection();
        $this->setConnection($specConnection ?: $connection);
        parent::save($editAsOwner, $addAsAdmin);
        $this->setConnection($connection);

        if ($this->config instanceof Config) {
            $this->config->setId($this->getId());
            $this->config->save();
        }

        return $this;
    }

    /**
     * Delete this group and the associated config (if exists).
     *
     * @param   Connection|null     $specConnection     the connection to use when deleting the spec
     */
    public function delete(Connection $specConnection = null)
    {
        $connection = $this->getConnection();

        if (Config::exists($this->getId(), $connection)) {
            $this->getConfig()->delete();
        }

        $this->setConnection($specConnection ?: $connection);
        parent::delete();
        $this->setConnection($connection);
    }

    /**
     * Get the raw group cache (arrays of values). Populate cache if empty.
     *
     * The high-level flow of this is:
     *  - try to read cache, return if that works
     *  - if read fails, try to build cache
     *  - whether write works or not, try to read cache again
     *  - if read fails again, throw.
     *
     * @param   Connection      $connection     optional - a specific connection to use.
     * @return  ArrayReader     a memory efficient group iterator
     */
    public static function getCachedData(Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        $cache      = $connection->getService('cache');

        // groups are cached with an index file, so we can use the streaming reader to save on memory
        // if this fails for any reason, assume that the group cache needs to be (re)built.
        try {
            return $cache->getReader('groups');
        } catch (\Exception $e) {
            // we will attempt to rebuild the cache below
        }

        // this can take a while if there are lots of users/groups - let it run for 30m
        $limit = ini_get('max_execution_time');
        ini_set('max_execution_time', 30 * 60);

        // wrap cache rebuild in try/catch so we can make one last attempt at reading
        try {
            $file   = $cache->getFile('groups');
            $writer = new ArrayWriter($file, true);
            $writer->createFile();

            // fetch all group configs as we will add them to the cache
            $configs = Config::fetchAll(array(), $connection);

            // fetch all of the groups, but use the filter callback to stream them into the cache file
            parent::fetchAll(
                array(
                    Group::FETCH_FILTER_CALLBACK => function ($group) use ($writer, $configs) {
                        $config = isset($configs[$group['Group']])
                            ? array('config' => $configs[$group['Group']]->get())
                            : array();
                        $writer->writeElement($group['Group'], $group + $config);
                        return false;
                    }
                ),
                $connection
            );

            // need to close file to record array length
            $writer->closeFile();
        } catch (\Exception $writerException) {
            // writer can throw due to a race condition (another process just built the cache)
            // or due to a legitimate problem (such as bad file permissions), either way we
            // try to read again and if that fails then we re-throw this exception
        }

        // hard work is done, restore original time limit
        ini_set('max_execution_time', $limit);

        // return reader for newly cached groups
        try {
            return $cache->getReader('groups');
        } catch (\Exception $readerException) {
            // we pick the best exception to re-throw below
        }

        // if we get this far we have a writer and/or a reader exception
        // the writer exception is more relevant, so favor it over the reader
        throw isset($writerException) ? $writerException : $readerException;
    }

    /**
     * Get the group cache sorted by the given fields. Populates sorted cache if needed.
     *
     * @param   array           $sortBy         fields to sort on with field names as keys
     *                                          (set value to true to reverse the order)
     * @param   Connection      $connection     optional - a specific connection to use.
     * @return  ArrayReader     a memory efficient group iterator
     */
    public static function getSortedCachedData(array $sortBy, Connection $connection = null)
    {
        $cache     = $connection->getService('cache');
        $file      = $cache->getFile('groups');
        $sortKey   = strtoupper(md5(serialize($sortBy)));
        $indexFile = $file . '-' . $sortKey . ArrayWriter::INDEX_SUFFIX;
        try {
            // read sorted cache, if this blows up we need to build it
            $groups = new ArrayReader($file, $indexFile);
            $groups->openFile();
        } catch (\Exception $e) {
            // sorted cache-miss, build sorted index
            $groups = Group::getCachedData($connection);
            $groups->sort(
                function ($a, $b) use ($sortBy) {
                    foreach ($sortBy as $field => $reverse) {
                        if ($field === 'isEmailEnabled') {
                            $aFlags = isset($a['config']['emailFlags']) ? $a['config']['emailFlags'] : array();
                            $bFlags = isset($b['config']['emailFlags']) ? $b['config']['emailFlags'] : array();
                            $aValue = (bool) array_filter($aFlags);
                            $bValue = (bool) array_filter($bFlags);
                        } elseif ($field === 'name') {
                            $aValue = (isset($a['config']['name']) ? $a['config']['name'] : null) ?: $a['Group'];
                            $bValue = (isset($b['config']['name']) ? $b['config']['name'] : null) ?: $b['Group'];
                        } else {
                            $aValue = isset($a[$field]) ? $a[$field] : null;
                            $bValue = isset($b[$field]) ? $b[$field] : null;
                        }

                        $order = strnatcasecmp($aValue, $bValue);
                        if ($order) {
                            return $order * ($reverse ? -1 : 1);
                        }
                    }
                    return 0;
                }
            );

            // save sorted index file for next time
            if (is_file($indexFile) && !is_writable($indeFile)) {
                @chmod($indexFile, 0700);
                if (!is_writable($indexFile)) {
                    throw new Exception(
                        "Cannot write to cache file ('" . $indexFile . "'). Check permissions."
                    );
                }
            }
            file_put_contents($indexFile, serialize($groups->getIndex()), LOCK_EX);
        }

        return $groups;
    }
}
