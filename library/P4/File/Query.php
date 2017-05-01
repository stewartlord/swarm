<?php
/**
 * Provides a container for query options suitable for passing to fetchAll.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        store filter internally as a filter - don't cast to string.
 */

namespace P4\File;

use P4;
use P4\Spec\Change;

class Query
{
    const QUERY_FILTER                  = 'filter';
    const QUERY_SORT_BY                 = 'sortBy';
    const QUERY_SORT_REVERSE            = 'reverseOrder';
    const QUERY_LIMIT_FIELDS            = 'limitFields';
    const QUERY_LIMIT_TO_CHANGELIST     = 'limitToChangelist';
    const QUERY_LIMIT_TO_NEEDS_RESOLVE  = 'limitToNeedsResolve';
    const QUERY_LIMIT_TO_OPENED         = 'limitToOpened';
    const QUERY_MAX_FILES               = 'maxFiles';
    const QUERY_START_ROW               = 'startRow';
    const QUERY_FILESPECS               = 'filespecs';

    const ADD_MODE_AND                  = 'add';
    const ADD_MODE_OR                   = 'or';

    const SORT_DATE                     = '#REdate';
    const SORT_HEAD_REV                 = '#RErev';
    const SORT_HAVE_REV                 = '#NEhrev';
    const SORT_FILE_TYPE                = '#NEtype';
    const SORT_FILE_SIZE                = '#REsize';
    const SORT_ASCENDING                = 'a';
    const SORT_DESCENDING               = 'd';
    const SORT_CLAUSE_COUNT_2010_2      = 2;

    protected $options                  = null;
    protected $validFieldNames          = null;

    /**
     * Constructor that accepts an array of options for immediate population.
     * Note that options are validated and can throw exceptions.
     *
     * @param array $options query options
     */
    public function __construct($options = array())
    {
        $this->reset();
        if (isset($options) && is_array($options)) {
            foreach ($options as $key => $value) {
                if (array_key_exists($key, $this->options)) {
                    $method = 'set'. ucfirst($key);
                    $this->$method($value);
                }
            }
        }
    }

    /**
     * Creates and returns a new Query class. Useful for working
     * around PHP's lack of new chaining.
     *
     * @param   array   $options    query options
     * @return  Query
     */
    public static function create($options = array())
    {
        return new static($options);
    }

    /**
     * Reset the current query object to its default state.
     *
     * @return  Query   provide a fluent interface.
     */
    public function reset()
    {
        $this->options = array(
            static::QUERY_FILTER                  => null,
            static::QUERY_SORT_BY                 => null,
            static::QUERY_SORT_REVERSE            => false,
            static::QUERY_LIMIT_FIELDS            => null,
            static::QUERY_LIMIT_TO_CHANGELIST     => null,
            static::QUERY_LIMIT_TO_NEEDS_RESOLVE  => false,
            static::QUERY_LIMIT_TO_OPENED         => false,
            static::QUERY_MAX_FILES               => null,
            static::QUERY_START_ROW               => null,
            static::QUERY_FILESPECS               => null,
        );

        $this->validFieldNames = array(
            static::SORT_DATE       => true,
            static::SORT_HEAD_REV   => true,
            static::SORT_HAVE_REV   => true,
            static::SORT_FILE_TYPE  => true,
            static::SORT_FILE_SIZE  => true,
        );

        return $this;
    }

    /**
     * Provide all of the current options as an array.
     *
     * @return  array  The current query options as an array.
     */
    public function toArray()
    {
        return $this->options;
    }

    /**
     * Retrieve the current filter object.
     * Null means no filtering will take place.
     *
     * @return  string  The current filter expression.
     */
    public function getFilter()
    {
        return $this->options[static::QUERY_FILTER];
    }

    /**
     * Set the filter to limit the returned set of files.
     * See 'p4 help fstat' and 'p4 help jobview' for more information on
     * the filter format. Accepts a Filter or string for input,
     * or null to remove any filter.
     *
     * @param   string|Filter|null  $filter     The desired filter.
     * @return  Query   provide a fluent interface.
     */
    public function setFilter($filter = null)
    {
        if (is_string($filter)) {
            $filter = new Filter($filter);
        }

        if (!$filter instanceof Filter && !is_null($filter)) {
            throw new \InvalidArgumentException(
                'Cannot set filter; argument must be a P4\File\Filter, a string, or null.'
            );
        }

        $this->options[static::QUERY_FILTER] = $filter;
        return $this;
    }

    /**
     * Get the current sort field.
     * Null means default sorting will take place.
     *
     * @return  array|null  The current list of sort options, or null if not set.
     */
    public function getSortBy()
    {
        return $this->options[static::QUERY_SORT_BY];
    }

    /**
     * Set the file field(s) which will be used to sort results.
     *
     * $sortBy can be an array containing, either strings for the field name to sort
     * (where the default option will be SORT_ASCENDING), or the field name as a key
     * with an options array as value.
     *
     * For convenience, setSortBy() can accept $sortBy as a string for the field name
     * and an options array.
     *
     * Valid sort fields are any valid attribute name, or one of the constants:
     * SORT_DATE, SORT_HEAD_REV, SORT_HAVE_REV, SORT_FILE_TYPE, SORT_FILE_SIZE
     *
     * The available sorting options include: SORT_ASCENDING and SORT_DESCENDING.
     * Future server versions may provide other alternatives.
     *
     * Specify null to receive files in the default order.
     *
     * @param   array|string|null   $sortBy   An array of fields or field => options, a string field,
     *                                        or default null.
     * @param   array|null          $options  Sorting options, only used when sortBy is a string.
     * @return  Query               provide a fluent interface.
     */
    public function setSortBy($sortBy = null, $options = null)
    {
        // handle variations of parameter passing
        $clauses = array();
        if (is_array($sortBy)) {
            $maxClauses = $this->getSortClauseCount();
            if (count($sortBy) > $maxClauses) {
                throw new \InvalidArgumentException(
                    "Cannot set sort by; argument contains more than $maxClauses clauses."
                );
            }

            // normalize sortBy clauses
            foreach ($sortBy as $field => $options) {
                if (is_integer($field) && is_string($options)) {
                    $field   = $options;
                    $options = null;
                }

                if (!is_string($field)) {
                    throw new \InvalidArgumentException(
                        'Cannot set sort by; invalid sort clause provided.'
                    );
                }

                $clauses[$field] = $options;
            }
        } elseif (is_string($sortBy)) {
            $clauses = array($sortBy => $options);
        } elseif (isset($sortBy)) {
            throw new \InvalidArgumentException(
                'Cannot set sort by; argument must be an array, string, or null.'
            );
        }

        // validate clauses
        if (isset($clauses)) {
            $counter = 0;
            foreach ($clauses as $field => $options) {
                $counter++;
                if (!$this->isValidSortField($field)) {
                    throw new \InvalidArgumentException(
                        "Cannot set sort by; invalid field name in clause #$counter."
                    );
                }
                if (!$this->isValidSortOptions($options)) {
                    throw new \InvalidArgumentException(
                        "Cannot set sort by; invalid sort options in clause #$counter."
                    );
                }
            }
        }

        $this->options[static::QUERY_SORT_BY] = !empty($clauses) ? $clauses : null;
        return $this;
    }

    /**
     * Validate sort field name.
     *
     * @param   string   $field  Field name to validate.
     * @return  boolean  true if field name is valid, false otherwise.
     */
    protected function isValidSortField($field)
    {
        if (!isset($field) || !is_string($field) || !strlen($field)) {
            return false;
        }

        if ($this->isInternalSortField($field)) {
            return true;
        }

        $validator = new P4\Validate\AttributeName();
        if ($validator->isValid($field)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the field provided is a server-specific sort field.
     *
     * @param   string   $field  The field name to check against list of internal fields.
     * @return  boolean  true if the supplied field name is an internal field.
     */
    protected function isInternalSortField($field)
    {
        return (array_key_exists($field, $this->validFieldNames)) ? true : false;
    }

    /**
     * Validate sort options.
     *
     * @param  array|null  $options  Options to validate.
     * @return boolean  true if options are valid, false otherwise.
     */
    protected function isValidSortOptions($options)
    {
        // null is valid; we'll apply a default later.
        if (!isset($options)) {
            return true;
        }

        if (!is_array($options)) {
            return false;
        }

        // validate each option
        $seenDirection = false;
        foreach ($options as $option) {
            if ($option === static::SORT_ASCENDING
                || $option === static::SORT_DESCENDING
            ) {
                if ($seenDirection) {
                    return false;
                }
                $seenDirection = true;
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * Retrieve the field count available for sort clauses, which is server dependent.
     * In the future, this method may return different values depending on the server
     * version in use.
     *
     * @return  int  The number of sort clauses the server supports.
     */
    public function getSortClauseCount()
    {
        return static::SORT_CLAUSE_COUNT_2010_2;
    }

    /**
     * Retrieve the current reverse order flag setting.
     * True means that the sort order will be reversed.
     *
     * @return  boolean  true if the sort order will be reversed.
     */
    public function getReverseOrder()
    {
        return $this->options[static::QUERY_SORT_REVERSE];
    }

    /**
     * Set the flag indicating whether the results will be returned in reverse order.
     *
     * @param   boolean     $reverse    Set to true to reverse sort order.
     * @return  Query       provide a fluent interface.
     */
    public function setReverseOrder($reverse = false)
    {
        $this->options[static::QUERY_SORT_REVERSE] = (bool) $reverse;
        return $this;
    }

    /**
     * Retrieve the list of file fields to return in server responses.
     * Null means all fields will be returned.
     *
     * @return  array|null  The current list of file fields.
     */
    public function getLimitFields()
    {
        return $this->options[static::QUERY_LIMIT_FIELDS];
    }

    /**
     * Set the list of fields to include in the response from the server.
     *
     * @param   string|array|null   $fields     The list of desired fields. Supply a string
     *                                          to specify one field, or supply a null to
     *                                          retrieve all fields.
     * @return  Query               provide a fluent interface.
     */
    public function setLimitFields($fields = array())
    {
        // accept strings for a single filespec, for convenience.
        if (is_string($fields)) {
            $fields = array($fields);
        }
        if (isset($fields) && !is_array($fields)) {
            throw new \InvalidArgumentException(
                'Cannot set limiting fields; argument must be a string, an array, or null.'
            );
        }

        $this->options[static::QUERY_LIMIT_FIELDS] = $fields;
        return $this;
    }

    /**
     * Retrieve the needs resolve flag.
     * True if only files needing resolve should be returned.
     *
     * @return  boolean  True if only files needing resolve should be returned.
     */
    public function getLimitToNeedsResolve()
    {
        return $this->options[static::QUERY_LIMIT_TO_NEEDS_RESOLVE];
    }

    /**
     * Sets the flag that will limit files to those that need resolve.
     * True means only files that need resolve will be included.
     *
     * @param   boolean     $limit  Set to true if only files needing resolve should be returned.
     * @return  Query       provide a fluent interface.
     */
    public function setLimitToNeedsResolve($limit = false)
    {
        // accept numbers or numeric string values, for convenience.
        if (is_numeric($limit) || is_string($limit)) {
            $limit = (bool) (int) $limit;
        }
        if (!is_bool($limit)) {
            throw new \InvalidArgumentException('Cannot set limit to needs resolve; argument must be a boolean.');
        }

        $this->options[static::QUERY_LIMIT_TO_NEEDS_RESOLVE] = $limit;
        return $this;
    }

    /**
     * Retrieve the opened files flag.
     * True if only opened files should be returned.
     *
     * @return  boolean  True if only opened files should be returned.
     */
    public function getLimitToOpened()
    {
        return $this->options[static::QUERY_LIMIT_TO_OPENED];
    }

    /**
     * Sets the flag that will limit files to those that are opened.
     * True means only files that are opened will be included.
     *
     * @param   boolean     $limit  Set to true if only opened files should be returned.
     * @return  Query       provide a fluent interface.
     */
    public function setLimitToOpened($limit = false)
    {
        // accept numbers or numeric string values, for convenience.
        if (is_numeric($limit) || is_string($limit)) {
            $limit = (bool) (int) $limit;
        }
        if (!is_bool($limit)) {
            throw new \InvalidArgumentException('Cannot set limit to opened files; argument must be a boolean.');
        }

        $this->options[static::QUERY_LIMIT_TO_OPENED] = $limit;
        return $this;
    }

    /**
     * Retrieve the changelist with which to limit returned files.
     * Null means all restriction to changelist is not in effect.
     *
     * @return  string|int  The current limiting changelist.
     */
    public function getLimitToChangelist()
    {
        return $this->options[static::QUERY_LIMIT_TO_CHANGELIST];
    }

    /**
     * Set to a valid changelist to limit returned files, or
     * null to remove the limit.
     *
     * @param   boolean     $changelist     A valid changelist.
     * @return  Query       provide a fluent interface.
     */
    public function setLimitToChangelist($changelist = null)
    {
        // accept numeric string values, for convenience.
        if (is_string($changelist)) {
            if ($changelist !== Change::DEFAULT_CHANGE) {
                $changelist = (int) $changelist;
            }
        }
        if ($changelist instanceof Change) {
            $changelist = $changelist->getId();
        }
        $validator = new P4\Validate\ChangeNumber;
        if (isset($changelist) && !$validator->isValid($changelist)) {
            throw new \InvalidArgumentException(
                'Cannot set limit to changelist; argument must be a changelist id, a P4\Spec\Change object, or null.'
            );
        }

        $this->options[static::QUERY_LIMIT_TO_CHANGELIST] = $changelist;
        return $this;
    }

    /**
     * Return the starting row for matching files.
     * Null means all matching files will be returned.
     *
     * @return  int|null  The starting row.
     */
    public function getStartRow()
    {
        return $this->options[static::QUERY_START_ROW];
    }

    /**
     * Set the starting row to return from matching files,
     * or null to return all matching files.
     *
     * @param   int|null    $row    The starting row.
     * @return  Query       provide a fluent interface.
     */
    public function setStartRow($row = null)
    {
        // accept numeric string values, for convenience.
        if (is_string($row)) {
            $row = (int) $row;
        }
        if (isset($row) && (!is_integer($row) || $row < 0)) {
            throw new \InvalidArgumentException('Cannot set start row; argument must be a positive integer or null.');
        }
        if ($row === 0) {
            $row = null;
        }

        $this->options[static::QUERY_START_ROW] = $row;
        return $this;
    }

    /**
     * Retrieve the maximum number of files to include in results.
     * 0 or null means unlimited.
     *
     * @return  integer  The maximum number of files to include in results.
     */
    public function getMaxFiles()
    {
        return $this->options[static::QUERY_MAX_FILES];
    }

    /**
     * Set to limit the number of matching files returned, or null
     * to return all matching files.
     *
     * @param   int|null    $max    The maximum number of files to return.
     * @return  Query       provide a fluent interface.
     */
    public function setMaxFiles($max = null)
    {
        // accept numeric string values, for convenience.
        if (is_string($max)) {
            $max = (int) $max;
        }
        if (isset($max) && (!is_integer($max) || $max < 0)) {
            throw new \InvalidArgumentException('Cannot set max files; argument must be a positive integer or null.');
        }
        if ($max === 0) {
            $max = null;
        }

        $this->options[static::QUERY_MAX_FILES] = $max;
        return $this;
    }

    /**
     * Retrieve the list of filespecs to fetch.
     * Null means no filespecs will be fetched; aka query cannot run.
     *
     * @return  array  The list of filespecs.
     */
    public function getFilespecs()
    {
        return $this->options[static::QUERY_FILESPECS];
    }

    /**
     * Set the list of filespecs to be fetched, or null to empty the array.
     *
     * The filespecs may be in any one of depot, client or local file syntax with wildcards
     * (e.g. '//depot/...'). Note: perforce applies options such as maxFiles and sortBy to
     * each filespec individually.
     *
     * @param   array|null  $filespecs  The filespecs to fetch.
     * @return  Query       provide a fluent interface.
     */
    public function setFilespecs($filespecs = null)
    {
        // accept a string for a single filespec, for convenience.
        if (is_string($filespecs)) {
            $filespecs = array($filespecs);
        }
        if (isset($filespecs) && !is_array($filespecs)) {
            throw new \InvalidArgumentException('Cannot set filespecs; argument must be a string, an array, or null.');
        }

        $this->options[static::QUERY_FILESPECS] = isset($filespecs) ? array_values($filespecs) : null;
        return $this;
    }

    /**
     * Add a single filespec to be fetched.
     *
     * The filespec may be in any one of depot, client or local file syntax with wildcards
     * (e.g. '//depot/...'). Note: perforce applies options such as maxFiles and sortBy to
     * each filespec individually.
     *
     * @param   string  $filespec   The filespec to add.
     * @return  Query   provide a fluent interface.
     */
    public function addFilespec($filespec)
    {
        if (!isset($filespec) || !is_string($filespec)) {
            throw new \InvalidArgumentException('Cannot add filespec; argument must be a string.');
        }

        return $this->addFilespecs(array($filespec));
    }

    /**
     * Add a list of filespecs to be fetched.
     *
     * The filespecs may be in any one of depot, client or local file syntax with wildcards
     * (e.g. '//depot/...'). Note: perforce applies options such as maxFiles and sortBy to
     * each filespec individually.
     *
     * @param   array   $filespecs  The array of filespecs to add.
     * @return  Query   provide a fluent interface.
     */
    public function addFilespecs($filespecs = array())
    {
        if (!isset($filespecs) || !is_array($filespecs)) {
            throw new \InvalidArgumentException('Cannot add filespecs; argument must be an array.');
        }

        $this->options[static::QUERY_FILESPECS] = (isset($this->options[static::QUERY_FILESPECS]))
            ? array_merge($this->options[static::QUERY_FILESPECS], array_values($filespecs))
            : $filespecs;

        return $this;
    }

    /**
     * Produce set of flags for the fstat command based on current options.
     *
     * @return  array  set of flags suitable for passing to fstat command.
     */
    public function getFstatFlags()
    {
        $flags = array();

        $filter = $this->getFilter();
        $filter = is_null($filter) ? '' : $filter->getExpression();

        // if start row set, apply rowNumber filter.
        if ($this->getStartRow()) {
            $filter = $filter ? '(' . $filter . ') & ' : '';
            $filter = $filter . 'rowNumber > ' . $this->getStartRow();
        }

        // filter option.
        if ($filter) {
            $flags[] = '-F';
            $flags[] = $filter;
        }

        // subset of fields option.
        if (count($this->getLimitFields())) {
            $flags[] = '-T';
            $flags[] = implode(' ', $this->getLimitFields());
        }

        // maximum results option.
        if ($this->getMaxFiles() !== null) {
            $flags[] = '-m';
            $flags[] = $this->getMaxFiles();
        }

        // files in change option.
        if ($this->getLimitToChangelist() !== null) {
            $flags[] = '-e';
            $flags[] = $this->getLimitToChangelist();

            // for the default change, we want to fetch opened files
            if ($this->getLimitToChangelist() === Change::DEFAULT_CHANGE) {
                $this->setLimitToOpened(true);
            }
        }

        // only opened files option.
        if ($this->getLimitToOpened()) {
            $flags[] = '-Ro';
        }

        // only files that need resolve option.
        if ($this->getLimitToNeedsResolve()) {
            $flags[] = "-Ru";
        }

        // sort options.
        if ($this->getSortBy() !== null) {
            $handled = false;
            $clauses = $this->getSortBy();
            if (count($clauses) == 1) {
                list ($field, $options) = each($clauses);
                if ($this->isInternalSortField($field) && !isset($options)) {
                    $handled = true;
                    switch ($field) {
                        case static::SORT_DATE:
                            $flags[] = '-Sd';
                            break;
                        case static::SORT_HEAD_REV:
                            $flags[] = '-Sr';
                            break;
                        case static::SORT_HAVE_REV:
                            $flags[] = '-Sh';
                            break;
                        case static::SORT_FILE_TYPE:
                            $flags[] = '-St';
                            break;
                        case static::SORT_FILE_SIZE:
                            $flags[] = '-Ss';
                            break;
                    }
                }
            }

            if (!$handled) {
                $expressions = array();
                foreach ($clauses as $field => $options) {
                    if (strpos($field, '#') !== false) {
                        $field = preg_replace('/#/', '', $field);
                    } else {
                        $field = "attr-$field";
                    }
                    if (!isset($options)) {
                        $options = array(static::SORT_ASCENDING);
                    }
                    $expressions[] = "$field=". join('', $options);
                }
                $flags[] = '-S';
                $flags[] = join(',', $expressions);
            }

        }

        // reverse sort option.
        if ($this->getReverseOrder()) {
            $flags[] = '-r';
        }

        // standard options.
        $flags[] = '-Oal';

        return $flags;
    }
}
