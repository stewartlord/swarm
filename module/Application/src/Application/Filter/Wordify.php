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

class Wordify extends AbstractFilter
{
    /**
     * Splits a string into words on dash, underscore or capital letters (camel-case), and capitalizes
     * each word.
     *
     * @param  string $value the string to wordify
     * @return string the wordified string
     */
    public function filter($value)
    {
        // underscores and dashes become spaces
        $value = str_replace(array('_', '-'), ' ', $value);
        // camel-case conversion algorithm:
        //  - aA gets split into "a A"
        //  - Aa gets a leading space
        //  - aa and AA are left alone
        $value = preg_replace(array('/([a-z])([A-Z])/', '/([A-Z][a-z])/'), array('$1 $2', ' $1'), $value);
        // normalize to one space between words, clean up whitespace on the ends, and capitalize words
        return ucwords(trim(preg_replace('/\s{2,}/', ' ', $value)));
    }
}
