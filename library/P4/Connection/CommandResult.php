<?php
/**
 * Encapsulates the results of a Perforce command.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection;

class CommandResult
{
    protected $command;
    protected $data     = array();
    protected $errors   = array();
    protected $warnings = array();
    protected $isTagged = true;

    /**
     * Constructs the perforce command result object.
     *
     * @param   string  $command    the command that was run.
     * @param   array   $data       optional - array of result data.
     * @param   bool    $tagged     optional - true if data is tagged.
     */
    public function __construct($command, $data = null, $tagged = true)
    {
        $this->command     =   $command;
        if (is_array($data)) {
            $this->data    =   $data;
        }
        $this->isTagged    =   $tagged;
    }

    /**
     * Return the name of the perforce command that was issued.
     *
     * @return  string  the command.
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * Test if the output is tagged.
     *
     * @return  boolean true if the output is tagged.
     */
    public function isTagged()
    {
        return $this->isTagged;
    }

    /**
     * Set data on the result object.
     *
     * @param   array  $data    the array of data to set on the result.
     * @return  CommandResult   provides a fluent interface
     */
    public function setData($data)
    {
        if (!is_array($data)) {
            $data = array($data);
        }
        $this->data = $data;

        return $this;
    }

    /**
     * Set errors on the result object.
     *
     * @param   array  $errors  the error messages to set.
     */
    public function setErrors($errors)
    {
        if (!is_array($errors)) {
            $errors = array($errors);
        }
        $this->errors = $errors;
    }

    /**
     * Set warnings on the result object.
     *
     * @param   array  $warnings  the warning messages to set.
     */
    public function setWarnings($warnings)
    {
        if (!is_array($warnings)) {
            $warnings = array($warnings);
        }
        $this->warnings = $warnings;
    }

    /**
     * Add data to the result object.
     *
     * @param   string|array   $data   string value or array of attribute values.
     */
    public function addData($data)
    {
        $this->data[] = $data;
    }

    /**
     * Set an error on the result object.
     *
     * @param   string  $error  the error message to set.
     */
    public function addError($error)
    {
        $this->errors[] = (string) $error;
    }

    /**
     * Set a warning on the result object.
     *
     * @param   string  $warning  the warning message to set.
     */
    public function addWarning($warning)
    {
        $this->warnings[] = (string) $warning;
    }

    /**
     * Return all result data or a particular index/attribute if specified.
     *
     * @param   integer             $index      optional - the set of attributes to get
     *                                          (if negative we count back from the end, e.g. -1 for the last set)
     * @param   mixed               $attribute  optional - a specific attribute to get.
     * @return  array|string|false  the requested result data or false if index/attribute invalid.
     */
    public function getData($index = null, $attribute = null)
    {
        // if an attribute is specified without an index,
        // return just that attribute (or null) for all indexes
        if ($attribute !== null && $index === null) {
            $column = array();
            foreach ($this->data as $row) {
                $column[] = isset($row[$attribute]) ? $row[$attribute] : null;
            }
            return $column;
        }

        // if no index is specified, return all data.
        if ($index === null) {
            return $this->data;
        }

        // if the index is negative, count back from the end
        if (is_int($index) && $index < 0) {
            $index = count($this->data) + $index;
        }

        // if a valid index is specified without an attribute,
        // return the value at that index.
        if ($attribute === null && array_key_exists($index, $this->data)) {
            return $this->data[$index];
        }

        // if a valid index and attribute are specified return the attribute value.
        if ($attribute !== null &&
            array_key_exists($index, $this->data) &&
            is_array($this->data[$index]) &&
            array_key_exists($attribute, $this->data[$index])
        ) {
            return $this->data[$index][$attribute];
        }

        return false;
    }

    /**
     * Return any errors encountered executing the command.
     * Errors have leading/trailing whitespace stripped to ensure consistency between
     * various connection methods.
     *
     * @return array    any errors set on the result object.
     */
    public function getErrors()
    {
        return array_map('trim', $this->errors);
    }

    /**
     * Return any warnings encountered executing the command.
     * Warnings have leading/trailing whitespace stripped to ensure consistency between
     * various connection methods.
     *
     * @return array    any warnings set on the result object.
     */
    public function getWarnings()
    {
        return array_map('trim', $this->warnings);
    }

    /**
     * Check if this result contains data (as opposed to errors/warnings).
     *
     * @return  bool    true if there is data - false otherwise.
     */
    public function hasData()
    {
        return !empty($this->data);
    }

    /**
     * Check if there are any errors set for this result.
     *
     * @return  bool    true if there are errors - false otherwise.
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Check if there are any warnings set for this result.
     *
     * @return  bool    true if there are warnings - false otherwise.
     */
    public function hasWarnings()
    {
        return !empty($this->warnings);
    }

    /**
     * Expands any numeric sequences present based on passed attribute identifier.
     *
     * @param   mixed       $attributes     accepts null (default) to expand all attributes,
     *                                      string specifying a single attribute or an array
     *                                      of attribute names.
     * @return  CommandResult               provides a fluent interface
     * @todo    Add support for indicies with commas (nested sequences)
     */
    public function expandSequences($attributes = null)
    {
        if (is_string($attributes)) {
            $attributes = array($attributes);
        }

        if ($attributes !== null && !is_array($attributes)) {
            throw new \InvalidArgumentException('Attribute must be null, string or array of strings');
        }

        // expand specified numbered sequences in data array.
        for ($i = 0; $i < count($this->data); $i++) {

            // skip any data blocks that are not in array format
            if (!is_array($this->data[$i])) {
                continue;
            }

            foreach ($this->data[$i] as $key => $value) {

                // pull sequences off of key (ie. View0, View1, ...).
                // skips entry if it doesn't have a trailing number
                if (preg_match('/(.*?)(([0-9]+,)?[0-9]+)$/', $key, $matches) !== 1) {
                    continue;
                }

                // pull out the base and index
                $base  = $matches[1];
                $index = $matches[2];

                // if we have a specified list of attribute(s) and this
                // base isn't listed skip it.
                if ($attributes !== null && !in_array($base, $attributes)) {
                    continue;
                }

                // if base doesn't exist, initialize it to an array
                // if we already have an entry for base that isn't an array, skip expansion
                if (!array_key_exists($base, $this->data[$i])) {
                    $this->data[$i][$base] = array();
                } elseif (!is_array($this->data[$i][$base])) {
                    continue;
                }

                // handle sub-indices (e.g. p4 filelog's how1,0)
                if (strpos($index, ',') !== false) {
                    list($index, $subIndex) = explode(',', $index, 2);
                    $value = array_key_exists($index, $this->data[$i][$base])
                        ? $this->data[$i][$base][$index] + array($subIndex => $value)
                        : array($subIndex => $value);
                }

                $this->data[$i][$base][$index] = $value;
                unset($this->data[$i][$key]);
            }
        }

        return $this;
    }
}
