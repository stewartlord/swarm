<?php
/**
 * Abstracts operations against the Perforce protections table.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

class Protections extends SingularAbstract
{
    const SPEC_TYPE         = 'protect';

    protected $fields       = array(
        'Protections'   => array(
            'accessor'  => 'getProtections',
            'mutator'   => 'setProtections'
        )
    );

    /**
     * Get protections in array form. Format of array is as follows:
     *
     *    array (
     *      array (
     *        'mode' => 'super',
     *        'type' => 'user',
     *        'name' => '*',
     *        'host' => '*',
     *        'path' => '//...'
     *      )
     *    )
     *
     * @return  array   array of protect entries.
     */
    public function getProtections()
    {
        $protections = array();
        foreach ((array) $this->getRawValue('Protections') as $line) {
            $protections[] = $this->toProtectArray($line);
        }

        return $protections;
    }

    /**
     * Set protections table entries.
     *
     * Individual protection entries may be specified in array or
     * raw string format for convienence. See getProtections() for format.
     *
     * @param   array   $protections    array of protect entries in array or raw string format.
     * @return  Protections     provides a fluent interface.
     */
    public function setProtections($protections)
    {
        if (!is_array($protections)) {
            throw new \InvalidArgumentException(
                'Protections must be passed as an array'
            );
        }

        $strings = array();
        foreach ($protections as $protect) {
            // Normalize protection entries to array format for validation
            if (is_string($protect)) {
                $protect = $this->toProtectArray($protect);
            }

            $strings[] = $this->fromProtectArray($protect);
        }

        $this->setRawValue('Protections', $strings);

        return $this;
    }

    /**
     * Add a protection table entry.
     *
     * @param   string  $mode   the access level (e.g. read, write, super, etc.)
     * @param   string  $type   the type of protection (user or group)
     * @param   string  $name   the user or group name
     * @param   string  $host   the host restriction
     * @param   string  $path   the path to apply the protection to.
     * @return  Protections     provides a fluent interface.
     * @throws  \InvalidArgumentException   if any inputs are invalid.
     */
    public function addProtection($mode, $type, $name, $host, $path)
    {
        if (!is_string($mode)
            || !is_string($type)
            || !is_string($name)
            || !is_string($host)
            || !is_string($path)
        ) {
            throw new \InvalidArgumentException(
                "Cannot add protection. All parameters must be in string form."
            );
        }

        // add to protections array.
        $protections   = $this->getRawValue('Protections');
        $protections[] = $this->fromProtectArray(
            array(
                'mode' => $mode,
                'type' => $type,
                'name' => $name,
                'host' => $host,
                'path' => $path,
            )
        );

        $this->setRawValue('Protections', $protections);

        return $this;
    }

    /**
     * Convert a raw protections string (single entry) into an array,
     * see getProtections for format.
     *
     * @param   string  $entry  A single protection entry in string format
     * @return  array   A single protect entry array
     * @throws  \InvalidArgumentException   If passed string is unparsable
     */
    protected function toProtectArray($entry)
    {
        $keys       = array('mode', 'type', 'name', 'host', 'path');
        $protection = str_getcsv($entry, ' ');

        if (count($protection) != count($keys)) {
            throw new \InvalidArgumentException(
                'Protection entry with missing field(s) encountered'
            );
        }

        return array_combine($keys, $protection);
    }

    /**
     * Convert a protection array (single entry) into a string, see
     * getProtections for format. Will validate input array and throw
     * on errors.
     *
     * @param   array   $array  The single protection entry to validate and convert to string
     * @return  string  A single protections entry in string format
     * @throws  \InvalidArgumentException   If input is poorly formatted
     */
    protected function fromProtectArray($array)
    {
        // Validate the array, will throw if invalid
        if (!$this->isValidProtectArray($array)) {
            throw new \InvalidArgumentException(
                'Protection array entry is invalid.'
            );
        }

        $protect =          $array['mode'] ." ".
                            $array['type'] ." ".
                            $array['name'] ." ".
                            $array['host'] ." ".
                    '"'.    $array['path'] .'"';

        return $protect;
    }

    /**
     * Validates a single protection entry in array format, see getProtections
     * for format details.
     *
     * @param   array   $array  A single protect entry in array format
     * @return  bool    True - Valid, False - Error(s) found
     */
    protected function isValidProtectArray($array)
    {
        if (!is_array($array)) {
            return false;
        }

        // Validate all 'word' fields are present and don't contain spaces
        $fields  = array('mode', 'type', 'name', 'host');
        foreach ($fields as $key) {
            if (!array_key_exists($key, $array) ||
                trim($array[$key]) === '' ||
                preg_match('/\s/', $array[$key])) {
                return false;
            }
        }

        // Validate 'path' field is present, spaces are permitted
        if (!array_key_exists('path', $array) || trim($array['path']) === '') {
            return false;
        }

        return true;
    }
}
