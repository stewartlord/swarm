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

class GitInfo extends AbstractFilter
{
    protected $value;

    /**
     * Allow, optionally, passing a value to the constructor to shorten up end usage.
     *
     * @param   null|string     $value  optional - a value to set on this instance
     */
    public function __construct($value = null)
    {
        $this->setValue($value);
    }

    /**
     * Set a value to be used for subsequent calls to get, hasInfo, getDescription.
     * The value is expected to be a changelist description which may contain git
     * fusion key/value pairs.
     *
     * @param   string|null     $value  the value to set on this instance
     * @return  GitInfo         to maintain a fluent interface
     * @throws  \InvalidArgumentException   if the value isn't an expected type
     */
    public function setValue($value)
    {
        if (!is_string($value) && !is_null($value)) {
            throw new \InvalidArgumentException('Invalid type given for value.');
        }

        $this->value = $value;
        return $this;
    }

    /**
     * Get the value presently set on this filter. See setValue for details.
     *
     * @return  string|null     the value set on this instance or null
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Removes all git-fusion info (keys/values) from the passed (or by default current)
     * value and returns result.
     *
     * @param   string  $string     text that potentially contains git-fusion info(s)
     * @return  string  text with all git info stripped
     */
    public function filter($string = null)
    {
        if (func_num_args()) {
            $this->setValue($string);
        }

        return $this->getDescription();
    }

    /**
     * Removes all git-fusion info from the value and returns result.
     *
     * @return  string      the change description, info removed if present
     */
    public function getDescription()
    {
        $split = $this->split();
        return trim(isset($split['description']) ? $split['description'] : $this->getValue());
    }

    /**
     * Set the description to the specified value but leave all git info unchanged.
     * You can retrieve the updated description with git info present by calling getValue
     * after calling this method.
     *
     * @param   string  $description    the new description to apply
     * @return  GitInfo to maintain a fluent interface
     */
    public function setDescription($description)
    {
        $split = $this->split();
        return $this->setValue(
            implode(
                "\n\n",
                array(trim($description, "\n\r"), $split['postDescription'])
            )
        );
    }

    /**
     * Tests if the active string is a git info bearing git-fusion change description
     *
     * @return  bool        true if string has git-fusion info, false otherwise
     */
    public function hasInfo()
    {
        return (bool) $this->split();
    }

    /**
     * Extracts git-fusion info key/value(s) from the current string.
     * If the string doesn't contain git info (or lacks the specified key) null/empty array will be returned.
     *
     * @param   mixed   $key        a specific key id to look for or null to retrieve all keys/values
     * @return  mixed   the value of the requested key (or null if not-found) or an array of all found keys/values
     */
    public function get($key = null)
    {
        // detect/split the git info from the rest of the description
        $split = (array) $this->split() + array('info' => '');

        // parse the git info (if we found any)
        $regex = '/^ (?P<keys>[^:]+): (?P<values>.+)$/m';
        preg_match_all($regex, $split['info'], $matches);
        $matches += array('keys' => array(), 'values' => array());

        // join together any keys/values we found
        $info = $matches['keys'] && $matches['values']
            ? array_combine($matches['keys'], $matches['values'])
            : array();

        // return everything if no key specified
        if (!$key) {
            return $info;
        }

        // return requested key or null if it isn't present
        return isset($info[$key]) ? $info[$key] : null;
    }

    /**
     * Helper method to determine if the active string is git-fusion description
     * formatted and split it into description/info if so.
     *
     * @return  mixed   if the current value is git-fusion formatted a matches array, otherwise false
     * @throws  \BadMethodCallException     if no value has been set on the filter
     */
    protected function split()
    {
        if ($this->getValue() === null) {
            throw new \BadMethodCallException('No value has been set on the GitInfo filter, cannot continue.');
        }

        $regex =
            '/
            ^(?P<description>.*)                # starts with a description
            (?P<postDescription>
                Imported[ ]from[ ]Git[\n\r]+    # followed by this constant
                (?P<info>                       # then one or more key: value pairs
                    (?:[ ][^:\r\n]+:[ ][^\r\n]+([\r\n]+|$))+
                )$                              # key: value pairs take us to end of text
            )
            /sx';

        if (!preg_match($regex, $this->getValue(), $matches)) {
            return false;
        }

        return $matches;
    }
}
