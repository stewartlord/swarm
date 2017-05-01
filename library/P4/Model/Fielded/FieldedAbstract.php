<?php
/**
 * Provides a base implementation for models that utilize fields.
 *
 * Declare your fields in the $fields array. For simple fields,
 * just add the field name to the array as a value. For more
 * complex fields, specify the field name as a key and provide
 * a configuration array as the value, like so:
 *
 *  $fields = array(
 *      'simple-field',
 *      'complex-field' => array(
 *          'accessor'  => 'get-method',
 *          'mutator'   => 'set-method'
 *      )
 *  );
 *
 * Accessor methods take no parameters and must return a value.
 * Mutator methods must accept a single value parameter. It is
 * recommended that mutator methods return $this to provide a
 * fluent interface.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Model\Fielded;

use P4\Model\Connected\ConnectedAbstract;

abstract class FieldedAbstract extends ConnectedAbstract implements FieldedInterface
{
    protected $fields = array();
    protected $values = array();

    /**
     * Get the model data as an array.
     * Excludes any fields marked as 'hidden'
     *
     * @return  array   the model data as an array.
     */
    public function toArray()
    {
        $values = array();
        foreach ($this->getFields() as $field) {
            if (!isset($this->fields[$field], $this->fields[$field]['hidden'])
                || !is_array($this->fields[$field])
                || !$this->fields[$field]['hidden']
            ) {
                $values[$field] = $this->get($field);
            }
        }

        return $values;
    }

    /**
     * Get field value. If a custom field accessor exists, it will be used.
     *
     * @param   string|null     $field  the name of the field to get the value of or null for all
     * @return  mixed           the value of the field(s).
     */
    public function get($field = null)
    {
        if ($field === null) {
            return $this->toArray();
        }

        // if field has custom accessor, use it.
        if (isset($this->fields[$field])
            && is_array($this->fields[$field])
            && isset($this->fields[$field]['accessor'])
        ) {
            return $this->{$this->fields[$field]['accessor']}();
        }

        return $this->getRawValue($field);
    }

    /**
     * Set field value. If a custom field mutator exists, it will be used.
     *
     * @param   string|array        $field  the name of the field to set the value of.
     * @param   mixed               $value  the value to set in the field.
     * @return  FieldedAbstract     provides a fluent interface
     */
    public function set($field, $value = null)
    {
        // if we were passed an array, do a set value for each entry
        if (is_array($field)) {
            foreach ($field as $key => $value) {
                $this->set($key, $value);
            }

            return $this;
        }

        // if field is read-only, throw if its being mutated
        if (isset($this->fields[$field])
            && is_array($this->fields[$field])
            && isset($this->fields[$field]['readOnly'])
            && $this->fields[$field]['readOnly']
            && $value !== $this->get($field)
        ) {
            throw new \InvalidArgumentException('The specified field is read-only.');
        }

        // if field has custom mutator, use it.
        if (isset($this->fields[$field])
            && is_array($this->fields[$field])
            && isset($this->fields[$field]['mutator'])
        ) {
            return $this->{$this->fields[$field]['mutator']}($value);
        }

        $this->setRawValue($field, $value);

        return $this;
    }

    /**
     * Check if this model has a particular field.
     *
     * @param   string      $field  the field to check for the existence of.
     * @return  boolean     true if the model has the named field, false otherwise.
     */
    public function hasField($field)
    {
        return in_array((string) $field, $this->getFields());
    }

    /**
     * Get all of the model field names.
     *
     * @return  array   a list of field names for this model.
     */
    public function getFields()
    {
        // defined fields appear first, ad-hoc fields appear last (keys from the values array)
        return array_unique(array_merge($this->getDefinedFields(), array_keys($this->values)));
    }

    /**
     * Get all of the field names that are defined in this model (excludes ad-hoc fields).
     *
     * @return  array   a list of defined field names for this model.
     */
    public function getDefinedFields()
    {
        // field names are taken from the fields array
        // if the element value is an array, we assume the key is the
        // field name; otherwise, we assume the value is the field name.
        $fields = array();
        foreach ($this->fields as $key => $value) {
            $fields[] = is_array($value) ? $key : $value;
        }

        return $fields;
    }

    /**
     * Get all of the field definitions.
     *
     * @return  array   a list of field definitions
     */
    public function getFieldDefinitions()
    {
        return $this->fields;
    }

    /**
     * Get a field's raw value (avoids accessor).
     *
     * @param   string  $field  the name of the field to get the value of.
     * @return  mixed           the value of the field.
     */
    public function getRawValue($field)
    {
        if (isset($this->values[$field])) {
            return $this->values[$field];
        }

        // if field has a default value, use it.
        if (isset($this->fields[$field])
            && is_array($this->fields[$field])
            && isset($this->fields[$field]['default'])
        ) {
            return $this->fields[$field]['default'];
        }

        return null;
    }

    /**
     * Set a field's raw value (avoids mutator).
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  FieldedAbstract     provides a fluent interface
     */
    public function setRawValue($field, $value)
    {
        $this->values[$field] = $value;

        return $this;
    }

    /**
     * Unset a field raw value (avoids mutator).
     *
     * @param   string  $field      the name of the field to unset.
     * @return  FieldedAbstract     provides a fluent interface
     */
    public function unsetRawValue($field)
    {
        unset($this->values[$field]);

        return $this;
    }

    /**
     * Test if a field raw value is set (avoids accessor).
     *
     * @param   string  $field      the name of the field to check.
     * @return  FieldedAbstract     provides a fluent interface
     */
    public function issetRawValue($field)
    {
        return isset($this->values[$field]);
    }

    /**
     * Get all of the raw field values (avoids accessors).
     *
     * @return  array   an associative array of field values.
     */
    public function getRawValues()
    {
        $values = array();
        foreach ($this->getFields() as $field) {
            $values[$field] = $this->getRawValue($field);
        }
        return $values;
    }

    /**
     * Set several of the spec's raw values at once (avoids mutators).
     *
     * @param   array   $values     associative array of raw field values.
     * @return  FieldedAbstract     provides a fluent interface
     */
    public function setRawValues($values)
    {
        foreach ($values as $field => $value) {
            $this->values[$field] = $value;
        }

        return $this;
    }
}
