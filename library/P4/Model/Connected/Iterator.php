<?php
/**
 * Provide a common container for a set of models.
 *
 * Advantage of extending ArrayIterator is that php built-in
 * array-walk functions reset(), next(), key(), current()
 * can be replaced by class-implemented counterparts
 * and vice versa. In other words, if $iterator is an instance
 * of P4\Iterator class then $iterator->next()
 * and next($iterator) are equivalent and same for all other pairs.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Model\Connected;

class Iterator extends \ArrayIterator
{
    const FILTER_INVERSE            = 'INVERSE';
    const FILTER_COPY               = 'COPY';

    /**
     * Define the type of models we want to accept in this iterator.
     */
    protected $allowedModelClass    = 'P4\Model\Connected\ConnectedInterface';

    /**
     * Store custom iterator properties
     */
    protected $properties           = array();

    /**
     * Create a new model iterator.
     * If an array of models is given, populate from the array.
     *
     * @param   array   $models  optional - the set of models to contain.
     */
    public function __construct($models = null)
    {
        if (isset($models) && is_array($models)) {
            foreach ($models as $model) {
                if (!$model instanceof $this->allowedModelClass) {
                    throw new \InvalidArgumentException("Models array contains one or more invalid elements.");
                }
            }
            parent::__construct($models);
        } else {
            parent::__construct(array());
        }
    }

    /**
     * Set the model under the given key
     *
     * @param string|integer    $key    the key to store the model under.
     * @param Model             $model  the model to store.
     * @return void
     */
    public function offsetSet($key, $model)
    {
        if (!$model instanceof $this->allowedModelClass) {
            throw new \InvalidArgumentException("Invalid model supplied.");
        }
        return parent::offsetSet($key, $model);
    }

    /**
     * Extend offsetExists to handle null case without warnings.
     * This provides parity with isset() against standard arrays.
     *
     * @param   string|integer  $key    the key to check for.
     * @return  bool            true if the offset exists, false otherwise.
     */
    public function offsetExists($key)
    {
        if ($key === null) {
            return false;
        }

        return parent::offsetExists($key);
    }

    /**
     * Seek to an absolute position.
     *
     * @param   integer     $position   the numeric position to seek to.
     */
    public function seek($position)
    {
        if (!is_integer($position)) {
            throw new \OutOfBoundsException('Invalid seek position.');
        }
        $this->rewind();
        $current = 0;
        while ($current < $position && $this->valid()) {
            $this->next();
            $current++;
        }
        if (!$this->valid()) {
            throw new \OutOfBoundsException('Invalid seek position.');
        }
    }

    /**
     *  Overwrite next() method to return current value or false.
     *
     *  If php built-in next() function is not called then array
     *  pointer is not advanced and other php array-walk functions
     *  like current() or key() won't work.
     *
     *  @return false | mixed
     */
    public function next()
    {
        parent::next();
        next($this);
        return $this->valid() ? $this->current() : false;
    }

    /**
     *  Overwrite rewind() method to reset array pointer.
     *
     *  If php built-in reset() function is not called then array
     *  pointer is not advanced and other php array-walk functions
     *  like current() or key() won't work.
     */
    public function rewind()
    {
        parent::rewind();
        reset($this);
    }

    /**
     * Return the key of the array element that's currently being
     * pointed to by the internal pointer.
     * It does not move the pointer in any way.
     *
     * @return string|integer|null
     */
    public function key()
    {
        return key($this);
    }

    /**
     * Get all of the keys for all of the entries in this iterator.
     *
     * @return  array   a list of the keys in the iterator.
     */
    public function keys()
    {
        return array_keys($this->getArrayCopy());
    }

    /**
     * Return the value of the array element that's currently being
     * pointed to by the internal pointer.
     * It does not move the pointer in any way.
     *
     * @return Model
     */
    public function current()
    {
        return current($this);
    }

    /**
     * Get the value of the first element.
     *
     * @return ModelInterface
     */
    public function first()
    {
        $this->rewind();
        return $this->current();
    }

    /**
     * Get the value of the last element.
     *
     * @return  ModelInterface
     */
    public function last()
    {
        end($this);
        return $this->current();
    }

    /**
     * Get the value of the Nth element.
     *
     * @param   integer     $position   the numeric position to seek to.
     * @return  ModelInterface
     */
    public function nth($position)
    {
        $this->seek($position);
        return $this->current();
    }

    /**
     * Will run the specified function on each entry in the iterator, optionally
     * passing arguments.
     *
     * An array of function return values will be returned.
     *
     * @param   string  $functionName   The name of the function to execute
     * @param   array   $params         Optional array of paramaters to pass the function
     * @returns array                   Array of return values
     * @throws  \InvalidArgumentException   If any entry lacks the specified function
     */
    public function invoke($functionName, $params = array())
    {
        $results = array();

        foreach ($this as $entry) {
            if (!is_object($entry) || !method_exists($entry, $functionName)) {
                throw new \InvalidArgumentException(
                    'One or more entries lack the specified function'
                );
            }

            $results[] = call_user_func_array(array($entry, $functionName), $params);
        }

        return $results;
    }

    /**
     * Filter items of this instance by callback function.
     *
     * Callback must be callable function with at least one
     * parameter represents the allowed model class instance.
     *
     * Additional parameters can be set in params, in this
     * case callback will be called with model instance
     * parameter followed by params.
     *
     * Item (model) is acceptable if and only if callback
     * function with item passed as first parameter returns
     * true.
     *
     * Valid filter options are:
     *
     *   FILTER_INVERSE - inverse filtering behavior - acceptable items are removed
     *      FILTER_COPY - return a filtered copy without modifying original
     *
     * @param  callback     $callback   callback function to determine if item is acceptable
     * @param  mixed        $params     optional additional callback parameters
     * @param  string|array $options    optional - one or more filtering options
     * @return Iterator     provides fluent interface
     */
    public function filterByCallback($callback, $params = null, $options = array())
    {
        if (!is_callable($callback)) {
            throw new \Exception('Callback for P4\Iterator must be callable function.');
        }

        $copy = new static;

        // remove items where callback returns false
        foreach ($this->getArrayCopy() as $key => $model) {

            $passesFilter = call_user_func_array($callback, array($model, $params));

            // inverse behavior if FILTER_INVERSE option is set
            if (in_array(self::FILTER_INVERSE, $options)) {
                $passesFilter = !$passesFilter;
            }

            if (!$passesFilter && !in_array(static::FILTER_COPY, $options, true)) {
                $this->offsetUnset($key);
            } elseif ($passesFilter && in_array(static::FILTER_COPY, $options, true)) {
                $copy[$key] = $model;
            }
        }

        return in_array(static::FILTER_COPY, $options, true) ? $copy : $this;
    }

    /**
     * Merges the passed iterator's values into this iterator.
     *
     * If the input iterator has the same string keys, then the later value for
     * that key will overwrite the previous one. If, however, the key are numeric,
     * the later value will not overwrite the original value, but will be appended.
     *
     * @param   Iterator        $iterator   The new values to merge in
     * @return  Model\Iterator              provides fluent interface
     */
    public function merge(Iterator $iterator)
    {
        foreach ($iterator as $key => $value) {
            if (is_int($key)) {
                $this[] = $value;
            } else {
                $this[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Check if iterator has a particular property.
     *
     * @param   string  $name   the property name to check for the existence of
     * @return  boolean         true if the iterator has the named property, false otherwise.
     */
    public function hasProperty($name)
    {
        return array_key_exists($name, $this->properties);
    }

    /**
     * Get a particular property value of this iterator.
     *
     * @param   string  $name               name of the property to get the value of
     * @return  mixed                       the value of the property name
     * @throws  \InvalidArgumentException   if the property name does not exist
     */
    public function getProperty($name)
    {
        // return property value if it was set, otherwise throw an exception
        if ($this->hasProperty($name)) {
            return $this->properties[$name];
        }

        throw new \InvalidArgumentException(
            "Cannot find iterator property '$name'. Property was not set."
        );
    }

    /**
     * Get all properties of this iterator.
     *
     * @return  array   all properties set to this iterator
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * Set a particular property of this iterator.
     *
     * @param   string          $name   name of the property to set the value of
     * @param   mixed           $value  value to set
     * @return  Iterator        provides a fluent interface
     */
    public function setProperty($name, $value)
    {
        $this->properties[$name] = $value;

        return $this;
    }

    /**
     * Set iterator properties.
     *
     * @param   array   $properties     array with properties to set
     * @return  Iterator                provides a fluent interface
     */
    public function setProperties(array $properties)
    {
        $this->properties = $properties;

        return $this;
    }
}
