<?php
/**
 * Provides a base for singular spec models such as protections,
 * triggers, typemap, etc. to extend.
 *
 * Keyed specs such as changes, jobs, users, etc. should extend
 * P4\Spec\PluralAbstract.
 *
 * When extending this class, be sure to set the SPEC_TYPE const
 * to the name of the Perforce Specification Type (e.g. protect,
 * typemap, etc.)
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Spec\Exception\Exception;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\FieldedAbstract;

class SingularAbstract extends FieldedAbstract
{
    const SPEC_TYPE             = null;

    protected $needsPopulate    = false;
    protected $specDefinition   = null;

    /**
     * Get this spec from Perforce.
     * Creates a new spec instance and schedules a populate.
     *
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @return  PluralAbstract          instace of the requested entry.
     * @throws  \InvalidArgumentException   if no id is given.
     */
    public static function fetch(ConnectionInterface $connection = null)
    {
        $spec = new static($connection);
        $spec->deferPopulate();

        return $spec;
    }

    /**
     * Gets the definition of this specification from Perforce.
     *
     * The specification definition provides: field names,
     * field types, field options, preset values, comments, etc.
     *
     * Only fetches it once per instance. Additionally, the spec
     * definition object has a per-process (static) cache.
     *
     * @return  Definition      instance containing details about this spec type.
     */
    public function getSpecDefinition()
    {
        // load the spec definition if we haven't already done so.
        if (!$this->specDefinition instanceof Definition) {
            $this->specDefinition = Definition::fetch(
                static::SPEC_TYPE,
                $this->getConnection()
            );
        }

        return $this->specDefinition;
    }

    /**
     * Get all of the spec field names.
     * Extended to pull fields from the spec definition.
     *
     * @return  array   a list of field names for this spec.
     */
    public function getFields()
    {
        $fields = $this->getSpecDefinition()->getFields();
        return array_keys($fields);
    }

    /**
     * Get all of the required fields.
     *
     * @return  array   a list of required fields for this spec.
     */
    public function getRequiredFields()
    {
        $fields = array();
        $spec   = $this->getSpecDefinition();
        foreach ($this->getFields() as $field) {
            if ($spec->isRequiredField($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Save this spec to Perforce.
     *
     * @return  SingularAbstract    provides a fluent interface
     */
    public function save()
    {
        // ensure all required fields have values.
        $this->validateRequiredFields();

        $this->getConnection()->run(
            static::SPEC_TYPE,
            "-i",
            $this->getRawValues()
        );

        // should re-populate (server may change values).
        $this->deferPopulate(true);

        return $this;
    }

    /**
     * Get a field's raw value (avoids accessor).
     *
     * Extended to limit to defined fields, to lazy-load values and to
     * pull default values from the spec definition for required fields.
     *
     * @param   string  $field  the name of the field to get the value of.
     * @return  mixed           the value of the field.
     * @throws  Exception       if the field does not exist.
     */
    public function getRawValue($field)
    {
        // if field doesn't exist, throw exception.
        if (!$this->hasField($field)) {
            throw new Exception("Can't get the value of a non-existant field.");
        }

        // if field has not been set, populate.
        if (!array_key_exists($field, $this->values)) {
            $this->populate();
        }

        // if field has a value, return it.
        if (array_key_exists($field, $this->values)) {
            return $this->values[$field];
        }

        // get default value if field is required - return null for
        // optional fields so that they don't get values automatically.
        // optional field defaults are best handled by the server.
        if ($this->getSpecDefinition($this->getConnection())->isRequiredField($field)) {
            return $this->getDefaultValue($field);
        } else {
            return null;
        }
    }

    /**
     * Set a field's raw value (avoids mutator).
     * Extended to limit to setting defined fields that are not read-only.
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  SingularAbstract    provides a fluent interface
     * @throws  Exception           if the field does not exist or is read-only
     */
    public function setRawValue($field, $value)
    {
        // if field doesn't exist, throw exception.
        if (!$this->hasField($field)) {
            throw new Exception("Can't set the value of a non-existant field.");
        }

        // if field is read-only, throw exception.
        if ($this->getSpecDefinition()->isReadOnlyField($field)) {
            throw new Exception("Can't set the value of a read-only field.");
        }

        $this->values[$field] = $value;

        return $this;
    }

    /**
     * Schedule populate to run when data is requested (lazy-load).
     *
     * @param   bool    $reset  optionally clear instance values.
     */
    public function deferPopulate($reset = false)
    {
        $this->needsPopulate = true;

        if ($reset) {
            $this->values = array();
        }
    }

    /**
     * Get the values for this spec from Perforce and set them
     * in the instance. Won't clobber existing values.
     */
    protected function populate()
    {
        // early exit if populate not needed.
        if (!$this->needsPopulate) {
            return;
        }

        // get spec data from Perforce.
        $data = $this->getSpecData();

        // ensure fields is an array.
        if (!is_array($data)) {
            throw new Exception("Failed to populate spec. Perforce result invalid.");
        }

        // copy field values to instance without clobbering.
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $this->values)) {
                $this->values[$key] = $value;
            }
        }

        // clear needs populate flag.
        $this->needsPopulate = false;
    }

    /**
     * Get a field's default value.
     *
     * @param   string  $field  the name of the field to get the default value of.
     * @return  mixed   the default value of the field.
     */
    protected function getDefaultValue($field)
    {
        $definition = $this->getSpecDefinition();
        $field      = $definition->getField($field);

        if (isset($field['default'])) {
            return $definition::expandDefault($field['default'], $this->getConnection());
        } else {
            return null;
        }
    }

    /**
     * Get raw spec data direct from Perforce. No caching involved.
     *
     * @return  array   $data   the raw spec output from Perforce.
     */
    protected function getSpecData()
    {
        $result = $this->getConnection()->run(static::SPEC_TYPE, "-o");
        return $result->expandSequences()->getData(-1);
    }

    /**
     * Ensure that all required fields have values.
     *
     * @param   array   $values     optional - set of values to validate against
     *                              defaults to instance values.
     * @throws  Exception           if any required fields are missing values.
     */
    protected function validateRequiredFields($values = null)
    {
        $values = (array) $values ?: $this->getRawValues();

        // check that each required field has a value.
        foreach ($this->getRequiredFields() as $field) {

            $value = isset($values[$field]) ? $values[$field] : null;

            // in order to satisfy a required field, array values
            // must have elements and all values must have string length.
            if ((is_array($value) && !count($value)) || (!is_array($value) && !strlen($value))) {
                $missing[] = $field;
            }

        }

        if (isset($missing)) {
            throw new Exception(
                "Cannot save spec. Missing required fields: " . implode(", ", $missing)
            );
        }
    }
}
