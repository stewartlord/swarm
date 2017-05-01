<?php
/**
 * Abstracts operations against Perforce changes.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Validate;
use P4\Spec\Job;
use P4\File\File;
use P4\File\Query as FileQuery;
use P4\Exception as P4Exception;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\ConflictException;
use P4\Model\Resolvable\ResolvableInterface;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\Exception;
use P4\Spec\Exception\UnopenedException;
use P4\Spec\Exception\NotFoundException;

class Change extends PluralAbstract implements ResolvableInterface
{
    const SPEC_TYPE             = 'change';
    const ID_FIELD              = 'Change';

    const DEFAULT_CHANGE        = 'default';
    const PENDING_CHANGE        = 'pending';
    const SHELVED_CHANGE        = 'shelved';
    const SUBMITTED_CHANGE      = 'submitted';

    const PUBLIC_CHANGE         = 'public';
    const RESTRICTED_CHANGE     = 'restricted';

    const FETCH_BY_FILESPEC     = 'filespec';
    const FETCH_BY_IDS          = 'ids';
    const FETCH_BY_STATUS       = 'status';
    const FETCH_INTEGRATED      = 'integrated';
    const FETCH_BY_CLIENT       = 'client';
    const FETCH_BY_USER         = 'user';

    const RESOLVE_FILE          = 'file';

    const MAX_SUBMIT_ATTEMPTS   = 3;

    protected $cache            = array();
    protected $fixStatus        = null;
    protected $fields           = array(
        'Date'          => array(
            'accessor'  => 'getDate'
        ),
        'User'          => array(
            'accessor'  => 'getUser',
            'mutator'   => 'setUser'
        ),
        'Client'        => array(
            'accessor'  => 'getClient',
            'mutator'   => 'setClient'
        ),
        'Status'        => array(
            'accessor'  => 'getStatus'
        ),
        'Description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'JobStatus'     => array(
            'accessor'  => 'getJobStatus',
            'mutator'   => 'setJobStatus'
        ),
        'Jobs'          => array(
            'accessor'  => 'getJobs',
            'mutator'   => 'setJobs'
        ),
        'Type'          => array(
            'accessor'  => 'getType',
            'mutator'   => 'setType'
        ),
        'Files'         => array(
            'accessor'  => 'getFiles',
            'mutator'   => 'setFiles'
        )
    );

    /**
     * Get the number of this change.
     * Extends parent to return an integer value for numbered changes.
     *
     * @return  null|string|int     the number of the change, the literal string 'default'
     *                              or null if no id has been set.
     */
    public function getId()
    {
        $id = parent::getId();
        if ($id !== null && $id !== static::DEFAULT_CHANGE) {
            $id = intval($id);
        }
        return $id;
    }

    /**
     * Set the id of this spec entry. Id must be in a valid format or null.
     * Extended from parent to clear cache.
     *
     * @param   null|string     $id     the id of this entry - pass null to clear.
     * @return  PluralAbstract          provides a fluent interface
     * @throws  \InvalidArgumentException   if id does not pass validation.
     */
    public function setId($id)
    {
        if ($this->getId() !== $id) {
            $this->cache = array();
        }

        return parent::setId($id);
    }

    /**
     * Determine if the given change id exists.
     *
     * @param   string                   $id          the id to check for.
     * @param   ConnectionInterface      $connection  optional - a specific connection to use.
     * @return  bool  true if the given id matches an existing change.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // default change always exists.
        if ($id === static::DEFAULT_CHANGE) {
            return true;
        }

        // attempt to fetch change - check message on failure.
        try {
            $connection->run('change', array('-o', $id));
            return true;
        } catch (P4Exception $e) {
            if (preg_match('/Change [0-9]+ unknown/', $e->getMessage())) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Get the requested change entry from Perforce.
     *
     * @param   string                      $id         the id of the change to fetch.
     * @param   ConnectionInterface         $connection optional - a specific connection to use.
     * @return  Change                      instance of the requested change.
     * @throws  \InvalidArgumentException   if no id is given.
     * @throws  Spec\NotFoundException      if no such change exists.
     */
    public static function fetch($id, ConnectionInterface $connection = null)
    {
        // ensure a valid id is provided.
        if (!static::isValidId($id)) {
            throw new \InvalidArgumentException("Must supply a valid id to fetch.");
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // attempt to fetch change - check message on failure.
        try {
            // for numbered changes we run '-o <id>'. for servers running
            // 2012.1+ we include -O to allow locating renamed changes.
            // for the default change simply run '-o'.
            $flags = array();
            if ($id != static::DEFAULT_CHANGE) {
                $flags[] = $connection->isServerMinVersion('2012.1') ? '-Oo' : '-o';
                $flags[] = $id;
            } else {
                $flags[] = '-o';
            }

            $result  = $connection->run('change', $flags)->expandSequences();
            $spec    = new static($connection);

            // if we fetched the default change ensure we have the id default.
            // the id would otherwise be 'new'.
            $data = $result->getData(-1);
            if ($id == static::DEFAULT_CHANGE) {
                $data['Change'] = $id;
            }

            // if we don't get any jobs back, that means the change doesn't have any
            // merge in an empty jobs array to avoid a needless populate call later
            $spec->setRawValues($data + array('Jobs' => array()))
                 ->deferPopulate();
        } catch (P4Exception $e) {
            if (preg_match('/Change [0-9]+ unknown|Invalid changelist number/', $e->getMessage())) {
                throw new NotFoundException(
                    "Cannot fetch " . static::SPEC_TYPE . " $id. Record does not exist."
                );
            }
            throw $e;
        }

        return $spec;
    }

    /**
     * Get all changes from Perforce. Adds filtering options.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                   FETCH_MAXIMUM - set to integer value to limit to the
     *                                                   first 'max' number of entries.
     *                               FETCH_BY_FILESPEC - set to a filespec to limit changes to those
     *                                                   affecting the file(s) matching the filespec.
     *                                    FETCH_BY_IDS - set to an array of change IDs to limit results.
     *                                 FETCH_BY_STATUS - set to a valid change status to limit result
     *                                                   to changes with that status (e.g. 'pending').
     *                                FETCH_INTEGRATED - set to true to include changes integrated
     *                                                   into the specified files.
     *                                 FETCH_BY_CLIENT - set to a client to limit changes to those
     *                                                   on the named client.
     *                                   FETCH_BY_USER - set to a user to limit changes to those
     *                                                   owned by the named user.
     *
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  FieldedIterator         all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // if fetch by ids was passed by is an empty array just return an empty result
        // otherwise the caller would actually get all changes back erroneously.
        $options += array(static::FETCH_BY_IDS => null);
        $ids      = $options[static::FETCH_BY_IDS];
        if (is_array($ids) && !count($ids)) {
            return new FieldedIterator;
        }

        // simply return parent - method exists to document options.
        return parent::fetchAll($options, $connection);
    }

    /**
     * Get all of the required fields.
     * Extends parent to remove 'Description' if present as, despite
     * being listed as required in the spec, it isn't required.
     *
     * @return  array   a list of required fields for this spec.
     */
    public function getRequiredFields()
    {
        $fields = parent::getRequiredFields();
        unset($fields[array_search('Description', $fields)]);

        return $fields;
    }

    /**
     * Extend parent to set id to 'new' if unset and to reopen files that
     * are open in other pending changelists where necessary.
     *
     * @param   bool        $force      optional - default false - true to save submitted change.
     * @return  Change      provides a fluent interface
     * @throws  UnopenedException       if change contains unopened files.
     * @throws  CommandException        if save command fails for some reason.
     */
    public function save($force = false)
    {
        $values = $this->getRawValues();
        if (!isset($values[static::ID_FIELD]) || $this->isDefault()) {
            $values[static::ID_FIELD] = "new";
        }

        // ensure all required fields have values.
        $this->validateRequiredFields($values);

        // can't update a submitted change without the force option.
        if (!$force && $this->isSubmitted()) {
            throw new Exception(
                "Cannot update a submitted change without the force option."
            );
        }

        // don't attempt to set files on submitted changes
        if ($this->isSubmitted()) {
            unset($values['Files']);
        }

        // extend the list of jobs to include the fix status if fixStatus is set
        if ($this->fixStatus && isset($values['Jobs'])) {
            foreach ($values['Jobs'] as &$value) {
                $value .= ' ' . $this->fixStatus;
            }
        }

        // perform save.
        $connection = $this->getConnection();
        try {

            $flags = array("-i");
            if ($this->fixStatus) {
                $flags[] = "-s";
            }
            if ($force) {
                $flags[] = $connection->isAdminUser(true) ? "-f" : "-u";
            }
            $result = $connection->run(static::SPEC_TYPE, $flags, $values);

            // extract change number from last command result.
            $matches = false;
            foreach ($result->getData() as $data) {
                if (preg_match('/^Change ([^ ]+) (created|updated)/', $data, $matches)) {
                    break;
                }
            }

            if (!$matches) {
                throw new Exception('Cannot determine number of saved change.');
            }

            $id = $matches[1];

        } catch (CommandException $e) {

            // if the exception was caused by non-existent jobs, the change should
            // have been created.
            if (preg_match(
                "/Change ([^ ]+) (created|updated).*Job '([^']+)' doesn't exist\./s",
                $e->getMessage(),
                $matches
            )) {
                $this->setId($matches[1]);
                throw $e;
            }

            // if exception not caused by un-opened files, re-throw.
            if (strpos($e->getMessage(), "Can't include file(s) not already opened.") === false) {
                throw $e;
            }

            // if any files are truly un-opened throw a special un-opened files exception.
            // (save will complain of un-opened files if files are not in default change)
            $flags  = array("-Ro", "-T", "depotFile");
            $flags  = array_merge($flags, $values['Files']);
            $result = $connection->run("fstat", $flags);
            if ($result->hasWarnings()) {
                throw new UnopenedException(
                    "Cannot save change. One or more files are not open."
                );
            }

            // all files are actually open, save w.out files first, then reopen.
            $change = clone $this;
            $id     = $change->setFiles(null)->save()->getId();
            $flags  = $values['Files'];
            array_unshift($flags, "-c", $id);
            $connection->run("reopen", $flags);

        }

        // Store the retrieved id.
        $this->setId($id);

        // should re-populate (server may change values).
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Save and submit this changelist.
     *
     * @param   string              $description    optional - a description of this change.
     * @param   null|string|array   $options        optional resolve flags, to be used if conflict
     *                                              occurs. See resolve() for details.
     * @return  Change              provides fluent interface.
     * @throws  Exception           if the change is not a pending change.
     * @throws  ConflictException   if change contains files requiring resolve.
     */
    public function submit($description = null, $options = null)
    {
        // ensure change is a pending change.
        if (!$this->isPending()) {
            throw new Exception("Can only submit pending changes.");
        }

        // if description is given, use it.
        if (strlen($description)) {
            $this->setDescription($description);
        }

        // save the change before submit.
        $this->save();

        // try repeatedly to submit (with resolves in-between attempts)
        // note: no need to explicitly sync as submit schedules resolve
        for ($i = static::MAX_SUBMIT_ATTEMPTS; $i > 0; $i--) {
            try {
                $result = $this->getConnection()->run("submit", array("-c", $this->getId()));

                // everything went ok no need to retry.
                break;
            } catch (ConflictException $e) {
                // if there are no resolve options or we have exceeded
                // max resolve attempts; re-throw the resolve exception
                if ($i <= 1 || empty($options)) {
                    throw $e;
                }

                // our id might have changed - update our id field to match
                // note we avoid setId() as it can cause a populate()
                $this->values[static::ID_FIELD] = $e->getChange()->getId();

                $this->resolve($options);
            }
        }

        // extract change number, it could have noisy trigger output prior and/or
        // after the actual data so just scan till we find it.
        // note we avoid setId() as it can cause a populate()
        foreach ($result->getData() as $data) {
            if (is_array($data) && isset($data['submittedChange'])) {
                break;
            }
        }
        $this->values[static::ID_FIELD] = $data['submittedChange'];

        // we cache files and things - clear that out
        $this->cache = array();

        return $this;
    }

    /**
     * Revert all of the files in this changelist.
     *
     * @return  Change      provides fluent interface.
     * @throws  Exception   if the change is not a pending change.
     */
    public function revert()
    {
        // ensure change is a pending change.
        if (!$this->isPending()) {
            throw new Exception("Can only revert pending changes.");
        }

        // save the change before revert (updates files in change).
        $this->save();

        // perform revert.
        $result = $this->getConnection()->run(
            "revert",
            array("-c", $this->getId(), '//...')
        );

        // should re-populate.
        $this->deferPopulate(true);

        return $this;
    }


    /**
     * Delete this changelist.
     *
     * @param   bool        $force  optional - defaults to false - set to true to force delete of
     *                              another user/client's changelist or a submitted (empty) change.
     * @return  Change      provides fluent interface.
     */
    public function delete($force = false)
    {
        $id = $this->getId();
        if ($id === null) {
            throw new Exception("Cannot delete change. No id has been set.");
        }

        // default change cannot be deleted.
        if ($id === Change::DEFAULT_CHANGE) {
            throw new Exception("Cannot delete the default change.");
        }

        // ensure id exists.
        $connection = $this->getConnection();
        if (!static::exists($id, $connection)) {
            throw new NotFoundException(
                "Cannot delete change $id. Record does not exist."
            );
        }

        // unknown or unhandled change status (e.g. 'shelved').
        if (!$this->isPending() && !$this->isSubmitted()) {
            throw new Exception(
                "Unable to delete change with status '" . $this->getStatus() . "'."
            );
        }

        // handle submitted changes.
        $connection = $this->getConnection();
        if ($this->isSubmitted()) {

            // requires force option.
            if (!$force) {
                throw new Exception(
                    "Cannot delete a submitted change without the force option."
                );
            }

            // check for files.
            if (count($this->getFiles())) {
                throw new Exception(
                    "Cannot delete a submitted change that contains files."
                );
            }

            $result = $connection->run("change", array("-d", "-f", $id));
        }

        // handle pending changes (must remove files and fixes first).
        if ($this->isPending()) {
            if (!$force &&
                ($this->getUser()   !== $connection->getUser() ||
                 $this->getClient() !== $connection->getClient())) {
                throw new Exception(
                    "Cannot delete a change from another user/client without the force option."
                );
            }

            // remove any associated files or jobs.
            $change = clone $this;
            $change->setFiles(null)->setJobs(null)->save();

            // delete the change.
            $flags = array("-d", $id);
            if ($force) {
                array_unshift($flags, "-f");
            }
            $connection->run("change", $flags);
        }

        // confirm delete successful (change -d does not surface errors).
        if (static::exists($id)) {
            throw new Exception("Failed to delete change $id.");
        }

        // should re-populate.
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Resolves the change based on the passed option(s).
     *
     * You must specify one of the below:
     *  RESOLVE_ACCEPT_MERGED
     *   Automatically accept the Perforce-recom mended file revision:
     *   if theirs is identical to base, accept yours; if yours is identical
     *   to base, accept theirs; if yours and theirs are different from base,
     *   and there are no conflicts between yours and theirs; accept merge;
     *   other wise, there are conflicts between yours and theirs, so skip this file.
     *  RESOLVE_ACCEPT_YOURS
     *   Accept Yours, ignore theirs.
     *  RESOLVE_ACCEPT_THEIRS
     *   Accept Theirs. Use this flag with caution!
     *  RESOLVE_ACCEPT_SAFE
     *   Safe Accept. If either yours or theirs is different from base,
     *   (and the changes are in common) accept that revision. If both
     *   are different from base, skip this file.
     *  RESOLVE_ACCEPT_FORCE
     *   Force Accept. Accept the merge file no matter what. If the merge file
     *   has conflict markers, they will be left in, and you'll need to remove
     *   them by editing the file.
     *
     * Additionally, one of the following whitespace options can, optionally, be passed:
     *  IGNORE_WHITESPACE_CHANGES
     *   Ignore whitespace-only changes (for instance, a tab replaced by eight spaces)
     *  IGNORE_WHITESPACE
     *   Ignore whitespace altogether (for instance, deletion of tabs or other whitespace)
     *  IGNORE_LINE_ENDINGS
     *   Ignore differences in line-ending convention
     *
     * Lastly, the resolve can be limited to a particular file in the change by passing:
     *  RESOLVE_FILE => filespec with no wildcards
     *
     * @param   array|string    $options    Resolve option(s); must include a RESOLVE_* preference.
     * @return  Change          provide fluent interface.
     * @todo implement a way to accept edit
     */
    public function resolve($options)
    {
        if (is_string($options)) {
            $options = array($options);
        }

        if (!is_array($options)) {
            throw new \InvalidArgumentException('Expected a string or array of options.');
        }

        $mode       = '';
        $whitespace = '';
        $arguments  = array();

        // loop options so we accept the last mode
        // and whitespace setting we encounter.
        foreach ($options as $option) {
            switch ($option)
            {
                case static::RESOLVE_ACCEPT_MERGED:
                    $mode = '-am';
                    break;
                case static::RESOLVE_ACCEPT_YOURS:
                    $mode = '-ay';
                    break;
                case static::RESOLVE_ACCEPT_THEIRS:
                    $mode = '-at';
                    break;
                case static::RESOLVE_ACCEPT_SAFE:
                    $mode = '-as';
                    break;
                case static::RESOLVE_ACCEPT_FORCE:
                    $mode = '-af';
                    break;
            }

            switch ($option)
            {
                case static::IGNORE_WHITESPACE_CHANGES:
                    $whitespace = '-db';
                    break;
                case static::IGNORE_WHITESPACE:
                    $whitespace = '-dw';
                    break;
                case static::IGNORE_LINE_ENDINGS:
                    $whitespace = '-dl';
                    break;
            }
        }

        // we can't do anything without a mode; throw
        if (empty($mode)) {
            throw new \InvalidArgumentException(
                'No action specified. Expected Resolve Accept Merged|Yours|Theirs|Safe|Force'
            );
        }

        // compile our various flags into our arguments array
        $arguments[] = $mode;
        if ($whitespace) {
            $arguments[] = $whitespace;
        }

        $files = $this->getFiles();
        if (isset($options[static::RESOLVE_FILE])) {
            $file = $options[static::RESOLVE_FILE];
            if (!in_array($file, $files)) {
                throw new \InvalidArgumentException(
                    "The RESOLVE_FILE specified is not in this change."
                );
            }

            $files = array($file);
        }

        // resolve files in several (as few as possible) runs as
        // there is a potential to exceed the arg-max on this command
        $connection = $this->getConnection();
        $batches    = $connection->batchArgs($files, $arguments);
        foreach ($batches as $batch) {
            $connection->run('resolve', $batch);
        }

        return $this;
    }

    /**
     * Set the fix status on jobs attached with this change. It will become the job's
     * status when the change is submitted (thus we don't allow to set it on already
     * submitted changes).
     *
     * @param   string|null     $fixStatus  status to set on jobs when this change is submitted
     *                                      to defer to the default behaviour set the value to null
     * @return  Change                      provides fluent interface.
     * @throws  \InvalidArgumentException   if fix status is incorrect type
     * @throws  Exception                   if change is submitted
     */
    public function setFixStatus($fixStatus)
    {
        if (!is_string($fixStatus) && $fixStatus !== null) {
            throw new \InvalidArgumentException('Job fix status must be a string or null.');
        }

        // setting job's fix status makes sense only on unsubmitted changes
        if ($this->isSubmitted()) {
            throw new Exception('Cannot set job fix status on submitted changes.');
        }

        $this->fixStatus = $fixStatus;

        return $this;
    }

    /**
     * Get the date this change was last modified on the server.
     *
     * @return  null|string     the date this change was last modified on the server,
     *                          or null if the change does not exist on the server.
     * @todo    modify to use DateTime object.
     */
    public function getDate()
    {
        return $this->getRawValue('Date');
    }

    /**
     * Get the unixtime this change was last modified on the server.
     *
     * @return  int|null    the unixtime this change was last modified on the server,
     *                      or null if the change does not exist on the server.
     */
    public function getTime()
    {
        return static::dateToTime($this->getDate(), $this->getConnection()) ?: null;
    }

    /**
     * Get the user that created this change.
     *
     * @return  string  the user that created this change.
     */
    public function getUser()
    {
        $user = $this->getRawValue('User');
        if (!$user) {
            $user = $this->getConnection()->getUser();
        }
        return $user;
    }

    /**
     * Set the user that created this change.
     *
     * @param   $user   string|User         the user that created this change.
     * @return  Change  provides a fluent interface.
     * @throws  \InvalidArgumentException   if bad type given for user
     */
    public function setUser($user)
    {
        $user = $user instanceof User ? $user->getId() : $user;

        if ($user !== null && !is_string($user)) {
            throw new \InvalidArgumentException("Cannot set user. Invalid type given.");
        }

        return $this->setRawValue('User', $user);
    }

    /**
     * Get the client on which this change was created.
     *
     * @return  string  the client on which this change was created.
     */
    public function getClient()
    {
        $client = $this->getRawValue('Client');
        if (!$client) {
            $client = $this->getConnection()->getClient();
        }
        return $client;
    }

    /**
     * Set the client on this change.
     *
     * @param   string|Client|null  $client     the client to set on this change
     * @return  Change              provides a fluent interface.
     * @throws  \InvalidArgumentException       if bad type given for client
     */
    public function setClient($client)
    {
        // normalize input to a string if object given
        if ($client instanceof Client) {
            $client = $client->getId();
        }

        if ($client !== null && !is_string($client)) {
            throw new \InvalidArgumentException("Cannot set client. Invalid type given.");
        }

        return $this->setRawValue('Client', $client);
    }

    /**
     * Get the status of this change (either 'pending' or 'submitted').
     *
     * @return  string  the status of this change: 'pending', 'submitted'.
     */
    public function getStatus()
    {
        $status = $this->getRawValue('Status');
        if (!$status) {
            $status = static::PENDING_CHANGE;
        }
        return $status;
    }

    /**
     * Get the type of this change (either 'public' or 'restricted').
     *
     * @return  string  the type of this change: 'public' or 'restricted'.
     */
    public function getType()
    {
        $type = $this->hasField('Type') ? $this->getRawValue('Type') : false;
        if (!$type) {
            $type = static::PUBLIC_CHANGE;
        }
        return $type;
    }

    /**
     * Set the type of this change (either 'public' or 'restricted').
     *
     * @return  Change  provides fluent interface
     */
    public function setType($type)
    {
        if ($type != static::PUBLIC_CHANGE && $type !== static::RESTRICTED_CHANGE) {
            throw new \InvalidArgumentException("Cannot set type. Type must be public or restricted.");
        }

        return $this->setRawValue('Type', $type);
    }

    /**
     * Get the description for this change.
     *
     * @return  string  the description for this change.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set the description for this change.
     *
     * @param   string|null     $description    description for this change.
     * @return  Change          provides a fluent interface.
     * @throws  \InvalidArgumentException if description is incorrect type.
     */
    public function setDescription($description)
    {
        if ($description !== null && !is_string($description)) {
            throw new \InvalidArgumentException("Cannot set description. Invalid type given.");
        }

        return $this->setRawValue('Description', $description);
    }

    /**
     * Get the job status of this change (the status that associated jobs will
     * have when the change is submitted).
     *
     * The value of the job status field is not preserved. You cannot get the
     * job status of a saved or submitted change. Once a changelist is saved or
     * submitted, the job status field is cleared. It can only be read after it
     * has been explicitly set, and before the change is saved or submitted.
     *
     * @return  null|string     the job status of this change if not yet saved or submitted.
     *                          null otherwise.
     */
    public function getJobStatus()
    {
        return $this->getRawValue('JobStatus');
    }

    /**
     * Get the jobs attached to this change.
     *
     * @return  array   the list of jobs attached to this change.
     * @todo    return  Job objects in an iterator.
     */
    public function getJobs()
    {
        $jobs = $this->getRawValue('Jobs');
        return is_array($jobs) ? $jobs : array();
    }

    /**
     * Get the jobs attached to this change in Job format.
     *
     * @return  FieldedIterator     list of Job's associated with this change.
     */
    public function getJobObjects()
    {
        // just skip to an empty iterator if we have no jobs
        if (!$this->getJobs()) {
            return new FieldedIterator;
        }

        if (!isset($this->cache['jobObjects'])
            || !$this->cache['jobObjects'] instanceof FieldedIterator
        ) {
            $this->cache['jobObjects'] = Job::fetchAll(
                array(Job::FETCH_BY_IDS => $this->getJobs()),
                $this->getConnection()
            );
        }

        return clone $this->cache['jobObjects'];
    }

    /**
     * Get the requested job attached to this change in Job format.
     *
     * @param   string  $job    Job identifier
     * @return  Job             The requested job
     * @throws  \InvalidArgumentException   If the specified job isn't attached to this change.
     */
    public function getJobObject($job)
    {
        // validate input
        if (!is_string($job)) {
            throw new \InvalidArgumentException('Job must be a string or P4\Job object.');
        }

        foreach ($this->getJobObjects() as $jobObject) {
            if ($jobObject->getId() == $job) {
                return $jobObject;
            }
        }

        throw new \InvalidArgumentException('The requested job was not found in this change');
    }

    /**
     * Set the list of jobs attached to this change.
     *
     * @param   null|array|FieldedIterator  $jobs   the jobs to attach to this change.
     * @return  Change                      provides a fluent interface.
     * @throws  \InvalidArgumentException   if jobs is incorrect type.
     * @throws  Exception                   if change is submitted.
     */
    public function setJobs($jobs)
    {
        if ($jobs === null) {
            $jobs = array();
        }

        // normalize to an array
        if ($jobs instanceof FieldedIterator) {
            $jobs = $jobs->toArray(true);
        }

        // ensure jobs is an array.
        if (!is_array($jobs)) {
            throw new \InvalidArgumentException('Cannot set jobs. Invalid type given.');
        }

        // ensure job elements are strings or job objects.
        foreach ($jobs as $key => $job) {
            if ($job instanceof Job) {
                $jobs[$key] = $job = $job->getId();
            }

            if (!is_string($job)) {
                throw new \InvalidArgumentException('Each job must be a string.');
            }
        }

        // don't permit set jobs on submitted changes.
        if ($this->isSubmitted()) {
            throw new Exception('Cannot set jobs on a submitted change.');
        }

        // we cache job objects; clear that out
        $this->cache = array();

        return $this->setRawValue('Jobs', $jobs);
    }

    /**
     * Add a job to the list of jobs attached to this change.
     *
     * @param   string      $job    the id of the job to attach to this change.
     * @return  Change      provides fluent interface.
     */
    public function addJob($job)
    {
        $jobs = $this->getJobs();
        if (!in_array($job, $jobs)) {
            $jobs[] = $job;
        }
        $this->setJobs($jobs);

        return $this;
    }

    /**
     * Get the files attached to this change. Revspecs are included for submitted
     * changes but are not present on pending changes.
     *
     * @return  array   list of files associated with this change.
     */
    public function getFiles()
    {
        $files = $this->getRawValue('Files');
        return is_array($files) ? $files : array();
    }

    /**
     * Get the files attached to this change in File format.
     *
     * @return  FieldedIterator     list of File's associated with this change.
     */
    public function getFileObjects()
    {
        if (!isset($this->cache['fileObjects'])
            || !$this->cache['fileObjects'] instanceof FieldedIterator
        ) {
            $this->cache['fileObjects'] = File::fetchAll(
                FileQuery::create()->addFilespecs(
                    $this->getFiles()
                ),
                $this->getConnection()
            );
        }

        return clone $this->cache['fileObjects'];
    }

    /**
     * For numbered changes, it is possible to get additional information
     * about the files attached to the change (e.g. action, type, rev, from-file).
     *
     * This method is a useful alternative to getFileObjects() if you don't
     * want to incur the cost of an fstat query and the instantiation of files.
     *
     * @param   bool            $shelved    optional - get shelved files instead of open files (default false)
     * @param   int|false|null  $max        optional - limit number of files to report (optimized in 2014.1+)
     *                                      if set to false, return any pre-existing cached file data
     * @return  array           a list of files with metadata (e.g. action, type, rev, from-file)
     */
    public function getFileData($shelved = false, $max = null)
    {
        $cacheKeyPrefix = 'fileData-' . ($shelved ? 'shelved-' : '');

        // if max is explicitly false, caller doesn't care how many files we return
        // pick the cached result with the highest number of files
        if ($max === false) {
            foreach (array_keys($this->cache) as $cacheKey) {
                if (strpos($cacheKey, $cacheKeyPrefix) === 0) {
                    $max = max($max, end(explode('-', $cacheKey)));
                }
            }
        }

        $max = (int) $max;

        $cacheKey = $cacheKeyPrefix . $max;
        if (!isset($this->cache[$cacheKey])) {
            // note: we don't supply '-s' to suppress diffs because this breaks
            // fromFile information - fortunately, we don't need -s because we
            // are getting tagged output which suppresses diffs automatically
            // (see job058799 for more information).
            $flags = $max && $this->getConnection()->isServerMinVersion('2014.1') ? array('-m', $max) : array();
            $flags = array_merge($flags, $shelved ? array('-S', $this->getId()) : array($this->getId()));
            $data  = $this->getConnection()->run('describe', $flags)->expandSequences()->getData(0);

            // turn describe data inside-out (currently keyed on field name
            // with entries for each file, re-key by file, then field).
            $files = array();
            foreach ($data as $key => $values) {
                // skip over job/jobstat as they aren't file related
                if (strpos($key, 'job') === 0) {
                    continue;
                }

                if (is_array($values)) {
                    foreach ($values as $index => $value) {
                        if ($max && $index >= $max) {
                            break;
                        }
                        $files[$index] = isset($files[$index])
                            ? $files[$index] + array($key => $value)
                            : array($key => $value);
                    }
                }

                // record the path so we can provide it later via getPath().
                // the 'path' is the deepest path common to all files.
                if ($key === 'path') {
                    $this->cache['path'] = $values;
                }

                // record the 'oldChange' so we can provide it later via
                // getOriginalId() - old change is the pre-submit id.
                if ($key === 'oldChange') {
                    $this->cache['oldChange'] = $values;
                }

                // record the 'shelved' property so we can use it later
                // needed to detect remote/un-promoted/shelved changes
                if ($key === 'shelved') {
                    $this->cache['shelved'] = true;
                }
            }

            // if we didn't encounter a 'shelved' property, it must be false
            if (!isset($this->cache['shelved'])) {
                $this->cache['shelved'] = false;
            }

            $this->cache[$cacheKey] = $files;
        }

        return $this->cache[$cacheKey];
    }

    /**
     * Get p4 changes list data for a submitted change.
     *
     * This is useful because changes reports some information that is not
     * included in spec data; specifically path and oldChange. The describe
     * command also reports this data (accessible via getFileData()), but
     * it includes all files in the change which can be too much data.
     *
     * @return  array   $data   p4 changes data from Perforce.
     * @throws  Exception       if called on a pending change.
     */
    public function getChangesData()
    {
        // not available for pending changes.
        if ($this->isPending()) {
            throw new Exception("Cannot get changes data for a pending change.");
        }

        if (!isset($this->cache['changesData'])) {
            $this->cache['changesData'] = $this->getConnection()->run(
                'changes',
                array('@=' . $this->getId())
            )->getData(0);
        }

        return $this->cache['changesData'];
    }

    /**
     * Get the deepest path common to all files in this change.
     *
     * @param   bool    $trim       remove trailing wildcards and partial paths
     * @param   bool    $shelved    optional - get path of shelved files (default false)
     * @param   int     $maxFiles   optional - limit number of files to scan (can cause incorrect result)
     * @return  string  the deepest path common to all files.
     */
    public function getPath($trim = true, $shelved = false, $maxFiles = null)
    {
        // if the path is not cached and this change is
        // submitted, use p4 changes data to get path
        if (!array_key_exists('path', $this->cache) && $this->isSubmitted()) {
            $data = $this->getChangesData();
            $this->cache['path'] = $data['path'];
        }

        // if path is still not set, use file data (which primes path)
        if (!array_key_exists('path', $this->cache)) {
            $paths = $this->getFileData($shelved, $maxFiles);

            // compute the common path if it wasn't sent by the server.
            // the server doesn't report the path for shelved changes.
            // note: because the paths are sorted, we can get away with
            // a clever trick and just compare the first and last file.
            if (!array_key_exists('path', $this->cache) && count($paths)) {
                $first   = $paths[0]['depotFile'];
                $last    = $paths[count($paths) - 1]['depotFile'];
                $length  = min(strlen($first), strlen($last));
                $compare = $this->getConnection()->isCaseSensitive() ? 'strcmp' : 'strcasecmp';
                for ($i = 0; $i < $length; $i++) {
                    if ($compare($first[$i], $last[$i]) !== 0) {
                        break;
                    }
                }
                $this->cache['path'] = substr($first, 0, $i);
            } elseif (!array_key_exists('path', $this->cache)) {
                $this->cache['path'] = null;
            }
        }

        $path = $this->cache['path'];
        return $trim ? substr($path, 0, strrpos($path, '/')) : $path;
    }

    /**
     * Get the original change id. When a change is submitted it is
     * potentially renumbered to be the highest numbered change.
     * If the change is not renumbered, returns getId().
     *
     * @return  int     the original id, falling back to the current id
     */
    public function getOriginalId()
    {
        // if the original id is not cached and this change is
        // submitted, use p4 changes data to get original id
        if (!array_key_exists('oldChange', $this->cache) && $this->isSubmitted()) {
            $data = $this->getChangesData();
            $this->cache['oldChange'] = isset($data['oldChange']) ? $data['oldChange'] : null;
        }

        return isset($this->cache['oldChange'])
            ? $this->cache['oldChange']
            : $this->getId();
    }

    /**
     * Get the requested file attached to this change in File format.
     *
     * @param   File|string     $file       Filespec in string or File format; rev is ignored
     * @return  File            The requested file at the revision associated with this change
     * @throws  \InvalidArgumentException   If the specified file doesn't exist.
     */
    public function getFileObject($file)
    {
        // normalize to string
        if ($file instanceof File) {
            $file = $file->getDepotFilename();
        }

        // validate input
        if (!is_string($file)) {
            throw new \InvalidArgumentException('File must be a string or P4\File object.');
        }

        // ensure no rev-spec is present on our comparison entry
        $file = File::stripRevspec($file);
        foreach ($this->getFileObjects() as $changeFile) {
            if (File::stripRevspec($changeFile->getDepotfilename()) == $file) {
                return $changeFile;
            }
        }

        throw new \InvalidArgumentException('The requested file was not found in this change');
    }

    /**
     * Set the list of opened files attached to this change.
     *
     * @param   null|array|FieldedIterator  $files  the files to attach to this change.
     * @return  Change                      provides a fluent interface.
     * @throws  \InvalidArgumentException   if files is incorrect type.
     * @throws  Exception                   if change is submitted.
     */
    public function setFiles($files)
    {
        if ($files === null) {
            $files = array();
        }

        if ($files instanceof FieldedIterator) {
            $files = iterator_to_array($files);
        }

        // ensure files is an array.
        if (!is_array($files)) {
            throw new \InvalidArgumentException('Cannot set files. Invalid type given.');
        }

        // normalize the array entries to a string, stripping revspecs
        foreach ($files as &$file) {
            if ($file instanceof File) {
                $file = $file->getFilespec();
            }

            if (!is_string($file)) {
                throw new \InvalidArgumentException('All files must be a string or P4\File');
            }

            $file = File::stripRevspec($file);
        }

        // don't permit set files on submitted changes.
        if ($this->isSubmitted()) {
            throw new Exception('Cannot set files on a submitted change.');
        }

        // we cache file objects; clear that out
        $this->cache = array();

        $this->setRawValue('Files', $files);

        return $this;
    }

    /**
     * Add a file to the list of files in this changelist.
     *
     * @param   string|File     $file   the file to attach to this change.
     * @return  Change          provides fluent interface.
     */
    public function addFile($file)
    {
        // if file is a File object, extract the filespecs.
        if ($file instanceof File) {
            $file = $file->getFilespec();
        }

        $files = $this->getFiles();
        if (!in_array($file, $files)) {
            $files[] = $file;
        }
        $this->setFiles($files);

        return $this;
    }

    /**
     * Check if this is a pending change.
     *
     * @return  bool  true if this is a pending change - false otherwise.
     */
    public function isPending()
    {
        return ($this->isDefault() || $this->getStatus() === static::PENDING_CHANGE);
    }

    /**
     * Check if this is a submitted change.
     *
     * @return  bool  true if this is a submitted change - false otherwise.
     */
    public function isSubmitted()
    {
        return ($this->getStatus() === static::SUBMITTED_CHANGE);
    }

    /**
     * Test if this is the default change.
     *
     * @return  bool  true if this is the default change - false otherwise.
     */
    public function isDefault()
    {
        return ($this->getId() === static::DEFAULT_CHANGE);
    }

    /**
     * Test if this change is accessible. Returns false if the change is
     * restricted and the description is obfuscated to indicate user
     * has no permission to view it, true otherwise.
     *
     * @return  bool    false if this change is not accessible, true
     *                  otherwise
     */
    public function canAccess()
    {
        return $this->getType() !== static::RESTRICTED_CHANGE
            || trim($this->getDescription()) !== "<description: restricted, no permission to view>";
    }

    /**
     * Get files that need to be resolved.
     *
     * @return  FieldedIterator   files that need to be resolved.
     */
    public function getFilesToResolve()
    {
        $query = FileQuery::create()
                 ->addFilespec(File::ALL_FILES)
                 ->setLimitToChangelist($this->getId())
                 ->setLimitToNeedsResolve(true)
                 ->setLimitToOpened(true);
        return File::fetchAll($query, $this->getConnection());
    }

    /**
     * Get files that must be reverted.
     *
     * @return  FieldedIterator   files that must be reverted.
     */
    public function getFilesToRevert()
    {
        // setup fstat filter to match files that must be reverted.
        // several conditions to catch:
        //  - files open for add, but already existing and not deleted, or
        //  - files that are not open for add, but are deleted in the depot.
        $filter = "(action=add & headAction=* & ^headAction=...deleted) | "
                . "(headAction=...delete & ^action=add)";

        $query = FileQuery::create()
                 ->addFilespec(File::ALL_FILES)
                 ->setLimitToChangelist($this->getId())
                 ->setLimitToOpened(true)
                 ->setFilter($filter);
        return File::fetchAll($query, $this->getConnection());
    }

    /**
     * Get a field's raw value.
     * Extend parent to ensure the Description's placeholder value is translated to null.
     *
     * @param   string      $field  the name of the field to get the value of.
     * @return  mixed       the value of the field.
     * @throws  Exception   if the field does not exist.
     */
    public function getRawValue($field)
    {
        $value = parent::getRawValue($field);

        // if the Description field has its placeholder value just return null
        // you can't actually save the placeholder value its reserved.
        if ($field == 'Description' && $value == "<enter description here>\n") {
            return null;
        }

        return $value;
    }

    /**
     * Set a field's raw value (avoids mutator).
     * Extended to limit to allow setting client. We think its read only based
     * on the spec definition but it really isn't.
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  SingularAbstract    provides a fluent interface
     * @throws  Exception           if the field does not exist or is read-only
     */
    public function setRawValue($field, $value)
    {
        if ($field == 'Client') {
            $this->values[$field] = $value;
            return $this;
        }

        return parent::setRawValue($field, $value);
    }

    /**
     * Attempt to detect if this change is a un-promoted shelved change on a remote edge server.
     *
     * We cannot do this with 100% accuracy, but we can make an educated guess.
     * If we satisfy the following conditions it is likely a remote edge shelf:
     *  - server version > 2014.1
     *  - not promoted
     *  - shelved change
     *  - no files
     *
     * @return  bool    true if the change looks like a remote edge shelf
     */
    public function isRemoteEdgeShelf()
    {
        // if the server is older than 2014.1, there is no such thing
        if (!$this->getConnection()->isServerMinVersion('2014.1')) {
            return false;
        }

        // if the change is promoted, then it is global
        if ($this->hasField('IsPromoted') && $this->get('IsPromoted') == 1) {
            return false;
        }

        // we need file data (describe output) for this next part
        $files = $this->getFileData(true, false);

        // if the change is not shelved, it cannot be an edge shelf
        if (!$this->cache['shelved']) {
            return false;
        }

        // if we can see files in the change, it cannot be a remote edge shelf
        if (count($files)) {
            return false;
        }

        // hmmm... looks inaccessible, likely a remote edge shelf
        return true;
    }

    /**
     * Check if the given id is in a valid format for a change number.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\ChangeNumber;
        return $validator->isValid($id);
    }

    /**
     * Get raw spec data direct from Perforce. No caching involved.
     * Overrides parent to suppress id when id is 'default' and to
     * fetch files for submitted changes.
     *
     * @return  array   $data   the raw spec output from Perforce.
     * @todo    get jobs for submitted changes.
     */
    protected function getSpecData()
    {
        $flags = array('-o');
        if ($this->getId() !== static::DEFAULT_CHANGE) {
            $flags[] = $this->getId();
        }
        $data = $this->getConnection()
                     ->run(static::SPEC_TYPE, $flags)
                     ->expandSequences()
                     ->getData(-1);

        // get files if this is a submitted change
        // note: can't use isSubmitted here - populate not complete yet.
        if ($data['Status'] == Change::SUBMITTED_CHANGE) {
            $describe = $this->getConnection()
                             ->run('describe', array('-s', $this->getId()))
                             ->getData(0);

            $data['Files'] = array();
            for ($i = 0; isset($describe['depotFile' . $i], $describe['rev' . $i]); $i++) {
                $data['Files'][] = $describe['depotFile' . $i] . '#' . $describe['rev' . $i];
            }
        }

        return $data;
    }

    /**
     * Produce set of flags for the spec list command, given fetch all options array.
     * Extends parent to add support for additional options.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        $flags = parent::getFetchAllFlags($options);

        // always use -l (for full descriptions).
        $flags[] = "-l";

        if (isset($options[static::FETCH_INTEGRATED]) &&
            $options[static::FETCH_INTEGRATED] === true) {
            $flags[] = "-i";
        }

        if (isset($options[static::FETCH_BY_STATUS])) {
            $flags[] = "-s";
            $flags[] = $options[static::FETCH_BY_STATUS];
        }

        if (isset($options[static::FETCH_BY_CLIENT])) {
            $flags[] = "-c";
            $flags[] = $options[static::FETCH_BY_CLIENT];
        }

        if (isset($options[static::FETCH_BY_USER])) {
            $flags[] = "-u";
            $flags[] = $options[static::FETCH_BY_USER];
        }

        if (isset($options[static::FETCH_BY_IDS]) && is_array($options[static::FETCH_BY_IDS])) {
            foreach ($options[static::FETCH_BY_IDS] as $id) {
                $flags[] = '@' . $id . ',@' . $id;
            }
        }

        // filespec must come last.
        if (isset($options[static::FETCH_BY_FILESPEC]) && $options[static::FETCH_BY_FILESPEC]) {
            $flags[] = $options[static::FETCH_BY_FILESPEC];
        }

        return $flags;
    }

    /**
     * Given a spec entry from spec list output (p4 changes), produce
     * an instance of this spec with field values set where possible.
     *
     * @param   array                       $listEntry      a single spec entry from spec list output.
     * @param   array                       $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface         $connection     a specific connection to use.
     * @return  Change                      a (partially) populated instance of this spec class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        // rename 'desc' field to 'Description'.
        $listEntry['Description'] = $listEntry['desc'];
        unset($listEntry['desc']);

        $change = parent::fromSpecListEntry($listEntry, $flags, $connection);

        // record the 'path' so we can provide it later via getPath().
        // record the 'oldChange' so we can provide it later via getOriginalId().
        $change->cache['path']      = isset($listEntry['path'])      ? $listEntry['path']      : null;
        $change->cache['oldChange'] = isset($listEntry['oldChange']) ? $listEntry['oldChange'] : null;

        return $change;
    }
}
