<?php
/**
 * Abstracts operations against Perforce counters.
 *
 * This class is somewhat unique as calling set will immediately write the new value
 * to perforce; no separate save step is required.
 * When reading values out we do attempt to use cached results, to ensure you read
 * out the value directly from perforce set $force to true when calling get.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Counter;

use P4\Counter\AbstractCounter;

class Counter extends AbstractCounter
{
    /**
     * Delete this counter entry.
     *
     * @param   bool    $force      optional - force delete the counter.
     * @return  Counter             provides a fluent interface
     * @throws  Exception           if no id has been set.
     */
    public function delete($force = false)
    {
        return parent::doDelete($force);
    }

    /**
     * Set counters value. The value will be immediately written to perforce.
     *
     * @param   mixed   $value  the value to set in the counter.
     * @param   bool    $force  optional - force set the counter.
     * @return  Counter         provides a fluent interface
     * @throws  Exception       if no Id has been set
     */
    public function set($value, $force = false)
    {
        return parent::doSet($value, $force);
    }
}
