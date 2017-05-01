<?php
/**
 * Abstracts operations against Perforce typemap entries.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

class Typemap extends SingularAbstract
{
    const SPEC_TYPE     = 'typemap';

    protected $fields   = array(
        'TypeMap'   => array(
            'accessor'  => 'getTypemap',
            'mutator'   => 'setTypemap'
        )
    );

    /**
     * Get Typemap in array form. Format of array is as follows:
     *
     *    array (
     *      array (
     *        'type' => 'text',
     *        'path' => '//...'
     *      )
     *    )
     *
     * @return  array   array of Typemap entries.
     */
    public function getTypemap()
    {
        $typeMap = array();
        // Go over each entry; defaults to empty array to avoid warnings on null
        foreach ($this->getRawValue('TypeMap') ?: array() as $line) {
            $typeMap[] = $this->toTypemapArray($line);
        }

        return $typeMap;
    }

    /**
     * Set Typemap in array form. See getTypemap() for format.
     * Individual Typemap entries may also be specified in raw string format for convienence.
     *
     * @param   array   $typeMap    array of Typemap entries in array or raw string format.
     * @return  Typemap     provides a fluent interface.
     */
    public function setTypemap($typeMap)
    {
        if (!is_array($typeMap)) {
            throw new \InvalidArgumentException(
                'Typemap must be passed as an array'
            );
        }

        $strings = array();
        foreach ($typeMap as $type) {
            // Normalize Typemap entries to array format for validation
            if (is_string($type)) {
                $type = $this->toTypemapArray($type);
            }

            $strings[] = $this->fromTypemapArray($type);
        }

        $this->setRawValue('TypeMap', $strings);

        return $this;
    }

    /**
     * Convert a raw Typemap string (single entry) into an array,
     * see getTypemap for format.
     *
     * @param   string  $entry  A single Typemap entry in string format
     * @return  array   A single Typemap entry array
     * @throws  \InvalidArgumentException   If passed string is unparsable
     */
    protected function toTypemapArray($entry)
    {
        $keys   = array('type', 'path');
        $type   = str_getcsv($entry, ' ');

        if (count($type) != count($keys)) {
            throw new \InvalidArgumentException(
                'Typemap entry with missing field(s) encountered'
            );
        }

        return array_combine($keys, $type);
    }

    /**
     * Convert a Typemap array (single entry) into a string, see
     * getTypemap for format. Will validate input array and throw
     * on errors.
     *
     * @param   array   $array  The single Typemap entry to validate and convert to string
     * @return  string  A single Typemap entry in string format
     * @throws  \InvalidArgumentException   If input is poorly formatted
     */
    protected function fromTypemapArray($array)
    {
        // Validate the array, will throw if invalid
        if (!$this->isValidTypemapArray($array)) {
            throw new \InvalidArgumentException(
                'Typemap array entry is invalid.'
            );
        }

        $entry =        $array['type'] ." ".
                  '"'.  $array['path'] .'"';

        return $entry;
    }

    /**
     * Validates a single Typemap entry in array format, see getTypemap
     * for format details.
     *
     * @param   array   $array  A single Typemap entry in array format
     * @return  bool    True - Valid, False - Error(s) found
     */
    protected function isValidTypemapArray($array)
    {
        if (!is_array($array)) {
            return false;
        }

        // Validate all 'word' fields are present and don't contain spaces
        $fields  = array('type');
        foreach ($fields as $key) {
            if (!array_key_exists($key, $array) ||
                trim($array[$key]) === '' ||
                preg_match('/\s/', $array[$key])) {
                return false;
            }
        }

        // Validate 'path' field is present, spaces are permitted
        if (!array_key_exists('path', $array) || $array['path'] === '') {
            return false;
        }

        return true;
    }
}
