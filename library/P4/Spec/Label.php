<?php
/**
 * Abstracts operations against Perforce labels.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\ConnectionInterface;

class Label extends PluralAbstract
{
    const SPEC_TYPE         = 'label';
    const ID_FIELD          = 'Label';

    const FETCH_BY_NAME     = 'name';
    const FETCH_BY_OWNER    = 'owner';

    protected $fields       = array(
        'Update'        => array(
            'accessor'  => 'getUpdateDateTime'
        ),
        'Access'        => array(
            'accessor'  => 'getAccessDateTime'
        ),
        'Owner'         => array(
            'accessor'  => 'getOwner',
            'mutator'   => 'setOwner'
        ),
        'Description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'Options'       => array(
            'accessor'  => 'getOptions',
            'mutator'   => 'setOptions'
        ),
        'Revision'      => array(
            'accessor'  => 'getRevision',
            'mutator'   => 'setRevision'
        ),
        'View'          => array(
            'accessor'  => 'getView',
            'mutator'   => 'setView'
        )
    );

    /**
     * Get all Labels from Perforce. Adds filtering options.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                   FETCH_MAXIMUM - set to integer value to limit to the
     *                                                   first 'max' number of entries.
     *                                   FETCH_BY_NAME - set to label name pattern (e.g. 'labe*').
     *                                  FETCH_BY_OWNER - set to owner's username (e.g. 'jdoe').
     *
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  \P4\Model\Fielded\Iterator  all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // simply return parent - method exists to document options.
        return parent::fetchAll($options, $connection);
    }

    /**
     * Determine if the given label id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing label.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $labels = static::fetchAll(
            array(
                static::FETCH_BY_NAME => $id,
                static::FETCH_MAXIMUM => 1
            ),
            $connection
        );

        return (bool) count($labels);
    }

    /**
     * Get the last update time for this label spec.
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
     * Get the last access time for this label spec.
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
     * Get the owner of this label.
     *
     * @return  string|null User who owns this record.
     */
    public function getOwner()
    {
        return $this->getRawValue('Owner');
    }

    /**
     * Set the owner of this label to passed value.
     *
     * @param   string|User|null    $owner  A string containing username
     * @return  Label               provides a fluent interface.
     * @throws  \InvalidArgumentException Owner is incorrect type.
     */
    public function setOwner($owner)
    {
        if ($owner instanceof User) {
            $owner = $owner->getId();
        }

        if (!is_string($owner) && !is_null($owner)) {
            throw new \InvalidArgumentException('Owner must be a string, P4\Spec\User or null.');
        }

        return $this->setRawValue('Owner', $owner);
    }

    /**
     * Get the description for this label.
     *
     * @return  string|null description for this label.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set a description for this label.
     *
     * @param   string|null $description    description for this label.
     * @return  Label      provides a fluent interface.
     * @throws  \InvalidArgumentException   Description is incorrect type.
     */
    public function setDescription($description)
    {
        if (!is_string($description) && !is_null($description)) {
            throw new \InvalidArgumentException('Description must be a string or null.');
        }

        return $this->setRawValue('Description', $description);
    }

    /**
     * Get options for this label.
     *
     * @return  string|null options which are set on this label ('locked' or 'unlocked').
     */
    public function getOptions()
    {
        return $this->getRawValue('Options');
    }

    /**
     * Set the options for this label. See getOptions for expected values.
     *
     * @param   string|null $options    options to set on this label.
     * @return  Label       provides a fluent interface.
     * @throws  \InvalidArgumentException Options are incorrect type.
     */
    public function setOptions($options)
    {
        if (!is_string($options) && !is_null($options)) {
            throw new \InvalidArgumentException('Options must be a string or null.');
        }

        return $this->setRawValue('Options', $options);
    }

    /**
     * Get the revision setting for this label.
     *
     * @return  string|null Revision setting for this label.
     */
    public function getRevision()
    {
        $revision = $this->getRawValue('Revision');

        // strip quotes if needed
        if (is_string($revision) &&
            substr($revision, 0, 1) === '"' &&
            substr($revision, -1) === '"') {
            return substr($revision, 1, -1);
        }

        return $revision;
    }

    /**
     * Set the revision setting for this label.
     *
     * @param   string|null $revision   Revision setting for this label.
     * @return  Label       provides a fluent interface.
     * @throws  \InvalidArgumentException revision is incorrect type.
     */
    public function setRevision($revision)
    {
        if (!is_string($revision) && !is_null($revision)) {
            throw new \InvalidArgumentException('Revision must be a string or null.');
        }

        // quote string values; leaves null values alone
        if (is_string($revision)) {
            $revision = '"' . $revision . '"';
        }

        return $this->setRawValue('Revision', $revision);
    }

    /**
     * Get the view for this label.
     * View entries will be returned as an array of strings e.g.:
     * array (
     *      0 => '//depot/example/with space/...',
     *      1 => '//depot/alternate/example/*'
     *  )
     * Labels view is fairly unique as each entry is only one depot path.
     *
     * @return  array  list view entries for this label, empty array if none.
     */
    public function getView()
    {
        return $this->getRawValue('View') ?: array();
    }

    /**
     * Set the view for this label. See getView for format details.
     *
     * @param   array  $view  Array of view strings, empty array for none.
     * @return  Label        provides a fluent interface.
     * @throws  \InvalidArgumentException View array, or a view entry, is incorrect type.
     */
    public function setView($view)
    {
        if (!is_array($view)) {
            throw new \InvalidArgumentException('View must be passed as array.');
        }

        foreach ($view as $entry) {
            if (!is_string($entry) || trim($entry) === "") {
                throw new \InvalidArgumentException(
                    "Each view entry must be a non-empty string."
                );
            }
        }

        return $this->setRawValue('View', $view);
    }

    /**
     * Add a view entry to this Label.
     *
     * @param   string  $path   the depot path to add.
     * @return  Label       provides a fluent interface.
     */
    public function addView($path)
    {
        $entries   = $this->getView();
        $entries[] = $path;

        return $this->setView($entries);
    }

    /**
     * Adds the specified filespecs to this label. The update is completed
     * synchronously, no need to call save.
     *
     * @param   array   $filespecs  The filespecs to add to this label, can include rev-specs
     * @return  Label               provides a fluent interface.
     */
    public function tag($filespecs)
    {
        if (!is_array($filespecs) || in_array(false, array_map('is_string', $filespecs))) {
            throw new \InvalidArgumentException(
                'Tag requires an array of string values for input'
            );
        }

        // there is a potential to exceed the arg-max limit;
        // run tag command as few times as possible
        $connection = $this->getConnection();
        $batches    = $connection->batchArgs($filespecs, array('-l', $this->getId()));
        foreach ($batches as $batch) {
            $connection->run('tag', $batch);
        }

        return $this;
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
        $validator->allowRelative(true);
        $validator->allowPercent(false);
        $validator->allowCommas(false);
        $validator->allowSlashes(true);
        return $validator->isValid($id);
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

        if (isset($options[static::FETCH_BY_NAME])) {
            $name = $options[static::FETCH_BY_NAME];

            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException(
                    'Filter by Name expects a non-empty string as input'
                );
            }

            $flags[] = '-e';
            $flags[] = $name;
        }

        if (isset($options[static::FETCH_BY_OWNER])) {
            $owner = $options[static::FETCH_BY_OWNER];

            // We allow empty values as this returns labels with no owner
            if (!is_string($owner) || trim($owner) === '') {
                throw new \InvalidArgumentException(
                    'Filter by Owner expects a non-empty string as input'
                );
            }

            $flags[] = '-u';
            $flags[] = $owner;
        }

        return $flags;
    }
}
