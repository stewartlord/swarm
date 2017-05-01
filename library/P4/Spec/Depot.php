<?php
/**
 * Abstracts operations against Perforce depots.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\ConnectionInterface;

class Depot extends PluralAbstract
{
    const SPEC_TYPE         = 'depot';
    const ID_FIELD          = 'Depot';

    protected $fields       = array(
        'Owner'         => array(
            'accessor'  => 'getOwner',
            'mutator'   => 'setOwner'
        ),
        'Date'          => array(
            'accessor'  => 'getDate'
        ),
        'Description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'Type'          => array(
            'accessor'  => 'getType',
            'mutator'   => 'setType'
        ),
        'Address'       => array(
            'accessor'  => 'getAddress',
            'mutator'   => 'setAddress'
        ),
        'Suffix'        => array(
            'accessor'  => 'getSuffix',
            'mutator'   => 'setSuffix'
        ),
        'StreamDepth'   => array(
            'accessor'  => 'getStreamDepth',
            'mutator'   => 'setStreamDepth'
        ),
        'Map'           => array(
            'accessor'  => 'getMap',
            'mutator'   => 'setMap'
        )
    );

    /**
     * Determine if the given depot id exists.
     *
     * @param   string                      $id             the id to check for.
     * @param   ConnectionInterface         $connection     optional - a specific connection
     *                                                      to use.
     * @return  bool                        true if the given id matches an existing depot.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $depots = static::fetchAll(array(), $connection);
        $depots->filter(static::ID_FIELD, $id);

        return (bool) count($depots);
    }

    /**
     * Get the owner of this depot.
     *
     * @return  string|null     user who owns this record.
     */
    public function getOwner()
    {
        return $this->getRawValue('Owner');
    }

    /**
     * Set the owner of this depot to passed value.
     *
     * @param   string|null                 $owner  a string containing username.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   owner is incorrect type.
     */
    public function setOwner($owner)
    {
        if (!is_string($owner) && !is_null($owner)) {
            throw new \InvalidArgumentException('Owner must be a string or null.');
        }

        return $this->setRawValue('Owner', $owner);
    }

    /**
     * Get the date that this specification was last modified.
     *
     * @return  string|null  Date/Time of last update, formatted "2009/11/23 12:57:06" or null
     */
    public function getDate()
    {
        return $this->getRawValue('Date');
    }

    /**
     * Get the unixtime this specification was last modified.
     *
     * @return  int|null    the unixtime this spec was last modified on the server,
     *                      or null if the depot does not exist on the server.
     */
    public function getTime()
    {
        return static::dateToTime($this->getDate(), $this->getConnection()) ?: null;
    }

    /**
     * Get the description for this depot.
     *
     * @return  string|null     description for this depot.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set a description for this depot.
     *
     * @param   string|null                 $description    description for this depot.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   description is incorrect type.
     */
    public function setDescription($description)
    {
        if (!is_string($description) && !is_null($description)) {
            throw new \InvalidArgumentException('Description must be a string or null.');
        }

        return $this->setRawValue('Description', $description);
    }

    /**
     * Get type of this depot.
     * Will be one of: local/stream/remote/spec/archive.
     *
     * @return  string|null     description for this depot.
     */
    public function getType()
    {
        return $this->getRawValue('Type');
    }

    /**
     * Set type for this depot.
     * See getType for available options.
     *
     * @param   string|null                 $type   type of this depot.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   description is incorrect type.
     */
    public function setType($type)
    {
        if (!is_string($type) && !is_null($type)) {
            throw new \InvalidArgumentException('Type must be a string or null.');
        }

        return $this->setRawValue('Type', $type);
    }

    /**
     * Get the address for this depot (for remote depots).
     *
     * @return  string|null     address for this depot.
     */
    public function getAddress()
    {
        return $this->getRawValue('Address');
    }

    /**
     * Set address for this depot - for remote depots.
     *
     * @param   string|null                 $address    remote depot connection address.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   address is incorrect type.
     */
    public function setAddress($address)
    {
        if (!is_string($address) && !is_null($address)) {
            throw new \InvalidArgumentException('Address must be a string or null.');
        }

        return $this->setRawValue('Address', $address);
    }

    /**
     * Get suffix for the depot.
     *
     * @return  string|null     depot suffix (for spec depots).
     */
    public function getSuffix()
    {
        return $this->getRawValue('Suffix');
    }

    /**
     * Set suffix for this depot - for spec depots.
     *
     * @param   string|null                 $suffix     suffix to be used for generated paths.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   suffix is incorrect type.
     */
    public function setSuffix($suffix)
    {
        if (!is_string($suffix) && !is_null($suffix)) {
            throw new \InvalidArgumentException('Suffix must be a string or null.');
        }

        return $this->setRawValue('Suffix', $suffix);
    }

    /**
     * Get stream depth of this depot (for stream depots only).
     *
     * @return  int|null    stream depth for this depot.
     */
    public function getStreamDepth()
    {
        return $this->normalizeStreamDepth($this->getRawValue('StreamDepth'));
    }

    /**
     * Set stream depth for this depot. This field is required only for stream depots.
     *
     * @param   int|string|null             stream depth for this depot
     * @return  Depot                       provides a fluent interface.
     * @throws  \BadMethodCallException     if stream depth field is not supported
     * @throws  \InvalidArgumentException   depth is incorrect type.
     */
    public function setStreamDepth($depth)
    {
        if (!$this->hasField('StreamDepth')) {
            throw new \BadMethodCallException('StreamDepth field is not supported.');
        }

        $depth = $this->normalizeStreamDepth($depth);
        if (!is_null($depth) && ($depth > 10 || $depth < 1)) {
            throw new \InvalidArgumentException('StreamDepth must be between 1-10 or null.');
        }

        return $this->setRawValue('StreamDepth', $depth);
    }

    /**
     * Get map for the depot.
     *
     * @return  string|null     depot map.
     */
    public function getMap()
    {
        return $this->getRawValue('Map');
    }

    /**
     * Set map for this depot.
     *
     * @param   string|null                 $map    depot map.
     * @return  Depot                       provides a fluent interface.
     * @throws  \InvalidArgumentException   map is incorrect type.
     */
    public function setMap($map)
    {
        if (!is_string($map) && !is_null($map)) {
            throw new \InvalidArgumentException('Map must be a string or null.');
        }

        return $this->setRawValue('Map', $map);
    }

    /**
     * Normalize given stream depth to return integer value or null.
     *
     * @param   int|string|null     $depth  stream depth to normalize
     * @return  int|null            normalized depth
     */
    protected function normalizeStreamDepth($depth)
    {
        // if $depth is null or integer return the same value
        // if $depth is string integer, cast it to integer
        // if $depth is other string, turn it into integer equal to number of components
        // separated by slashes (not counting the initial //depot)
        $depth = (string)(int)$depth === (string)$depth
            ? (int) $depth
            : substr_count($depth, '/') - 2;

        // return computed value if its a positive integer, otherwise return null as it
        // means that the input was null or invalid
        return $depth > 0 ? $depth : null;
    }

    /**
     * Check if the given id is in a valid format for this spec type.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\SpecName;
        $validator->allowRelative(true);
        $validator->allowPercent(false);
        $validator->allowCommas(false);
        $validator->allowSlashes(true);
        return $validator->isValid($id);
    }

    /**
     * Return empty set of flags for the spec list command as depots takes no arguments.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        return array();
    }

    /**
     * Given a spec entry from spec list output (p4 clients), produce
     * an instance of this spec with field values set where possible.
     *
     * @param   array                       $listEntry      a single spec entry from spec list output.
     * @param   array                       $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface         $connection     a specific connection to use.
     * @return  Client                      a (partially) populated instance of this spec class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        // some values are mapped differently in listEntry
        $listEntry['Depot']       = $listEntry['name'];
        $listEntry['Description'] = $listEntry['desc'];
        unset($listEntry['name']);
        unset($listEntry['desc']);

        return parent::fromSpecListEntry($listEntry, $flags, $connection);
    }
}
