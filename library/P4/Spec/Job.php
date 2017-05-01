<?php
/**
 * Abstracts operations against Perforce jobs.
 *
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        Add support for the following commands:
 *              fix
 *              fixes
 */

namespace P4\Spec;

use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\Exception;
use P4\Spec\Exception\NotFoundException;
use P4\Validate;

class Job extends PluralAbstract
{
    const SPEC_TYPE         = 'job';
    const ID_FIELD          = 'Job';

    const FETCH_BY_FILTER   = 'filter';
    const FETCH_DESCRIPTION = 'descriptions';
    const FETCH_BY_IDS      = 'ids';
    const FETCH_INSENSITIVE = 'insensitive';
    const FETCH_REVERSE     = 'reverse';

    protected $cache        = array();
    protected $fields       = array(
        102 => array(
            'accessor'  => 'getStatus',
            'mutator'   => 'setStatus'
        ),
        103 => array(
            'accessor'  => 'getUser',
            'mutator'   => 'setUser',
        ),
        104 => array(
            'accessor'  => 'getDate'
        ),
        105 => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        )
    );

    /**
     * Extend parent to clear any cached fixed changes.
     *
     * @param   null|string     $id     the id of this entry - pass null to clear.
     * @return  PluralAbstract          provides a fluent interface
     * @throws  \InvalidArgumentException   if id does not pass validation.
     */
    public function setId($id)
    {
        $this->cache = array();
        return parent::setId($id);
    }

    /**
     * Get field value. If a custom field accessor exists, it will be used.
     * Extends parent to add support for accessors keyed on field code instead of name.
     *
     * @param   string|null     $field  the name of the field to get the value of or null for all
     * @return  mixed           the value of the field(s).
     * @throws  Exception       if the field does not exist.
     */
    public function get($field = null)
    {
        // allow parent to deal with requests for array format
        if ($field === null) {
            return parent::get($field);
        }

        // if field has custom accessor based on field code, use it.
        $fieldCode = $this->getSpecDefinition()->fieldNameToCode($field);
        if (isset($this->fields[$fieldCode]['accessor'])) {
            return $this->{$this->fields[$fieldCode]['accessor']}();
        }

        return parent::get($field);
    }

    /**
     * Set field value. If a custom field mutator exists, it will be used.
     * Extends parent to add support for mutators keyed on field code instead of name.
     *
     * @param   string|array    $field  the name of the field to set the value of.
     * @param   mixed           $value  the value to set in the field.
     * @return  Job             provides a fluent interface
     */
    public function set($field, $value = null)
    {
        // if field has custom mutator based on field code, use it.
        $fieldCode = !is_array($field)
            ? $this->getSpecDefinition()->fieldNameToCode($field)
            : null;
        if ($fieldCode && isset($this->fields[$fieldCode]['mutator'])) {
            return $this->{$this->fields[$fieldCode]['mutator']}($value);
        }

        return parent::set($field, $value);
    }

    /**
     * Get all Jobs from Perforce. Adds filtering options.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                   FETCH_MAXIMUM - set to integer value to limit to the
     *                                                   first 'max' number of entries.
     *                                 FETCH_BY_FILTER - set to jobview filter
     *                               FETCH_DESCRIPTION - description will be fetched if true,
     *                                                   left for later lazy loading if false.
     *                                                   * defaults to true if not specified
     *                                    FETCH_BY_IDS - pass a list of ids to fetch
     *                                                   not compatible with FETCH_BY_FILTER
     *                               FETCH_INSENSITIVE - only applies to FETCH_BY_IDS, makes
     *                                                   id matches case insensitive
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  \P4\Model\Fielded\Iterator  all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // if fetch by ids was passed by is an empty array just return an empty result
        // otherwise the caller would actually get all jobs back erroneously.
        $options += array(static::FETCH_BY_IDS => null, static::FETCH_INSENSITIVE => null);
        $ids      = $options[static::FETCH_BY_IDS];
        if (is_array($ids) && !count($ids)) {
            return new FieldedIterator;
        }

        $result = parent::fetchAll($options, $connection);

        // if we received fetch by ids, ensure the results are accurate.
        // if the id foo was requested we can also see results for entries
        // such as foo-bar without this step. its rare in reality though.
        if ($options[static::FETCH_BY_IDS]) {
            $result->filter(
                'Job',
                $options[static::FETCH_BY_IDS],
                $options[static::FETCH_INSENSITIVE] ? array($result::FILTER_NO_CASE) : array()
            );
        }

        return $result;
    }

    /**
     * Determine if the given job id exists.
     *
     * @param   string|int                  $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing job.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $jobs = static::fetchAll(
            array(
                static::FETCH_BY_IDS        => $id,
                static::FETCH_DESCRIPTION   => false,
                static::FETCH_MAXIMUM       => 1
            ),
            $connection
        );

        return (bool) count($jobs);
    }

    /**
     * Get the requested job entry from Perforce.
     *
     * @param   string                      $id         the id of the job to fetch.
     * @param   ConnectionInterface         $connection optional - a specific connection to use.
     * @return  Change                      instance of the requested job.
     * @throws  \InvalidArgumentException   if no id is given.
     * @throws  NotFoundException           if no such job exists.
     */
    public static function fetch($id, ConnectionInterface $connection = null)
    {
        // ensure a valid id is provided.
        if (!static::isValidId($id)) {
            throw new \InvalidArgumentException("Must supply a valid id to fetch.");
        }

        $job = static::fetchAll(
            array(
                static::FETCH_BY_IDS    => $id,
                static::FETCH_MAXIMUM   => 1
            ),
            $connection
        )->first();

        if (!$job || $job->getId() != $id) {
            throw new NotFoundException(
                "Cannot fetch " . static::SPEC_TYPE . " $id. Record does not exist."
            );
        }

        return $job;
    }

    /**
     * Override parent to set id to 'new' if unset and capture id returned by save.
     *
     * @return  Job     provides a fluent interface
     */
    public function save()
    {
        $values = $this->getRawValues();
        if ($this->getId() === null) {
            $values[static::ID_FIELD] = "new";
        }

        // ensure all required fields have values.
        $this->validateRequiredFields($values);

        $result = $this->getConnection()->run(static::SPEC_TYPE, "-i", $values);

        // Saved job Id is returned as a string, capture it.
        $matches = false;
        foreach ($result->getData() as $data) {
            if (preg_match('/^Job ([^ ]+) (saved|not changed)\./', $data, $matches)) {
                break;
            }
        }

        if (!$matches) {
            throw new Exception('Cannot find ID for saved Job.');
        }

        // Store the retrieved ID
        $this->setId($matches[1]);

        // should re-populate (server may change values).
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Returns the status of this job. This will return the value of field 102 even if the
     * field name has been changed in the jobspec.
     *
     * Out of the box valid status options are: open/suspended/closed or null. Modifying the
     * jobspec can change the list of valid options.
     *
     * @return  string|null     Status of this job or null if unset.
     */
    public function getStatus()
    {
        return $this->getRawValue($this->getSpecDefinition()->fieldCodeToName(102));
    }

    /**
     * Update the status of this job. This will update the value of field 102 even if the
     * field name has been changed in the jobspec.
     *
     * @param   string|null $status Status of this job or null
     * @return  Job     provides a fluent interface.
     * @throws  \InvalidArgumentException   For input which isn't a string or null
     */
    public function setStatus($status)
    {
        if (!is_string($status) && !is_null($status)) {
            throw new \InvalidArgumentException('Status must be a string or null');
        }

        return $this->setRawValue($this->getSpecDefinition()->fieldCodeToName(102), $status);
    }


    /**
     * Returns the user who created this job. This will return the value of field 103
     * even if the field name has been changed in the jobspec.
     *
     * @return  string|null     User who created this job or null if unset.
     */
    public function getUser()
    {
        return $this->getRawValue($this->getSpecDefinition()->fieldCodeToName(103));
    }

    /**
     * Update the user who created this job. This will update the value of field 103
     * even if the field name has been changed in the jobspec.
     *
     * @param   string|User|null    $user User who created this job, or null
     * @return  Job                 provides a fluent interface.
     * @throws  \InvalidArgumentException   For input which isn't a string, User or null
     */
    public function setUser($user)
    {
        if ($user instanceof User) {
            $user = $user->getId();
        }

        if (!is_null($user) && !is_string($user)) {
            throw new \InvalidArgumentException('User must be a string, P4\Spec\User or null');
        }

        return $this->setRawValue($this->getSpecDefinition()->fieldCodeToName(103), $user);
    }

    /**
     * Returns the date this job was created. This will return the value of field 104
     * even if the field name has been changed in the jobspec.
     *
     * @return  string|null     Date this job was created or null if unset.
     */
    public function getDate()
    {
        return $this->getRawValue($this->getSpecDefinition()->fieldCodeToName(104));
    }

    /**
     * Get the unixtime this job was created on the server.
     *
     * @return  int|null    the unixtime this job was created on the server,
     *                      or null if the job does not exist on the server.
     */
    public function getTime()
    {
        return $this->getAsTime($this->getSpecDefinition()->fieldCodeToName(104)) ?: null;
    }

    /**
     * Convenience function to get a given field as unixtime accounting for server's current timezone.
     *
     * @param  string       $field  the name of the field
     * @return int|false    date in unix timestamp of false if unable to convert
     */
    public function getAsTime($field)
    {
        return static::dateToTime($this->getRawValue($field), $this->getConnection());
    }

    /**
     * Returns the description for this job. This will return the value of field 105
     * even if the field name has been changed in the jobspec.
     *
     * @return  string|null     Description for this job or null if unset.
     */
    public function getDescription()
    {
        return $this->getRawValue($this->getSpecDefinition()->fieldCodeToName(105));
    }

    /**
     * Update the decription for this job. This will update the value of field 105
     * even if the field name has been changed in the jobspec.
     *
     * @param   string|null $description    Description for this job, or null
     * @return  Job     provides a fluent interface.
     * @throws  \InvalidArgumentException   For input which isn't a string or null
     */
    public function setDescription($description)
    {
        if (!is_null($description) && !is_string($description)) {
            throw new \InvalidArgumentException('Description must be a string or null');
        }

        return $this->setRawValue($this->getSpecDefinition()->fieldCodeToName(105), $description);
    }

    /**
     * Get the changes fixed by this job.
     *
     * @return  array   the list of changes fixed by this job.
     */
    public function getChanges()
    {
        // if no id is set; just return an empty array
        if (!$this->getId()) {
            return array();
        }

        // fetch the list of changes if we don't already have it
        if (!isset($this->cache['changes']) || !is_array($this->cache['changes'])) {
            $this->cache['changes'] = array();
            $data    = $this->getConnection()->run('fixes', array('-j', $this->getId()))->getData();
            foreach ($data as $fix) {
                $this->cache['changes'][] = $fix['Change'];
            }
        }

        return $this->cache['changes'];
    }

    /**
     * Get the change objects fixed by this job.
     *
     * @return  FieldedIterator     list of Changes fixed by this job
     */
    public function getChangeObjects()
    {
        // just skip to an empty iterator if we have no fixes
        if (!$this->getChanges()) {
            return new FieldedIterator;
        }

        if (!isset($this->cache['changeObjects'])
            || !$this->cache['changeObjects'] instanceof FieldedIterator
        ) {
            $this->cache['changeObjects'] = Change::fetchAll(
                array(Change::FETCH_BY_IDS => $this->getChanges()),
                $this->getConnection()
            );
        }

        return clone $this->cache['changeObjects'];
    }

    /**
     * Determine if this job has a created date field
     *
     * @return  bool    true if job has a created date field; false otherwise.
     */
    public function hasCreatedDateField()
    {
        try {
            $this->getCreatedDateField();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the name of this job's created date field.
     *
     * @return  string      the name of the created date field.
     * @throws  Exception   if there is no created date field.
     */
    public function getCreatedDateField()
    {
        $spec   = $this->getSpecDefinition();
        $fields = $spec->getFields();
        foreach ($fields as $key => $field) {
            if (isset($field['fieldType'])  && $field['fieldType'] === 'once'
                && isset($field['default']) && $field['default']   === '$now'
            ) {
                return $key;
            }
        }

        throw new Exception("Job has no created date field.");
    }

    /**
     * Determine if this job has a modified date field
     *
     * @return  bool    true if job has a modified date field; false otherwise.
     */
    public function hasModifiedDateField()
    {
        try {
            $this->getModifiedDateField();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the name of this job's modified date field.
     *
     * @return  string      the name of the modified date field.
     * @throws  Exception   if there is no modified date field.
     */
    public function getModifiedDateField()
    {
        $spec   = $this->getSpecDefinition();
        $fields = $spec->getFields();
        foreach ($fields as $key => $field) {
            if (isset($field['fieldType'])  && $field['fieldType'] === 'always'
                && isset($field['default']) && $field['default']   === '$now'
            ) {
                return $key;
            }
        }

        throw new Exception("Job has no modified date field.");
    }

    /**
     * Determine if this job has a created by field
     *
     * @return  bool    true if job has a created by field; false otherwise.
     */
    public function hasCreatedByField()
    {
        try {
            $this->getCreatedByField();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the name of this job's created by field.
     *
     * @return  string      the name of the created by field.
     * @throws  Exception   if there is no created by field.
     */
    public function getCreatedByField()
    {
        $spec   = $this->getSpecDefinition();
        $fields = $spec->getFields();
        foreach ($fields as $key => $field) {
            if (isset($field['fieldType'])  && $field['fieldType'] !== 'always'
                && isset($field['default']) && $field['default']   === '$user'
            ) {
                return $key;
            }
        }

        throw new Exception("Job has no created By field.");
    }

    /**
     * Determine if this job has a modified by field
     *
     * @return  bool    true if job has a modified by field; false otherwise.
     */
    public function hasModifiedByField()
    {
        try {
            $this->getModifiedByField();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the name of this job's modified by field.
     *
     * @return  string      the name of the modified by field.
     * @throws  Exception   if there is no modified by field.
     */
    public function getModifiedByField()
    {
        $spec   = $this->getSpecDefinition();
        $fields = $spec->getFields();
        foreach ($fields as $key => $field) {
            if (isset($field['fieldType'])  && $field['fieldType'] === 'always'
                && isset($field['default']) && $field['default']   === '$user'
            ) {
                return $key;
            }
        }

        throw new Exception("Job has no modified By field.");
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

        if (isset($options[static::FETCH_BY_FILTER]) &&
            !(isset($options[static::FETCH_BY_IDS]) && $options[static::FETCH_BY_IDS])
        ) {
            $filter = $options[static::FETCH_BY_FILTER];

            if (!is_string($filter) || trim($filter) === "") {
                throw new \InvalidArgumentException(
                    'Fetch by Filter expects a non-empty string as input'
                );
            }

            $flags[] = '-e';
            $flags[] = $filter;
        }

        if (isset($options[static::FETCH_BY_IDS]) && $options[static::FETCH_BY_IDS]) {
            // escape and concat job ids
            $jobs = array();
            foreach ((array)$options[static::FETCH_BY_IDS] as $id) {
                $jobs[] = preg_replace('/([^\w])/', '\\\\$1', $id);
            }

            $flags[] = '-e';
            $flags[] = static::ID_FIELD . "="
                     . implode("|" . static::ID_FIELD . "=", $jobs);
        }

        // if they have not specified FETCH_DESCRIPTION or
        // they have and its true; include full descriptions
        if (!isset($options[static::FETCH_DESCRIPTION]) ||
            $options[static::FETCH_DESCRIPTION]) {
            $flags[] = '-l';
        }

        // sort in reverse order if so instructed
        if (isset($options[static::FETCH_REVERSE]) && $options[static::FETCH_REVERSE]) {
            $flags[] = '-r';
        }

        return $flags;
    }

    /**
     * Check if the given id is in a valid format for this spec type.
     *
     * @param   string|int  $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\SpecName;
        $validator->allowSlashes(true);
        $validator->allowRelative(true);
        $validator->allowPurelyNumeric(true);
        return $validator->isValid($id);
    }

    /**
     * Extends parent to control description inclusion based on FETCH options.
     *
     * @param   array                       $listEntry      a single spec entry from spec list output.
     * @param   array                       $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface         $connection     a specific connection to use.
     * @return  Job                         a (partially) populated instance of this spec class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        // discard the description if it isn't the 'long' version
        if (!in_array('-l', $flags)) {
            unset($listEntry['Description']);
        }

        $job = parent::fromSpecListEntry($listEntry, $flags, $connection);

        // jobs are fully populated when -l is used.
        // empty fields are not returned by p4 jobs and would
        // otherwise cause a needless populate on get(null)
        if (in_array('-l', $flags)) {
            $job->needsPopulate = false;
        }

        return $job;
    }
}
