<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Filter;

use P4\Filter\Utf8;
use Zend\Filter\AbstractFilter;

class StringToId extends AbstractFilter
{
    /**
     * Replaces spaces and special characters with hyphens and normalizes to lower-case.
     * For example, "Some Record Name!" becomes "some-record-name"
     *
     * Unicode is allowed to pass through after being filtered for invalid Utf8.
     *
     * An attempt will be made to replace uppercase unicode with dashes
     * if the mbstring extension is not installed.
     *
     * @param  string $value
     * @return string
     */
    public function filter($value)
    {
        $utf8  = new Utf8;
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[�\p{Lu}]+/u', '-', $utf8->filter($value));
        return trim(preg_replace('/[^a-z0-9\x80-\xFF]+/', '-', $value), '-');
    }
}
