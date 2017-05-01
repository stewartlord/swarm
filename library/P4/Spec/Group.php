<?php
/**
 * Abstracts operations against Perforce user groups.
 *
 * Abandon all hope ye who go beyond this point.
 *
 * Groups is a bit of an odd duck. Identified un-expected behaviour includes:
 * - "group -i" with no populated users/owners/subgroups will report 'created' but it isn't
 * - "groups" output is unusually formatted; see Pural Abstract for details
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\OutputHandler\Limit as LimitHandler;
use P4\Spec\Exception\Exception;
use P4\Validate;

class Group extends PluralAbstract
{
    const SPEC_TYPE             = 'group';
    const ID_FIELD              = 'Group';

    const FETCH_BY_MEMBER       = 'member';
    const FETCH_BY_USER         = 'user';
    const FETCH_INDIRECT        = 'indirect';
    const FETCH_BY_NAME         = 'name';
    const FETCH_FILTER_CALLBACK = 'filterCallback';

    protected $fields = array(
        'MaxResults'    => array(
            'accessor'  => 'getMaxResults',
            'mutator'   => 'setMaxResults'
        ),
        'MaxScanRows'   => array(
            'accessor'  => 'getMaxScanRows',
            'mutator'   => 'setMaxScanRows'
        ),
        'MaxLockTime'   => array(
            'accessor'  => 'getMaxLockTime',
            'mutator'   => 'setMaxLockTime'
        ),
        'Timeout'       => array(
            'accessor'  => 'getTimeout',
            'mutator'   => 'setTimeout'
        ),
        'PasswordTimeout' => array(
            'accessor'  => 'getPasswordTimeout',
            'mutator'   => 'setPasswordTimeout'
        ),
        'Subgroups'     => array(
            'accessor'  => 'getSubgroups',
            'mutator'   => 'setSubgroups'
        ),
        'Owners'        => array(
            'accessor'  => 'getOwners',
            'mutator'   => 'setOwners'
        ),
        'Users'         => array(
            'accessor'  => 'getUsers',
            'mutator'   => 'setUsers'
        )
    );

    /**
     * Get all Groups from Perforce. Adds filtering options.
     * The groups command produces very unique output - we take over parent to handle it here.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                                   *Note: Limits imposed client side.
     *                                 FETCH_BY_MEMBER - get groups containing passed group or
     *                                                   user (no wildcards).
     *                                   FETCH_BY_USER - get groups containing passed user (no wildcards).
     *                                  FETCH_INDIRECT - used with FETCH_BY_MEMBER or FETCH_BY_USER
     *                                                   to also list indirect matches.
     *                                   FETCH_BY_NAME - get the named group. essentially a 'fetch'
     *                                                   but performed differently (no wildcards).
     *                                                   *Note: not compatible with FETCH_BY_MEMBER
     *                                                          FETCH_BY_USER or FETCH_INDIRECT
     *                           FETCH_FILTER_CALLBACK - function that takes group array and returns true
     *                                                   or false to include/exclude the group from result
     *
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  FieldedIterator         all groups satisfying fetch options
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // normalize options we care about
        $options = (array) $options + array(
            static::FETCH_MAXIMUM         => 0,
            static::FETCH_BY_MEMBER       => null,
            static::FETCH_BY_USER         => null,
            static::FETCH_FILTER_CALLBACK => null,
        );
        $options[static::FETCH_MAXIMUM] = (int) $options[static::FETCH_MAXIMUM];

        // if a callback is given, ensure it's callable
        if ($options[static::FETCH_FILTER_CALLBACK] && !is_callable($options[static::FETCH_FILTER_CALLBACK])) {
            throw new \InvalidArgumentException("Filter callback must be callable or null.");
        }

        // fetch all specs using an output handler to minimize memory usage
        //
        // 'groups' produces data-blocks for each user/sub-group/owner, but no
        // data-blocks for the actual groups, this results in a lot of redundant
        // information and it means we need to collate groups ourselves
        //
        // example data-block:
        //   array(
        //      'user'          => 'tester',
        //      'group'         => 'test',
        //      'isSubGroup'    => '0',
        //      'isOwner'       => '0',
        //      'isUser'        => '1',
        //      'maxResults'    => '0',
        //      'maxScanRows'   => '0',
        //      'maxLockTime'   => '0',
        //      'timeout'       => '43200',
        //      'passTimeout'   => '0',
        //      'isValidUser'   => '1',
        //   )
        //
        // the users/sub-groups/owners for a given group are output consecutively
        // as soon as we capture an entire group, we invoke the filter callback
        // (if one was specified) and append/skip the group as appropriate
        $handler = new LimitHandler;
        $groups  = new FieldedIterator;
        $group   = array();
        $count   = 0;
        $max     = $options[static::FETCH_MAXIMUM];
        $filter  = $options[static::FETCH_FILTER_CALLBACK];
        $class   = get_class(new static);
        $handler->setOutputCallback(
            function ($data, $type) use ($groups, &$group, &$count, $options, $max, $filter, $class, $connection) {
                // stop processing if we hit the maximum number of groups
                if ($max && $count >= $max) {
                    return LimitHandler::HANDLER_CANCEL | LimitHandler::HANDLER_HANDLED;
                }

                // skip unexpected data blocks
                // sometimes 'p4 groups' reports a null group (due to job037630), just ignore it
                if (!is_array($data) || $type !== 'stat' || !strlen($data['group'])) {
                    return LimitHandler::HANDLER_HANDLED;
                }

                // if we have hit a new group, process the previous one
                if ($group && $data['group'] !== $group['Group']) {
                    if (!$filter || $filter($group)) {
                        $spec = new $class($connection);
                        $spec->setRawValues($group)
                             ->deferPopulate();
                        $groups[$spec->getId()] = $spec;
                        $count++;
                    }
                    $group = array();
                }

                // defer to lazy load if FETCH_BY_MEMBER or FETCH_BY_USER option
                // was used as result data doesn't contain all the values
                if ($options[$class::FETCH_BY_MEMBER] || $options[$class::FETCH_BY_USER]) {
                    $group = array('Group' => $data['group']);
                    return LimitHandler::HANDLER_HANDLED;
                }

                // setup the group if we haven't already done so
                if (!$group) {
                    $group = array(
                        'Group'             => $data['group'],
                        'MaxResults'        => $class::normalizeMaxValue($data['maxResults']),
                        'MaxScanRows'       => $class::normalizeMaxValue($data['maxScanRows']),
                        'MaxLockTime'       => $class::normalizeMaxValue($data['maxLockTime']),
                        'Timeout'           => $class::normalizeMaxValue($data['timeout']),
                        'PasswordTimeout'   => $class::normalizeMaxValue($data['passTimeout']),
                        'Subgroups'         => array(),
                        'Owners'            => array(),
                        'Users'             => array()
                    );
                }

                // this data-block represents a user, owner and/or sub-group (can be multiple)
                if ($data['isSubGroup']) {
                    $group['Subgroups'][] = $data['user'];
                }
                if ($data['isOwner']) {
                    $group['Owners'][]    = $data['user'];
                }
                if ($data['isUser']) {
                    $group['Users'][]     = $data['user'];
                }

                return LimitHandler::HANDLER_HANDLED;
            }
        );

        $command = static::getFetchAllCommand();
        $flags   = static::getFetchAllFlags($options);
        $connection->runHandler($handler, $command, $flags);

        // handle the last group
        if ($group && (!$max || $count < $max) && (!$filter || $filter($group))) {
            $spec = new static($connection);
            $spec->setRawValues($group)
                 ->deferPopulate();
            $groups[$spec->getId()] = $spec;
        }

        return $groups;
    }

    /**
     * Save this spec to Perforce. Extend parent to throw if group is 'empty'
     *
     * @param   bool    $editAsOwner    save the group as a group owner
     * @param   bool    $addAsAdmin     pass -A to allow admin's to add.
     * @return  SpecAbstract            provides a fluent interface
     * @throws  Exception               if group is empty
     */
    public function save($editAsOwner = false, $addAsAdmin = false)
    {
        // check server version to see if addAsAdmin is supported
        if ($addAsAdmin && !$this->getConnection()->isServerMinVersion('2012.1')) {
            throw new Exception('Cannot add group as admin on server versions < 2012.1');
        }

        if ($this->isEmpty()) {
            throw new Exception("Cannot save. Group is empty.");
        }

        // ensure all required fields have values.
        $this->validateRequiredFields();

        $flags = array('-i');
        if ($editAsOwner) {
            $flags[] = '-a';
        }
        if ($addAsAdmin) {
            $flags[] = '-A';
        }

        $this->getConnection()->run(
            static::SPEC_TYPE,
            $flags,
            $this->getRawValues()
        );

        // should re-populate (server may change values).
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Remove this group from Perforce.
     * Extended to support the -a flag so that users can delete groups they own.
     *
     * @return  SpecAbstract    provides a fluent interface
     * @throws  Exception       if no id has been set.
     */
    public function delete()
    {
        return parent::delete($this->getConnection()->isSuperUser() ? null : array('-a'));
    }

    /**
     * Determine if the given group id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing group.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $groups = static::fetchAll(
            array(
                static::FETCH_BY_NAME => $id,
                static::FETCH_MAXIMUM => 1
            ),
            $connection
        );

        return (bool) count($groups);
    }

    /**
     * Determines if this group is 'empty'.
     *
     * A group is considered empty if no entries are present in:
     * -SubGroups
     * -Owners
     * -Users
     *
     * Values in Group (id), MaxResults, MaxScanRows, MaxLockTime do not
     * count towards 'emptiness'.
     *
     * @return  bool    True if group is empty, False otherwise
     */
    public function isEmpty()
    {
        $entries = count($this->get('Subgroups'))
                 + count($this->get('Owners'))
                 + count($this->get('Users'));

        return !(bool) $entries;
    }

    /**
     * The maximum number of results that members of this group can access
     * from the server from a single command. The default value is null.
     *
     * Will be an integer >0, null (if 'unset') or the string 'unlimited'
     *
     * @return  null|int|string     Null if unset, integer >0 or 'unlimited'
     */
    public function getMaxResults()
    {
        return $this->getMaxValue('MaxResults');
    }

    /**
     * Set the MaxResults for this group. See getMaxResults for more info.
     *
     * The string 'unset' may be passed in place of null for convienence.
     *
     * @param   null|int|string     $max    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group       provides fluent interface.
     */
    public function setMaxResults($max)
    {
        return $this->setMaxValue('MaxResults', $max);
    }

    /**
     * The maximum number of rows that members of this group can scan from
     * the server from a single command. The default value is null.
     *
     * Will be an integer >0, null (if 'unset') or the string 'unlimited'
     *
     * @return  null|int|string     Null if unset, integer >0 or 'unlimited'
     */
    public function getMaxScanRows()
    {
        return $this->getMaxValue('MaxScanRows');
    }

    /**
     * Set the MaxScanRows for this group. See getMaxScanRows for more info.
     *
     * The string 'unset' may be passed in place of null for convienence.
     *
     * @param   null|int|string     $max    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group       provides fluent interface.
     */
    public function setMaxScanRows($max)
    {
        return $this->setMaxValue('MaxScanRows', $max);
    }

    /**
     * The maximum length of time (in milliseconds) that any one operation can
     * lock any database table when scanning data. The default value is null.
     *
     * Will be an integer >0, null (if 'unset') or the string 'unlimited'
     *
     * @return  null|int|string     Null if unset, integer >0 or 'unlimited'
     */
    public function getMaxLockTime()
    {
        return $this->getMaxValue('MaxLockTime');
    }

    /**
     * Set the MaxLockTime for this group. See getMaxLockTime for more info.
     *
     * The string 'unset' may be passed in place of null for convienence.
     *
     * @param   null|int|string     $max    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group       provides fluent interface.
     */
    public function setMaxLockTime($max)
    {
        return $this->setMaxValue('MaxLockTime', $max);
    }

    /**
     * The duration (in seconds) of the validity of a session ticket created
     * by p4 login. The default value is 43200 seconds (12 hours).
     * For tickets that do not expire, will return 'unlimited'.
     *
     * Will be an integer >0, null (if 'unset') or the string 'unlimited'
     *
     * @return  null|int|string     Null if unset, integer >0 or 'unlimited'
     */
    public function getTimeout()
    {
        return $this->getMaxValue('Timeout');
    }

    /**
     * Set the Timeout for this group. See getTimeout for more info.
     *
     * The string 'unset' may be passed in place of null for convenience.
     *
     * @param   null|int|string     $timeout    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group       provides fluent interface.
     */
    public function setTimeout($timeout)
    {
        return $this->setMaxValue('Timeout', $timeout);
    }

    /**
     * The duration (in seconds) of the validity of a password (default is unset).
     * Will be an integer >0, null (if 'unset') or the string 'unlimited'
     *
     * @return  null|int|string     null if unset, integer >0 or 'unlimited'
     */
    public function getPasswordTimeout()
    {
        return $this->getMaxValue('PasswordTimeout');
    }

    /**
     * Set the PasswordTimeout for this group. See getPasswordTimeout for more info.
     *
     * The string 'unset' may be passed in place of null for convenience.
     *
     * @param   null|int|string     $timeout    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group               provides fluent interface.
     */
    public function setPasswordTimeout($timeout)
    {
        return $this->setMaxValue('PasswordTimeout', $timeout);
    }

    /**
     * Returns the sub-groups for this group.
     *
     * @return  array   subgroups belonging to this group
     */
    public function getSubgroups()
    {
        return $this->getRawValue('Subgroups') ?: array();
    }

    /**
     * Set the sub-groups for this group.
     * Expects an array containing group names or Group objects.
     *
     * @param   array   $subgroups  array of group names or Group objects
     * @return  Group       provides fluent interface.
     */
    public function setSubgroups($subgroups)
    {
        if (!is_array($subgroups)) {
            throw new \InvalidArgumentException(
                'Subgroups must be specified as an array.'
            );
        }

        foreach ($subgroups as &$group) {
            // normalize to strings
            if ($group instanceof Group) {
                $group = $group->getId();
            }

            if (!static::isValidId($group)) {
                throw new \InvalidArgumentException(
                    'Individual sub-groups must be a valid ID in either string or P4\Spec\Group format.'
                );
            }
        }

        return $this->setRawValue('Subgroups', $subgroups);
    }

    /**
     * Adds the passed group to the end of the current sub-groups.
     *
     * @param   string|Group    $group  new group to add
     * @return  Group           provides fluent interface.
     */
    public function addSubgroup($group)
    {
        $subgroups = $this->getSubgroups();
        $subgroups[] = $group;

        return $this->setSubgroups($subgroups);
    }

    /**
     * Returns the owners for this group.
     *
     * @return  array   owners belonging to this group
     */
    public function getOwners()
    {
        return $this->getRawValue('Owners') ?: array();
    }

    /**
     * Set the owners for this group.
     * Expects an array containing user names or User objects.
     *
     * @param   array   $owners     array of user names or User objects
     * @return  Group   provides fluent interface.
     */
    public function setOwners($owners)
    {
        if (!is_array($owners)) {
            throw new \InvalidArgumentException(
                'Owners must be specified as an array.'
            );
        }

        foreach ($owners as &$owner) {
            // normalize to strings
            if ($owner instanceof User) {
                $owner = $owner->getId();
            }

            if (!static::isValidUserId($owner)) {
                throw new \InvalidArgumentException(
                    'Individual owners must be a valid ID in either string or P4\Spec\User format.'
                );
            }
        }

        return $this->setRawValue('Owners', $owners);
    }

    /**
     * Adds the passed owner to the end of the current owners.
     *
     * @param   string|User     $owner  new owner to add
     * @return  Group           provides fluent interface.
     */
    public function addOwner($owner)
    {
        $owners   = $this->getOwners();
        $owners[] = $owner;

        return $this->setOwners($owners);
    }

    /**
     * Returns the users for this group.
     *
     * @return  array   users belonging to this group
     */
    public function getUsers()
    {
        return $this->getRawValue('Users') ?: array();
    }

    /**
     * Set the users for this group.
     * Expects an array containing user names or User objects.
     *
     * @param   array   $users  array of user names or User objects
     * @return  Group   provides fluent interface.
     */
    public function setUsers($users)
    {
        if (!is_array($users)) {
            throw new \InvalidArgumentException(
                'Users must be specified as an array.'
            );
        }

        foreach ($users as &$user) {
            // normalize to strings
            if ($user instanceof User) {
                $user = $user->getId();
            }

            if (!static::isValidUserId($user)) {
                throw new \InvalidArgumentException(
                    'Individual users must be a valid ID in either string or P4\Spec\User format.'
                );
            }
        }

        return $this->setRawValue('Users', $users);
    }

    /**
     * Adds the passed user to the end of the current users.
     *
     * @param   string|User     $user   new user to add
     * @return  Group           provides fluent interface.
     */
    public function addUser($user)
    {
        $users   = $this->getUsers();
        $users[] = $user;

        return $this->setUsers($users);
    }

    /**
     * Normalize 'max' style field to convert null/0 to 'unset' and -1 to 'unlimited'.
     *
     * @param   mixed   $max    the value to attempt normalization on
     * @return  mixed   the normalized value if it was null
     */
    public static function normalizeMaxValue($max)
    {
        if ($max === null || $max === 0 || $max === '0') {
            return 'unset';
        }
        if ($max === -1 || $max === '-1') {
            return 'unlimited';
        }

        // numbers from perforce come back as strings, make them ints
        if ($max == (string)(int)$max) {
            return (int)$max;
        }

        return $max;
    }

    /**
     * Get the value for a 'max' style field
     * (one of MaxResults, MaxScanRows, MaxLockTime and Timeout).
     *
     * @param   string          $field  Name of the field to get the value from
     * @return  null|int|string null (if 'unset'), integer >0 or 'unlimited'
     */
    protected function getMaxValue($field)
    {
        $max = $this->getRawValue($field);

        // translate the string 'unset' to null
        if ($max === 'unset') {
            return null;
        }

        // integers come back from perforce as strings
        // casting to an int, then back to a string screens out non-digit
        // characters and allows for a 'pure digit' check.
        if ($max == (string)(int)$max) {
            return (int)$max;
        }

        return $max;
    }

    /**
     * Check if the given id is in a valid format for group specs.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\GroupName;
        return $validator->isValid($id);
    }

    /**
     * Check if the given id is in a valid format for user specs.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidUserId($id)
    {
        $validator = new Validate\UserName;
        return $validator->isValid($id);
    }

    /**
     * Set the value for a 'max' style field
     * (one of MaxResults, MaxScanRows, MaxLockTime, Timeout and PasswordTimeout).
     *
     * Valid 'max' inputs are:
     * -null and 0, get converted to 'unset'
     * -negative 1, gets converted to 'unlimited'
     * -the string 'unset'
     * -an integer greater than 0
     * -the string 'unlimited'
     *
     * @param   string          $field  Name of the field to set value on
     * @param   null|int|string $max    null (or 'unset'), integer >0 or 'unlimited'
     * @return  Group       provides a fluent interface
     * @throws  \InvalidArgumentException   If input is of incorrect type of format
     */
    protected function setMaxValue($field, $max)
    {
        // ensure input is in the ballpark
        if (!is_null($max) && !is_int($max) && !is_string($max)) {
            throw new \InvalidArgumentException(
                "Type of input must be one of: null, int, string"
            );
        }

        // handle null, 0 and -1
        $max = static::normalizeMaxValue($max);

        // verify string format input matches expected value
        if (is_string($max) && $max !== 'unlimited' && $max !== 'unset') {
            throw new \InvalidArgumentException(
                "For string input, only the values 'unlimited' and 'unset' are valid."
            );
        }

        // ensure integer input is greater than zero
        if (is_int($max) && $max <= 0) {
            throw new \InvalidArgumentException(
                "For integer input, only values greater than zero are valid."
            );
        }

        return $this->setRawValue($field, $max);
    }

    /**
     * Produce set of flags for the spec list command, given fetch all options array.
     * Extends parent to add support for filter option.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        // clear FETCH_MAXIMUM if present as we handle it manually
        unset($options[static::FETCH_MAXIMUM]);

        $flags = parent::getFetchAllFlags($options);

        if (isset($options[static::FETCH_BY_NAME])) {
            $name = $options[static::FETCH_BY_NAME];

            if (!static::isValidId($name) && !static::isValidUserId($name)) {
                throw new \InvalidArgumentException(
                    'Filter by Name expects a valid group id.'
                );
            }

            if (isset($options[static::FETCH_INDIRECT]) ||
                isset($options[static::FETCH_BY_MEMBER])
            ) {
                throw new \InvalidArgumentException(
                    'Filter by Name is not compatible with Fetch by Member or Fetch Indirect.'
                );
            }

            $flags[] = '-v';
            $flags[] = $name;
        }

        if (isset($options[static::FETCH_BY_MEMBER], $options[static::FETCH_BY_USER])) {
            throw new \InvalidArgumentException(
                'You cannot specify both fetch by user and fetch by member.'
            );
        }

        if (isset($options[static::FETCH_INDIRECT])
            && (isset($options[static::FETCH_BY_MEMBER]) || isset($options[static::FETCH_BY_USER]))
        ) {
            $flags[] = '-i';
        }

        if (isset($options[static::FETCH_BY_USER])) {
            $user = $options[static::FETCH_BY_USER];

            if (!static::isValidUserId($user)) {
                throw new \InvalidArgumentException(
                    'Filter by User expects a valid username.'
                );
            }

            $flags[] = '-u';
            $flags[] = $user;
        }

        if (isset($options[static::FETCH_BY_MEMBER])) {
            $member = $options[static::FETCH_BY_MEMBER];

            if (!static::isValidId($member) && !static::isValidUserId($member)) {
                throw new \InvalidArgumentException(
                    'Filter by Member expects a valid group or username.'
                );
            }

            $flags[] = $member;
        }

        return $flags;
    }

    /**
     * This function is not utilized by Group as our result format is incompatible.
     * Any attempt to call this function results in an exception.
     *
     * @param   array                       $listEntry      a single spec entry from spec list output.
     * @param   array                       $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface         $connection     a specific connection to use.
     * @throws  \BadFunctionCallException   On any use of this function in this class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        throw new \BadFunctionCallException(
            'From Spec List Entry is not implemented in the P4\Spec\Group class.'
        );
    }
}
