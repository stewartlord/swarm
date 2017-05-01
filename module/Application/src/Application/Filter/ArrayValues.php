<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Filter;

use Zend\Filter\AbstractFilter;

class ArrayValues extends AbstractFilter
{
    /**
     * If the input $value is an array, return its values without keys.
     * If the $value is null or an empty string, convert it to an empty array.
     * All other values are returned unmodified.
     *
     * @param  mixed    $value
     * @return mixed
     */
    public function filter($value)
    {
        // we do this because commonly the filter is used with an array validator
        // and form inputs with no value often end up as empty strings on post
        $value = $value === '' || $value === null ? array() : $value;

        // if value is an array, return it, throwing away any provided keys
        // otherwise, return the original value
        return is_array($value) ? array_values($value) : $value;
    }
}
