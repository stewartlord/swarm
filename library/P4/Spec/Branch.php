<?php
/**
 * Abstracts operations against Perforce branch specs.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        Add support for the following commands:
 *              integrate
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\ConnectionInterface;

class Branch extends PluralAbstract
{
    const SPEC_TYPE         = 'branch';
    const ID_FIELD          = 'Branch';

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
        'View'          => array(
            'accessor'  => 'getView',
            'mutator'   => 'setView'
        )
    );

    /**
     * Get all Branches from Perforce. Adds filtering options.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *
     *                                   FETCH_MAXIMUM - set to integer value to limit to the
     *                                                   first 'max' number of entries.
     *                                   FETCH_BY_NAME - set to branch name pattern (e.g. 'bran*').
     *                                  FETCH_BY_OWNER - set to owner's username (e.g. 'jdoe').
     *
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  P4\Model\Fielded\Iterator   all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // simply return parent - method exists to document options.
        return parent::fetchAll($options, $connection);
    }

    /**
     * Determine if the given branch id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing branch.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $branches = static::fetchAll(
            array(
                static::FETCH_BY_NAME => $id,
                static::FETCH_MAXIMUM => 1
            ),
            $connection
        );

        return (bool) count($branches);
    }

    /**
     * Get the last update time for this branch spec.
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
     * Get the last access time for this branch spec.
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
     * Get the owner of this branch.
     *
     * @return  string|null  User who owns this record.
     */
    public function getOwner()
    {
        return $this->getRawValue('Owner');
    }

    /**
     * Set the owner of this branch to passed value.
     *
     * @param   string|null $owner  A string containing username or null for none
     * @return  Branch      provides a fluent interface.
     * @throws  \InvalidArgumentException Owner is incorrect type.
     */
    public function setOwner($owner)
    {
        if (!is_string($owner) && !is_null($owner)) {
            throw new \InvalidArgumentException('Owner must be a string or null.');
        }

        return $this->setRawValue('Owner', $owner);
    }

    /**
     * Get the description for this branch.
     *
     * @return  string  description for this branch.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set a description for this branch.
     *
     * @param   string|null $description    description for this branch.
     * @return  Branch      provides a fluent interface.
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
     * Get options for this branch.
     *
     * @return  string  options which are set on this branch ('locked' or 'unlocked').
     */
    public function getOptions()
    {
        return $this->getRawValue('Options');
    }

    /**
     * Set the options for this branch. See getOptions for expected values.
     *
     * @param   string|null $options    options to set on this branch.
     * @return  Branch      provides a fluent interface.
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
     * Get the view for this branch.
     * View entries will be returned as an array with 'source' and 'target' entries, e.g.:
     * array (
     *      0 => array (
     *          'source' => '//depot/branchA/with space/...',
     *          'target' => '//depot/branchB/with space/...'
     *      )
     *  )
     *
     * @return  array  list view entries for this branch.
     */
    public function getView()
    {
        // The raw view data is formatted as:
        //  array (
        //      0 => '"//depot/example/with space/..." //depot/example/nospace/...',
        //  )
        //
        // We split this into 'source' and 'target' components via the str_getcsv function
        // and key the two resulting entries as 'source' and 'target'
        $view = array();
        // The ?: translates empty views into an empty array
        foreach ($this->getRawValue('View') ?: array() as $entry) {
            $entry  = str_getcsv($entry, ' ');
            $view[] = array_combine(array('source','target'), $entry);
        }

        return $view;
    }

    /**
     * Set the view for this branch.
     * View is passed as an array of view entries. Each view entry can be an array with
     * 'source' and 'target' entries or a raw string.
     *
     * @param   array  $view  View entries, formatted into source/target sub-arrays.
     * @return  Branch      provides a fluent interface.
     * @throws  \InvalidArgumentException View array, or a view entry, is incorrect type.
     */
    public function setView($view)
    {
        if (!is_array($view)) {
            throw new \InvalidArgumentException('View must be passed as array.');
        }

        // The View array contains either:
        // - Child arrays keyed on source/target which we glue together
        // - Raw strings which we simply leave as is
        // The below foreach run will normalize the whole thing for storage
        $parsedView = array();
        foreach ($view as $entry) {
            if (is_array($entry) &&
                isset($entry['source'], $entry['target']) &&
                is_string($entry['source']) &&
                is_string($entry['target'])) {
                $entry = '"'. $entry['source'] .'" "'. $entry['target'] .'"';
            }

            if (!is_string($entry)) {
                throw new \InvalidArgumentException(
                    "Each view entry must be a 'source' and 'target' array or a string."
                );
            }

            $validate = str_getcsv($entry, ' ');
            if (count($validate) != 2 || trim($validate[0]) === '' || trim($validate[1]) === '') {
                throw new \InvalidArgumentException(
                    "Each view entry must contain two depot paths, no more, no less."
                );
            }

            $parsedView[] = $entry;
        };

        return $this->setRawValue('View', $parsedView);
    }

    /**
     * Add a view mapping to this Branch.
     *
     * @param   string  $source     the source half of the view mapping.
     * @param   string  $target     the target half of the view mapping.
     * @return  Branch      provides a fluent interface.
     */
    public function addView($source, $target)
    {
        $mappings   = $this->getView();
        $mappings[] = array("source" => $source, "target" => $target);

        return $this->setView($mappings);
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

            if (!is_string($name) || trim($name) === "") {
                throw new \InvalidArgumentException(
                    'Filter by Name expects a non-empty string as input'
                );
            }

            $flags[] = '-e';
            $flags[] = $name;
        }

        if (isset($options[static::FETCH_BY_OWNER])) {
            $owner = $options[static::FETCH_BY_OWNER];

            // We allow empty values as this returns branches with no owner
            if (!is_string($owner) || trim($owner) === "") {
                throw new \InvalidArgumentException(
                    'Filter by Owner expects a non-empty string as input'
                );
            }

            $flags[] = '-u';
            $flags[] = $owner;
        }

        return $flags;
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
     * Get the fetch all command, generally a plural version of the spec type.
     *
     * @return  string  Perforce command to use for fetchAll
     */
    protected static function getFetchAllCommand()
    {
        // Branch is a special case; over-ridden to add 'es' instead of 's' to spec type.
        return static::SPEC_TYPE . "es";
    }
}
