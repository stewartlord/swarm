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

/**
 * PHP supports using 'shorthand' bytes in INI directives see:
 * http://www.php.net/manual/en/faq.using.php#faq.using.shorthandbytes
 *
 * For a nice example of how odd/bad values are handled see:
 * http://hakre.wordpress.com/2011/06/09/protocol-of-some-php-memory-stretching-fun/
 *
 * This class assists in going from bytes => shorthand or shorthand => bytes
 * to assist in dealing with these values.
 */
class ShorthandBytes extends AbstractFilter
{
    /**
     * Converts the passed value to PHP shorthand byte format. If its already
     * in shorthand format this ensures its a compact representation.
     *
     * @param   string|int  $value  the value to ensure is in shorthand format
     * @return  string      passed value in shorthand bytes format
     */
    public function filter($value)
    {
        return static::toShorthand($value);
    }

    /**
     * Converts the passed value to bytes. Input can be PHP shorthand or already bytes.
     *
     * @param   string|int|null $value  the value to convert to bytes
     * @return  int             the size converted to bytes
     * @throws  \InvalidArgumentException   if passed value is invalid type
     */
    public static function toBytes($value)
    {
        if (!is_int($value) && !is_string($value) && !is_null($value)) {
            throw new \InvalidArgumentException('Can only convert int string or null to bytes.');
        }

        // deal with the easy case of empty or already a number
        if (empty($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        // for other values PHP is quite forgiving basically it follows the int casting approach
        // to get any leading digits ignoring everything after them to get the number.
        // it then grabs the last character and if its one of g/m/k (case insensitive) uses that for units.
        // note, unrecognized units are just treated as bytes as per docs.
        $size = (int) $value;
        $last = substr(strtolower(trim($value)), -1);
        if ($last == 'g') {
            $size = $size * 1024 * 1024 * 1024;
        } elseif ($last == 'm') {
            $size = $size * 1024 * 1024;
        } elseif ($last == 'k') {
            $size = $size * 1024;
        }

        return $size;
    }

    /**
     * Converts the passed value to 'shorthand' assuming that can be done without losing precision.
     * If value won't fit evenly into kilobytes, megabytes or gigabytes its left as bytes.
     *
     * @param   $value  the value to put into shorthand format, shorthand inputs are supported
     * @return  string  the value in the most compact shorthand possible (may still be bytes)
     */
    public static function toShorthand($value)
    {
        $size = static::toBytes($value);

        $kb = 1024;
        $mb = $kb * 1024;
        $gb = $mb * 1024;

        if ($size >= $gb && $size % $gb == 0) {
            return ($size / $gb) . 'G';
        }
        if ($size >= $mb && $size % $mb == 0) {
            return ($size / $mb) . 'M';
        }
        if ($size >= $kb && $size % $kb == 0) {
            return ($size / $kb) . 'K';
        }

        return (string) $size;
    }
}
