<?php
/**
 * Abstracts operations against Perforce users.
 *
 * The P4 User class differs from the 'user' spec definition in that it
 * does not have a password field. This is because the password does
 * not behave like other fields. To change a user's password, use the
 * setPassword() function. To test if a given string matches a user's
 * password, use the isPassword() method. It is not possible to get a
 * user's password.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\Connection;
use P4\Spec\Exception\Exception;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\LoginException;
use P4\Model\Fielded\Iterator as FieldedIterator;

class User extends PluralAbstract
{
    const SPEC_TYPE         = 'user';
    const ID_FIELD          = 'User';

    const OPERATOR_USER     = 'operator';
    const SERVICE_USER      = 'service';
    const STANDARD_USER     = 'standard';

    const FETCH_BY_NAME     = 'name';

    protected $fields       = array(
        'Email'         => array(
            'accessor'  => 'getEmail',
            'mutator'   => 'setEmail'
        ),
        'Update'        => array(
            'accessor'  => 'getUpdateDateTime'
        ),
        'Access'        => array(
            'accessor'  => 'getAccessDateTime'
        ),
        'FullName'      => array(
            'accessor'  => 'getFullName',
            'mutator'   => 'setFullName'
        ),
        'JobView'       => array(
            'accessor'  => 'getJobView',
            'mutator'   => 'setJobView'
        ),
        'Reviews'       => array(
            'accessor'  => 'getReviews',
            'mutator'   => 'setReviews'
        ),
        'Password'      => array(
            'accessor'  => 'getPassword',
            'mutator'   => 'setPassword'
        ),
        'Type'          => array(
            'accessor'  => 'getType',
            'mutator'   => 'setType'
        )
    );

    /**
     * Determine if the given user id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing user.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $users = static::fetchAll(
            array(
                static::FETCH_BY_NAME => $id,
                static::FETCH_MAXIMUM => 1
            ),
            $connection
        );

        return (bool) count($users);
    }

    /**
     * Get all users from Perforce. Adds filtering option.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                  FETCH_MAXIMUM - set to integer value to limit to the
     *                                                  first 'max' number of entries.
     *                                  FETCH_BY_NAME - set to user name pattern (e.g. 'jdo*'),
     *                                                  can be a single string or array of strings.
     *
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  FieldedIterator         all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();

        // if not fetching by name, defer to parent
        if (!isset($options[static::FETCH_BY_NAME])) {
            return parent::fetchAll($options, $connection);
        }

        // get fetch max option and uset it from the options as we handle it manually
        $max = isset($options[static::FETCH_MAXIMUM]) ? $options[static::FETCH_MAXIMUM] : 0;
        unset($options[static::FETCH_MAXIMUM]);

        // sort names before fetching users from the server, so if max is set
        // we get the first max users (according to case-sensitivity of server)
        $names = (array) $options[static::FETCH_BY_NAME];
        if ($connection->isCaseSensitive()) {
            sort($names);
        } else {
            usort($names, 'strcasecmp');
        }

        // fetch users in several (as few as possible) runs as
        // there is a potential to exceed the arg-max on this command
        $users = new FieldedIterator;
        foreach ($connection->batchArgs($names) as $batch) {
            $options[static::FETCH_BY_NAME] = $batch;
            foreach (parent::fetchAll($options, $connection) as $user) {
                $users[$user->getId()] = $user;

                // exit loop if we've reached the max limit
                if ($max && $users->count() == $max) {
                    break(2);
                }
            }
        }

        return $users;
    }

    /**
     * Save this user to Perforce. This will not save changes to the
     * password field. Passwords must be set via setPassword().
     *
     * @return  SpecAbstract    provides a fluent interface
     * @throws  Exception       if no id has been set.
     */
    public function save()
    {
        // ensure all required fields have values.
        $this->validateRequiredFields();

        // set 'password' field to '******' otherwise password
        // will be deleted under certain security levels.
        $values = $this->getRawValues();
        $values['Password'] = '******';

        // initialize command flags with first arg.
        $flags = array("-i");

        // if we are connected with super user privileges, add in the -f flag.
        // otherwise, if not connected as this user, connect as this user.
        $connection = $this->getConnection();
        if ($connection->isSuperUser()) {
            $flags[] = "-f";
        } elseif ($connection->getUser() != $this->getId()) {
            $connection = Connection::factory(
                $connection->getPort(),
                $this->getId()
            );
        }

        // send user spec to server.
        $password = $this->getRawValue('Password');
        try {
            $connection->run(static::SPEC_TYPE, $flags, $values);
        } catch (CommandException $e) {

            // if saving user failed because password has not been
            // set, and the caller supplied a password, try setting
            // the password first, then saving user again.
            //
            // @todo This workaround relies on the fact, that user has been created although previous
            // command had failed. At the moment it seems, that when adding a new user (with non-superuser
            // connection) this error cannot be avoided.
            $errors = $e->getResult()->getErrors();
            if (stristr($errors[0], "password must be set") && is_array($password) && isset($password[0])) {
                $this->changePassword($password[0], null, $connection);
                $connection->run(static::SPEC_TYPE, $flags, $values);
                // avoid redundant password change
                $password[0] = null;
            } else {
                throw $e;
            }
        }

        // change the users password if they have set a new one.
        if (is_array($password) && $password[0] !== null) {
            $this->changePassword($password[0], $password[1], $connection);
        }

        // should re-populate (server may change values).
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Remove this user from Perforce.
     *
     * @return  SpecAbstract    provides a fluent interface
     * @throws  Exception       if no id has been set.
     */
    public function delete()
    {
        if ($this->getId() === null) {
            throw new Exception("Cannot delete. No id has been set.");
        }

        // initialize command flags with first arg.
        $flags = array("-d");

        // if we are connected with super user privileges, add in the -f flag.
        // otherwise, if not connected as this user, connect as this user.
        $connection = $this->getConnection();
        if ($connection->isSuperUser()) {
            $flags[] = "-f";
        } elseif ($connection->getUser() != $this->getId()) {
            $connection = Connection::factory(
                $connection->getPort(),
                $this->getId()
            );
        }

        // issue delete user command.
        $flags[] = $this->getId();
        $result = $connection->run(static::SPEC_TYPE, $flags);

        // should re-populate.
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Get the in-memory password (if one is set).
     *
     * @return  string|null the in-memory password.
     */
    public function getPassword()
    {
        $password = $this->getRawValue('Password');
        return is_array($password) ? $password[0] : null;
    }

    /**
     * Set the user's password to the given password.
     * Does not take effect until save() is called.
     *
     * @param   string|null     $newPassword    the new password string or
     *                                          null to clear in-memory password.
     * @param   string          $oldPassword    optional - existing password.
     * @return  User            provides fluent interface.
     */
    public function setPassword($newPassword, $oldPassword = null)
    {
        $this->setRawValue('Password', array($newPassword, $oldPassword));

        return $this;
    }

    /**
     * Test if the given password is correct for this user.
     *
     * @param   string  $password   the password to test.
     * @return  bool    true if the password is correct, false otherwise.
     */
    public function isPassword($password)
    {
        $p4 = Connection::factory(
            $this->getConnection()->getPort(),
            $this->getId(),
            null,
            $password
        );

        try {
            $p4->login();
            return true;
        } catch (LoginException $e) {
            return false;
        }
    }

    /**
     * Get the type for this account. Expected to be one of 'service', 'operator' or 'standard'.
     *
     * @return  the 'type' of this user, by default 'standard'
     */
    public function getType()
    {
        return $this->hasField('Type') && $this->getRawValue('Type')
            ? $this->getRawValue('Type')
            : static::STANDARD_USER;
    }

    /**
     * Set the user's type.
     *
     * @param   string|null $type   the type, expected to be one of 'service', 'operator' or 'standard'.
     * @return  User        provides fluent interface.
     * @throws  \InvalidArgumentException   if type field doesn't exist and a value other than null/standard is passed
     */
    public function setType($type)
    {
        $type = $type ?: 'standard';
        if (!$this->hasField('Type') && $type != static::STANDARD_USER) {
            throw new \InvalidArgumentException(
                'The user spec lacks a Type field, setting to a value other than null or standard is not supported'
            );
        }

        return $this->setRawValue('Type', $type);
    }

    /**
     * Get an Iterator of all the Clients this user owns.
     *
     * @return  FieldedIterator     Iterator of Clients owned by current user
     * @throws  Exception           If no ID is set for this user
     */
    public function getClients()
    {
        if (!static::isValidId($this->getId())) {
            throw new Exception("Cannot get clients. No user id has been set.");
        }

        return Client::fetchAll(
            array(Client::FETCH_BY_OWNER => $this->getId()),
            $this->getConnection()
        );
    }

    /**
     * Get the names of groups that this user belongs to.
     *
     * @return  FieldedIterator     Iterator of Groups this user belongs to.
     */
    public function getGroups()
    {
        if (!static::isValidId($this->getId())) {
            throw new Exception("Cannot get groups. No user id has been set.");
        }

        return Group::fetchAll(
            array(Group::FETCH_BY_USER => $this->getId(), Group::FETCH_INDIRECT),
            $this->getConnection()
        );
    }

    /**
     * Add this user to the named group.
     *
     * @param   string  $group  the name of the group to add the user to.
     * @return  User    provides fluent interface.
     */
    public function addToGroup($group)
    {
        $group = Group::fetch($group, $this->getConnection())
            ->addUser($this->getId())
            ->save();

        return $this;
    }

    /**
     * Get the user's full name.
     *
     * @return  string|null the user's full name.
     */
    public function getFullName()
    {
        return $this->getRawValue('FullName');
    }

    /**
     * Set the user's full name.
     *
     * @param   string|null $name   the full name to give the user.
     * @return  User    provides fluent interface.
     * @throws  \InvalidArgumentException   if given name is not a string.
     */
    public function setFullName($name)
    {
        if ($name !== null && !is_string($name)) {
            throw new \InvalidArgumentException("Cannot set full name. Invalid type given.");
        }
        return $this->setRawValue('FullName', $name);
    }

    /**
     * Get the user's email address.
     *
     * @return  string|null the user's email address.
     */
    public function getEmail()
    {
        return $this->getRawValue('Email');
    }

    /**
     * Set the user's email address. We don't require a valid email
     * address here because Perforce doesn't enforce one. If we did
     * then users with invalid emails would be innaccessible.
     *
     * @param   string|null $email  the email of the user.
     * @return  User    provides fluent interface.
     * @throws  \InvalidArgumentException   if given email is not a string.
     */
    public function setEmail($email)
    {
        if ($email !== null && !is_string($email)) {
            throw new \InvalidArgumentException("Cannot set email. Invalid type given.");
        }
        return $this->setRawValue("Email", $email);
    }

    /**
     * Get the user's job view (selects jobs for inclusion during changelist creation).
     *
     * @return  string|null the user's job view.
     */
    public function getJobView()
    {
        return $this->getRawValue('JobView');
    }

    /**
     * Set the user's job view (selects jobs for inclusion during changelist creation).
     *
     * @param   string|null $jobView    the user's job view.
     * @return  User    provides fluent interface.
     * @throws  \InvalidArgumentException   if given job view is not a string.
     */
    public function setJobView($jobView)
    {
        if ($jobView !== null && !is_string($jobView)) {
            throw new \InvalidArgumentException("Cannot set job view. Invalid type given.");
        }
        return $this->setRawValue("JobView", $jobView);
    }


    /**
     * Get the reviews for this client (depot paths to notify user of changes to).
     *
     * @return  array   list of filespec strings.
     */
    public function getReviews()
    {
        return $this->getRawValue('Reviews') ?: array();
    }

    /**
     * Set the reviews for this user (depot paths to notify user of changes to).
     * Reviews is passed as an array of filespec strings.
     *
     * @param   array   $reviews    Review entries - an array of filespec strings.
     * @return  User    provides a fluent interface.
     * @throws  \InvalidArgumentException   if reviews is not an array.
     */
    public function setReviews($reviews)
    {
        if (!is_array($reviews)) {
            throw new \InvalidArgumentException('Reviews must be passed as array.');
        }

        return $this->setRawValue('Reviews', $reviews);
    }

    /**
     * Get the last update time for this user spec.
     * This value is read only, no setUpdateTime function is provided.
     *
     * If this is a brand new spec, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last update, formatted "2009/11/23 12:57:06" or null
     */
    public function getUpdateDateTime()
    {
        return $this->getRawValue('Update');
    }

    /**
     * Get the last access time for this user spec.
     * This value is read only, no setAccessTime function is provided.
     *
     * If this is a brand new spec, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last access, formatted "2009/11/23 12:57:06" or null
     */
    public function getAccessDateTime()
    {
        return $this->getRawValue('Access');
    }

    /**
     * Check if automatic user creation is enabled.
     *
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool                        true if auto user creation is enabled, false otherwise.
     * @throws  Exception                   if we exceed the maximum number of unlikely usernames
     */
    public static function isAutoUserCreationEnabled(ConnectionInterface $connection = null)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        $port = $connection->getPort();

        // limit the number of 'unlikely' username lookups to 3.
        $maxLookups = 3;
        for ($i = 0; $i < $maxLookups; $i++) {
            // generate an unlikely user name.
            $username = md5(mt_rand());

            // try to run p4 users as the unlikely user
            // (perforce won't create an account for this lookup).
            try {
                $connection = Connection::factory($port, $username);
                $result = $connection->run('users', $username);
            } catch (CommandException $e) {
                return false;
            }

            // ensure unlikely user doesn't exist.
            if (!$result->getData()) {
                return true;
            }
        }

        throw new \Exception(
            "Failed to determine if auto user creation is enabled."
            . "Exceeded the maximum of $maxLookups 'unlikely' username lookups."
        );
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
        $flags = parent::getFetchAllFlags($options);

        // ensure we include service/operator users
        $flags[] = '-a';

        if (isset($options[static::FETCH_BY_NAME])) {
            $name = $options[static::FETCH_BY_NAME];

            if ((!is_array($name) || !count($name)) && (!is_string($name) || trim($name) === "")) {
                throw new \InvalidArgumentException(
                    'Filter by Name expects a non-empty string or an non-empty array as input'
                );
            }

            // if array is given, ensure values are non-empty strings
            if (is_array($name)) {
                $names    = $name;
                $filtered = array_filter($names, 'is_string');
                $filtered = array_filter($filtered, 'trim');

                if (count($names) !== count($filtered)) {
                    throw new \InvalidArgumentException(
                        'Filter by Name expects all names in the input array to be non-empty strings'
                    );
                }
                $flags = array_merge($flags, $names);
            } else {
                $flags[] = $name;
            }
        }

        return $flags;
    }

    /**
     * Check if the given id is in a valid format for user specs.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\UserName;
        return $validator->isValid($id);
    }

    /**
     * Immediately set the user's password to the given password.
     * If the current password is given, it will be validated.
     *
     * @param   string                      $newPassword    the new password.
     * @param   string                      $oldPassword    optional - existing password.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  User                        provides fluent interface.
     * @throws  Exception                   if the password can't be set.
     */
    protected function changePassword(
        $newPassword,
        $oldPassword = null,
        ConnectionInterface $connection = null
    ) {
        $input = array();

        // if caller supplied an old password, prepend it to input array.
        if ($oldPassword) {
            $input[] = $oldPassword;
        }

        // always confirm old password
        $input[] = $newPassword;
        $input[] = $newPassword;

        // if no connection given, use default.
        $connection = $connection ?: $this->getConnection();

        // if not connected as this user, supply user id.
        $flags = array();
        if ($connection->getUser() !== $this->getId()) {
            $flags[] = $this->getId();
        }

        // attempt to set password.
        $result = $connection->run("password", $flags, $input);

        // change connection credentials if password for connected user has been changed
        // if we don't do this automatically, subsequent commands will fail when using
        // the command-line connection, but would succeed using the P4PHP extension.
        if ($connection->getUser() === $this->getId()) {
            $connection->setPassword($newPassword);
            if ($connection->getTicket()) {
                $connection->login($connection->isTicketUnlocked());
            }
        }

        return $this;
    }
}
