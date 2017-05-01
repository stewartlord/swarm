<?php
/**
 * Provides a common container for a set of models.
 *
 * Advantage of extending ArrayIterator is that php built-in
 * array-walk functions reset(), next(), key(), current()
 * can be replaced by class-implemented counterparts
 * and vice versa. In other words, if $iterator is an instance
 * of P4\Model\Iterator class then $iterator->next()
 * and next($iterator) are equivalent and same for all other pairs.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Model\Fielded;

use P4\Model\Connected\Iterator as ConnectedIterator;

class Iterator extends ConnectedIterator
{
    const SORT_ASCENDING            = 'ASC';
    const SORT_DESCENDING           = 'DESC';
    const SORT_ALPHA                = 'ALPHA';
    const SORT_NUMERIC              = 'NUMERIC';
    const SORT_NATURAL              = 'NATURAL';
    const SORT_NO_CASE              = 'NO_CASE';
    const SORT_FIXED                = 'FIXED';

    const FILTER_NO_CASE            = 'NO_CASE';
    const FILTER_CONTAINS           = 'CONTAINS';
    const FILTER_STARTS_WITH        = 'STARTS_WITH';
    const FILTER_REGEX              = 'REGEX';
    const FILTER_MATCH_ALL          = 'MATCH_ALL';
    const FILTER_IMPLODE            = 'IMPLODE';

    /**
     * Define the type of models we want to accept in this iterator.
     */
    protected $allowedModelClass    = 'P4\Model\Fielded\FieldedInterface';

    /**
     * Get the iterator data as an array.
     * Calls toArray() on all of the models unless 'shallow' is true.
     *
     * @param   bool    $shallow    optional - set shallow to true to avoid calling toArray()
     *                              on each of the models - defaults to false.
     * @return  array   all model data as an array.
     */
    public function toArray($shallow = false)
    {
        if ($shallow) {
            return $this->getArrayCopy();
        }

        $data = array();
        foreach ($this->getArrayCopy() as $key => $model) {
            $data[$key] = $model->toArray();
        }
        return $data;
    }

    /**
     * Reorder models by the given field(s).
     *
     * Multiple fields can be specified to produce a nested sort.
     * Comparison behavior defaults to alphabetical, ascending order.
     * Use the options argument to produce a different order.
     *
     * When sorting on multiple fields, separate options can be given
     * for each field by setting the entry key to the field name and
     * the value to the array of sort options to use for that field.
     *
     * Alternatively, each entry in fields may be an array with two
     * parts where the first part is the field name and the second is
     * the array of sort options to use for that field (can be used to
     * sort on the same field twice with different options).
     *
     * Valid sorting options are:
     *
     *    SORT_ASCENDING - default direction
     *   SORT_DESCENDING - reverse direction
     *        SORT_ALPHA - default alphabetic order comparison
     *      SORT_NUMERIC - perform numeric comparison
     *      SORT_NATURAL - perform natural order comparison
     *      SORT_NO_CASE - perform case-insensitive comparison
     *        SORT_FIXED - put entries in a prescribed order
     *                     e.g. SORT_FIXED => array(val, val, ...)
     *
     * @param   array|string    $fields     one or more fields to order by
     *                                      if multiple fields are specified,
     *                                      performs a nested sort.
     * @param   array           $options    optional - one or more sorting options
     * @return  Iterator        provides fluent interface.
     */
    public function sortBy($fields, $options = array())
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException(
                "Cannot sort. Sort options must be an array."
            );
        }

        // normalize fields to an array.
        $fields = (array) $fields;

        // determine comparison function to use for each field.
        $comparators = array();
        foreach ($fields as $key => $value) {

            // three ways to specify fields + options:
            //  - common case: key is an integer and value is a string,
            //    takes value as field name and second func. param as options.
            //  - if key is a string and value is an array,
            //    takes key as field name and value as options.
            //  - if key is an integer and value is an array with two parts,
            //    takes first part as field name and second part as options.
            if (is_integer($key) && is_string($value)) {
                $comparators[] = array($value, $this->getSortComparator($options));
            } elseif (is_string($key) && is_array($value)) {
                $comparators[] = array($key, $this->getSortComparator($value));
            } elseif (is_integer($key) && is_array($value) && count($value) == 2) {
                $comparators[] = array($value[0], $this->getSortComparator($value[1]));
            } else {
                throw new \InvalidArgumentException("Cannot sort. Invalid sort field(s) given.");
            }

        }

        // perform sort.
        // uses '@' to silence warnings about array being modified by
        // comparison function - can occur due to lazy loading.
        @$this->uasort(
            function ($a, $b) use ($comparators) {
                foreach ($comparators as $comparator) {
                    $result = call_user_func(
                        $comparator[1],
                        Iterator::implodeValue($a->get($comparator[0])),
                        Iterator::implodeValue($b->get($comparator[0]))
                    );

                    // if values are equal, compare the next field.
                    if (!$result) {
                        continue;
                    }

                    return $result;
                }

                return 0;
            }
        );

        return $this;
    }

    /**
     * Reorder models using a callback function for the comparison.
     * Effectively just a wrapper for the uasort() method.
     *
     * @param   callable    $callback   the function to pass to uasort().
     * @return  Iterator    provides fluent interface.
     */
    public function sortByCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot sort iterator. Given callback is not callable."
            );
        }

        // perform sort.
        // uses '@' to silence warnings about array being modified by
        // comparison function - can occur due to lazy loading.
        @$this->uasort($callback);

        return $this;
    }

    /**
     * Implodes arrays into comma-separated strings, returns non-arrays unmodified.
     *
     * @param   mixed  $value  A value to be imploded; only arrays will be modified.
     * @return  mixed  An imploded array, or unmodified value.
     */
    public static function implodeValue($value)
    {
        return is_array($value) ? implode(', ', $value) : $value;
    }

    /**
     * Filter items of this instance.
     *
     * You may specify one or more fields to check for one or more acceptable
     * values. Models that do not have acceptable values will be removed from
     * the iterator.
     *
     * Valid filter options are:
     *
     *     FILTER_NO_CASE - perform case insensitive comparisons
     *    FILTER_CONTAINS - fields only need to contain a value to match
     * FILTER_STARTS_WITH - fields only need to start with a value to match
     *       FILTER_REGEX - value is a regular expression
     *     FILTER_INVERSE - inverse filtering behavior - items that match are removed
     *   FILTER_MATCH_ALL - require all values to match at least once per model
     *        FILTER_COPY - return a filtered copy without modifying original
     *     FILTER_IMPLODE - fields that contain arrays will be flattened prior to matching
     *
     * @param   string|array    $fields     one or more fields to check for acceptable values.
     * @param   string|array    $values     one or more acceptable values/patterns
     * @param   string|array    $options    optional - one or more filtering options
     * @return  Iterator        provides fluent interface
     */
    public function filter($fields, $values, $options = array())
    {
        // normalize arguments to arrays.
        $fields  = is_null($fields) ? $fields : (array) $fields;
        $values  = (array) $values;
        $options = (array) $options;
        $copy    = new static;

        // remove items that don't pass the filter.
        foreach ($this->getArrayCopy() as $key => $model) {
            $passesFilter = $this->passesFilter($model, $fields, $values, $options);

            // inverse behavior if FILTER_INVERSE option is set
            if (in_array(static::FILTER_INVERSE, $options, true)) {
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
     * Search (filters) this iterator instance by user-provided query.
     *
     * Splits the given query string on whitespace and comma,
     * then filters the iterator with the following options:
     *
     *    FILTER_NO_CASE - perform case insensitive comparisons
     *   FILTER_CONTAINS - fields only need to contain a value to match
     *    FILTER_IMPLODE - fields that contain arrays will be flattened prior to matching
     *  FILTER_MATCH_ALL - require all values to match at least once per model
     *
     * The options can be overridden via the optional $options param.
     *
     * @param   array|string    $fields     the fields to match on
     * @param   string          $query      the user-supplied search string
     * @param   array           $options    optional - flags to pass to the filter.
     * @return  Iterator        provides fluent interface.
     */
    public function search($fields, $query, array $options = null)
    {
        // normalize fields to array.
        $fields = (array) $fields;

        // split query into words.
        $query = preg_split('/[\s,]+/', trim($query));

        // use default options if none provided.
        $options = $options !== null ? $options : array(
            Iterator::FILTER_CONTAINS,
            Iterator::FILTER_NO_CASE,
            Iterator::FILTER_IMPLODE,
            Iterator::FILTER_MATCH_ALL
        );

        // remove models that don't match search query.
        return $this->filter($fields, $query, $options);
    }

    /**
     * Check if model passes the given filter criteria.
     *
     * @param   P4\ModelInterface   $model      the model to test against filter
     * @param   string|array        $fields     one or more fields to check for
     *                                          acceptable values
     * @param   string|array        $values     one or more acceptable values/patterns
     * @param   string|array        $options    optional - one or more filtering options
     * @return  bool                true if the model passes filter; false otherwise
     */
    protected function passesFilter($model, $fields, $values, $options)
    {
        $fields = is_array($fields)
            ? array_intersect($fields, $model->getFields())
            : $model->getFields();

        $matches = array();
        $matchAll = in_array(static::FILTER_MATCH_ALL, $options, true);

        foreach ($fields as $field) {
            $value = $model->get($field);
            foreach ($values as $filter) {
                if ($this->valueMatches($value, $filter, $options)) {
                    $matches[$filter] = true;

                    // exit if we have satisfied match.
                    if (!$matchAll || count($matches) == count($values)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Evaluate if value matches given filter with respect to options.
     *
     * @param   string  $value      the value to test against filter/pattern
     * @param   string  $filter     the filter/pattern to match against
     * @param   array   $options    filter options
     * @return  bool    true if the value matches the filter.
     */
    protected function valueMatches($value, $filter, $options)
    {
        // array comparisons require FILTER_IMPLODE so we can convert to a string
        if (is_array($value) && in_array(static::FILTER_IMPLODE, $options, true)) {
            $value = static::implodeValue($value);
        }

        // evaluate matching against null
        if (is_null($filter)) {
            return is_null($value);
        }

        // evaluate only string, numeric, and boolean values
        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
            return false;
        }

        $noCase = in_array(static::FILTER_NO_CASE, $options, true);

        // perform 'contains' comparison.
        if (in_array(static::FILTER_CONTAINS, $options, true)) {
            return false !== ($noCase
                ? stripos($value, $filter)
                : strpos($value, $filter));
        }

        // perform 'starts with' comparison.
        if (in_array(static::FILTER_STARTS_WITH, $options, true)) {
            return 0 === ($noCase
                ? stripos($value, $filter)
                : strpos($value, $filter));
        }

        // perform 'regex' comparison.
        if (in_array(static::FILTER_REGEX, $options, true)) {
            // make pattern case insensitive if no-case set.
            if ($noCase) {
                $filter .= 'i';
            }

            return preg_match($filter, $value);
        }

        // default literal/exact comparison.
        return 0 === ($noCase
            ? strcasecmp($value, $filter)
            : strcmp($value, $filter));
    }

    /**
     * Return the appropriate comparison function to use
     * for the given sort options.
     *
     * @param   array   $options    sort options @see sortBy()
     * @return  mixed   a callable comparison function.
     */
    protected function getSortComparator($options)
    {
        // ensure options are in an expected format.
        $options = $this->normalizeSortOptions($options);

        // select the comparison function to use based on flags given.
        if ($options[static::SORT_FIXED]) {
            $order      = array_flip((array) $options[static::SORT_FIXED]);
            $comparator = function ($a, $b) use ($order) {
                $c = isset($order[$a]) ? $order[$a] : PHP_INT_MAX;
                $d = isset($order[$b]) ? $order[$b] : PHP_INT_MAX;
                // fall back to default comparison if not all values specified
                if ($c === PHP_INT_MAX and $d === PHP_INT_MAX) {
                    return strcmp($a, $b);
                }
                return $c - $d;
            };
        } elseif ($options[static::SORT_NUMERIC]) {
            $comparator = function ($a, $b) {
                // for float numbers comparison.
                // round() function does not work here since round(-0.01) = -0,
                // but array.sort() expects -1.
                $c = $a - $b;
                if ($c < 0) {
                    return -1;
                } elseif ($c > 0) {
                    return 1;
                }
                return 0;
            };
        } elseif ($options[static::SORT_NATURAL] && $options[static::SORT_NO_CASE]) {
            $comparator = 'strnatcasecmp';
        } elseif ($options[static::SORT_NATURAL]) {
            $comparator = 'strnatcmp';
        } elseif ($options[static::SORT_NO_CASE]) {
            $comparator = 'strcasecmp';
        } else {
            $comparator = 'strcmp';
        }

        // optionally reverse the sort order by
        // inverting result of comparison function.
        if ($options[static::SORT_DESCENDING]) {
            return function ($a, $b) use ($comparator) {
                return call_user_func($comparator, $b, $a);
            };
        }

        return $comparator;
    }

    /**
     * Normalize sort options to ensure consistent structure
     * and to catch invalid/malformed options.
     *
     * @param   array   $options            sort options @see sortBy()
     * @return  array                       the normalized options array.
     * @throws  \InvalidArgumentException   if invalid/malformed options are found.
     */
    protected function normalizeSortOptions(array $options)
    {
        // ensure options are specified as option => value
        // instead of having the option name as the value
        // (value can be true/false or an array in the case
        // of sort fixed).
        $validSortOptions  = $this->getValidSortOptions();
        $normalizedOptions = array_fill_keys($validSortOptions, false);
        foreach ($options as $key => $value) {

            // check if the key is a valid sort option.
            // if not, the value must be the sort option
            // otherwise, it's invalid.
            if (in_array($key, $validSortOptions, true)) {
                $normalizedOptions[$key] = $value;
            } elseif (in_array($value, $validSortOptions, true)) {
                $normalizedOptions[$value] = true;
            } else {
                throw new \InvalidArgumentException(
                    "Unexpected sort option(s) encountered."
                );
            }
        }

        return $normalizedOptions;
    }

    /**
     * Get a list of the available sorting options.
     *
     * @return  array   all valid sort options.
     */
    protected function getValidSortOptions()
    {
        return array(
            static::SORT_ALPHA,
            static::SORT_ASCENDING,
            static::SORT_DESCENDING,
            static::SORT_FIXED,
            static::SORT_NATURAL,
            static::SORT_NO_CASE,
            static::SORT_NUMERIC
        );
    }
}
