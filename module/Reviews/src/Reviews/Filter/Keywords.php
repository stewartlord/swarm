<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\Filter;

use Zend\Filter\AbstractFilter;

class Keywords extends AbstractFilter
{
    protected $patterns = null;

    /**
     * Convenience constructor allows passing patterns at creation time.
     *
     * @param   array|null  $patterns   patterns to use, see setPatterns for details
     */
    public function __construct(array $patterns = null)
    {
        $this->setPatterns($patterns);
    }

    /**
     * Set the keyword patterns being used. We expect an array of patterns.
     * Each pattern will be a hash with, at least, the keys regex and spec.
     * Regex should use named capture groups to pull out the 'id'. The spec
     * utilizes %id% style placeholders to re-construct or update the keyword.
     * In addition to the id the spec can utilized any of the named captures.
     *
     * @param   array|null  $patterns   the patterns to use or null
     * @return  Keywords    to maintain a fluent interface
     */
    public function setPatterns(array $patterns = null)
    {
        $patterns = (array) $patterns;
        foreach ($patterns as $key => $pattern) {
            if (!isset($pattern['regex'], $pattern['spec'])) {
                unset($patterns[$key]);
            }
        }

        $this->patterns = array_values($patterns);
        return $this;
    }

    /**
     * Returns the currently specified array of keyword patterns.
     * See setPatterns for details.
     *
     * @return  array   array of patterns
     */
    public function getPatterns()
    {
        return (array) $this->patterns;
    }

    /**
     * Removes all keywords from the passed value and returns result.
     *
     * @param   string  $string     text that potentially contains keyword(s)
     * @return  string  text with all keywords stripped
     */
    public function filter($string)
    {
        foreach ($this->getPatterns() as $remove) {
            // use the strip pattern if we have one; otherwise use the regex
            $regex  = isset($remove['strip']) && $remove['strip'] ? $remove['strip'] : $remove['regex'];
            $string = preg_replace($regex, '', $string);
        }

        return $string;
    }

    /**
     * Scans the passed value for keywords. We'll utilize the first hit so
     * pattern order is important. If no patterns match returns false.
     * If a pattern is matched; its named capture groups are returned.
     * If no match occurs an empty array is returned.
     *
     * @param   string  $string     the value to scan for possible keywords
     * @return  array               array of found keyword values (empty if none)
     */
    public function getMatches($string)
    {
        // return the 'matches' off the first pattern that hits
        foreach ($this->getPatterns() as $pattern) {
            // if the pattern matches return matches
            if (preg_match($pattern['regex'], $string, $matches)) {
                return $matches;
            }
        }

        return array();
    }

    /**
     * Updates the passed string's keywords with the passed values.
     * Only changed values need be passed; any unspecified value that already
     * appears in the pattern will be passed to the spec.
     *
     * If more than one keyword pattern matches they will all be updated.
     *
     * If no keyword is present in passed value but one of our configured
     * patterns has an 'insert' specification, a keyword will be added so
     * long as the passed values array contains an 'id'.
     *
     * @param   string  $string     the string we are updating a keyword in
     * @param   array   $values     the value(s) to update in the keyword
     * @return  string  the string with updated keywords
     */
    public function update($string, array $values)
    {
        $updated = 0;
        foreach ($this->getPatterns() as $pattern) {
            $pattern += array('defaults' => array());
            $string   = preg_replace_callback(
                $pattern['regex'],
                function ($matches) use ($pattern, $values, &$updated) {
                    $updated++;
                    $updated = $pattern['spec'];
                    $matches = $values + $matches + (array) $pattern['defaults'];
                    foreach ($matches as $key => $value) {
                        $updated = str_replace("%$key%", $value, $updated);
                    }

                    return $updated;
                },
                $string
            );
        }

        // if we didn't locate any keywords; add one if we have the spec to
        // do so and were passed an id field
        if (!$updated && isset($values['id']) && strlen($values['id'])) {
            foreach ($this->getPatterns() as $pattern) {
                if (isset($pattern['insert']) && $pattern['insert']) {
                    $pattern += array('defaults' => array());
                    $values   = array('description' => trim($string)) + $values + (array) $pattern['defaults'];
                    $string   = $pattern['insert'];
                    foreach ($values as $key => $value) {
                        $string = str_replace("%$key%", $value, $string);
                    }
                    break;
                }
            }
        }

        return $string;
    }
}
