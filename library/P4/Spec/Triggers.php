<?php
/**
 * Abstracts operations against the Perforce triggers table.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

class Triggers extends SingularAbstract
{
    const SPEC_TYPE     = 'triggers';

    protected $fields   = array(
        'Triggers'  => array(
            'accessor'  => 'getTriggers',
            'mutator'   => 'setTriggers'
        )
    );

    /**
     * Get Triggers in array form.
     *
     * Format of array is as follows:
     *
     * array (
     *     array (
     *         'name'    => 'my-trigger',
     *         'type'    => 'form-in',
     *         'path'    => '//...',
     *         'command' => '/path/to/my/script.sh'
     *     )
     * )
     *
     * @return  array   array of Trigger entries.
     */
    public function getTriggers()
    {
        $triggers = array();
        // Go over each entry; defaults to empty array to avoid warnings on null
        foreach ($this->getRawValue('Triggers') ?: array() as $line) {
            $triggers[] = $this->toTriggerArray($line);
        }

        return $triggers;
    }

    /**
     * Set Triggers in array form.
     *
     * See getTriggers() for format.
     * Individual Trigger entries may also be specified in raw string
     * format for convienence.
     *
     * @param   array  $triggers  array of Trigger entries in array or raw string format.
     * @return  Triggers          provides a fluent interface.
     */
    public function setTriggers($triggers)
    {
        if (!is_array($triggers)) {
            throw new \InvalidArgumentException(
                'Triggers must be passed as an array'
            );
        }

        $strings = array();
        foreach ($triggers as $trigger) {
            // Normalize Trigger entries to array format for validation
            if (is_string($trigger)) {
                $trigger = $this->toTriggerArray($trigger);
            }

            $strings[] = $this->fromTriggerArray($trigger);
        }

        $this->setRawValue('Triggers', $strings);

        return $this;
    }

    /**
     * Convert a raw Trigger string (single entry) into an array,
     * see getTriggers for format.
     *
     * @param   string  $entry  A single Trigger entry in string format
     * @return  array   A single Trigger entry array
     * @throws  \InvalidArgumentException   If passed string is unparsable
     */
    protected function toTriggerArray($entry)
    {
        $keys   = array('name', 'type', 'path', 'command');
        $values = str_getcsv($entry, ' ');

        // multiple spaces in a row will create empty values, remove them
        $values = array_filter($values, 'strlen');

        if (count($values) != count($keys)) {
            throw new \InvalidArgumentException(
                'Trigger entry with missing field(s) encountered'
            );
        }

        return array_combine($keys, $values);
    }

    /**
     * Convert a Trigger array (single entry) into a string, see
     * getTriggers for format. Will validate input array and throw
     * on errors.
     *
     * @param   array   $array  The single Trigger entry to validate and convert to string
     * @return  string  A single Trigger entry in string format
     * @throws  \InvalidArgumentException   If input is poorly formatted
     */
    protected function fromTriggerArray($array)
    {
        // Validate the array, will throw if invalid
        if (!$this->isValidTriggerArray($array)) {
            throw new \InvalidArgumentException(
                'Trigger array entry is invalid.'
            );
        }

        $entry =        $array['name']      ." ".
                        $array['type']      ." ".
                  '"'.  $array['path']      .'" '.
                  '"'.  $array['command']   .'"';

        return $entry;
    }

    /**
     * Validates a single Trigger entry in array format, see getTriggers
     * for format details.
     *
     * @param   array   $array  A single Trigger entry in array format
     * @return  bool    True - Valid, False - Error(s) found
     */
    protected function isValidTriggerArray($array)
    {
        if (!is_array($array)) {
            return false;
        }

        // Validate all 'word' fields are present and don't contain spaces
        $fields = array('name', 'type');
        foreach ($fields as $key) {
            if (!array_key_exists($key, $array) ||
                trim($array[$key]) === '' ||
                preg_match('/\s/', $array[$key])) {
                return false;
            }
        }

        // Validate 'path' and 'command' fields are present, spaces are permitted
        $fields = array('path', 'command');
        foreach ($fields as $key) {
            if (!array_key_exists($key, $array) ||
                trim($array[$key]) === '') {
                return false;
            }
        }

        return true;
    }
}
