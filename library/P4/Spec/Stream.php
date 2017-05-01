<?php
/**
 * Abstracts operations against Perforce streams.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo add accessor/mutator for 'Paths'
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Model\Fielded\Iterator as FieldedIterator;

class Stream extends PluralAbstract
{
    const SPEC_TYPE         = 'stream';
    const ID_FIELD          = 'Stream';

    const FETCH_BY_PATH     = 'path';
    const FETCH_BY_FILTER   = 'filter';
    const SORT_RECURSIVE    = 'sort';

    protected $cache        = array();
    protected $fields       = array(
        'Update'        => array(
            'accessor'  => 'getUpdateDateTime'
        ),
        'Access'        => array(
            'accessor'  => 'getAccessDateTime'
        ),
        'Owner'         => array(
            'accessor'  => 'getOwner',
            'mutator'   => 'setOwner'
        ),
        'Name'          => array(
            'accessor'  => 'getName',
            'mutator'   => 'setName'
        ),
        'Parent'        => array(
            'accessor'  => 'getParent',
            'mutator'   => 'setParent'
        ),
        'Type'          => array(
            'accessor'  => 'getType',
            'mutator'   => 'setType'
        ),
        'Description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'Options'       => array(
            'accessor'  => 'getOptions',
            'mutator'   => 'setOptions'
        ),
        'Paths'         => array(
            'accessor'  => 'getPaths',
            'mutator'   => 'setPaths'
        )
    );

    /**
     * Get all Streams from Perforce. Adds the following filter options:
     *   FETCH_BY_PATH - limits results to streams matching path (can include wildcards).
     * FETCH_BY_FILTER - a 'jobview' style expression to limit results.
     *  SORT_RECURSIVE - optionally sort the results recusively
     *
     * @param   array                   $options        optional - options to augment fetch behavior.
     * @param   ConnectionInterface     $connection     optional - a specific connection to use.
     * @return  FieldedIterator         all records of this type matching options.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // try/catch parent to deal with the exception we get on non-existend depots
        try {
            $streams = parent::fetchAll($options, $connection);

            // set the 'parent' stream on each entry if we have a copy
            foreach ($streams as $stream) {
                // skip any streams without parents
                if (!$stream->getParent()) {
                    continue;
                }

                // attempt to locate the parent object in our result set and set it
                $parent = $streams->filter('Stream', $stream->getParent(), FieldedIterator::FILTER_COPY)
                                  ->first();

                if ($parent) {
                    $stream->cache['parentObject'] = $parent;
                }
            }

            // apply sorting if it has been requested
            if (isset($options[static::SORT_RECURSIVE]) && $options[static::SORT_RECURSIVE]) {
                $streams = static::sortRecursively($streams);
            }

            return $streams;
        } catch (CommandException $e) {
            // if the 'depot' has been interpreted as an invalid client, just return no matches
            if (preg_match("/Command failed: .+ - must refer to client/", $e->getMessage())) {
                return new FieldedIterator;
            }

            // unexpected error; rethrow it
            throw $e;
        }
    }

    /**
     * Determine if the given stream id exists.
     *
     * @param   string                   $id          the id to check for.
     * @param   ConnectionInterface      $connection  optional - a specific connection to use.
     * @return  bool    true if the given id matches an existing stream.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $streams = static::fetchAll(array(static::FETCH_BY_FILTER => "Stream=$id"), $connection);

        return (bool) count($streams);
    }

    /**
     * Get the last update time for this stream.
     * This value is read only, no setUpdateDateTime function is provided.
     *
     * If this is a brand new stream, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last update, formatted "2009/11/23 12:57:06" or null
     */
    public function getUpdateDateTime()
    {
        return $this->getRawValue('Update');
    }

    /**
     * Get the last access time for this stream.
     * This value is read only, no setAccessDateTime function is provided.
     *
     * If this is a brand new spec, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last access, formatted "2009/11/23 12:57:06" or null
     */
    public function getAccessDateTime()
    {
        return $this->getRawValue('Access');
    }

    /**
     * Get the owner of this stream.
     *
     * @return  string|null     User who owns this record.
     */
    public function getOwner()
    {
        return $this->getRawValue('Owner');
    }

    /**
     * Set the owner of this stream to passed value.
     *
     * @param   string|null $owner  A string containing username
     * @return  Stream      provides a fluent interface.
     * @throws  \InvalidArgumentException   Owner is incorrect type.
     */
    public function setOwner($owner)
    {
        if (!is_string($owner) && !is_null($owner)) {
            throw new \InvalidArgumentException('Owner must be a string or null.');
        }

        return $this->setRawValue('Owner', $owner);
    }

    /**
     * Get the name setting for this stream.
     *
     * @return  string|null     Name set for this stream or null.
     */
    public function getName()
    {
        return $this->getRawValue('Name');
    }

    /**
     * Set the name for this stream.
     *
     * @param   string|null $name   Name for this stream or null
     * @return  Stream      provides a fluent interface.
     * @throws  \InvalidArgumentException   Name is incorrect type.
     */
    public function setName($name)
    {
        if (!is_string($name) && !is_null($name)) {
            throw new \InvalidArgumentException('Name must be a string or null.');
        }

        return $this->setRawValue('Name', $name);
    }

    /**
     * Get the parent setting for this stream.
     *
     * @return  string|null Parent set for this stream.
     */
    public function getParent()
    {
        $parent = $this->getRawValue('Parent');

        return $parent == 'none' ? null : $parent;
    }

    /**
     * Set the parent for this stream.
     *
     * @param   string|null $parent     Parent for this stream or null
     * @return  Stream      provides a fluent interface.
     * @throws  \InvalidArgumentException   Parent is incorrect type.
     */
    public function setParent($parent)
    {
        if (!is_string($parent) && !is_null($parent)) {
            throw new \InvalidArgumentException('Parent must be a string or null.');
        }

        // clear cache as parent may have changed
        $this->cache = array();

        return $this->setRawValue('Parent', $parent);
    }

    /**
     * Get the parent asociated with this stream in Stream format.
     *
     * @return  Stream|null     this streams parent object or null if none
     */
    public function getParentObject()
    {
        if (!$this->getParent()) {
            return null;
        }

        if (!isset($this->cache['parentObject'])
            || !$this->cache['parentObject'] instanceof Stream
        ) {
            $this->cache['parentObject'] = Stream::fetch(
                $this->getParent(),
                $this->getConnection()
            );
        }

        return clone $this->cache['parentObject'];
    }

    /**
     * Returns the depth of this stream. Assumes all parent objects
     * are returnable.
     *
     * @return  int     the depth of this stream.
     */
    public function getDepth()
    {
        $stream = $this;
        for ($i = 0; $stream = $stream->getParentObject(); $i++) {
        }

        return $i;
    }

    /**
     * Get the type setting for this stream.
     *
     * @return  string|null Type set for this stream.
     */
    public function getType()
    {
        return $this->getRawValue('Type');
    }

    /**
     * Set the type for this stream.
     *
     * @param   string|null $type   Type for this stream or null
     * @return  Stream      provides a fluent interface.
     * @throws  \InvalidArgumentException Type is incorrect type.
     */
    public function setType($type)
    {
        if (!is_string($type) && !is_null($type)) {
            throw new \InvalidArgumentException('Type must be a string or null.');
        }

        return $this->setRawValue('Type', $type);
    }

    /**
     * Get the description for this stream.
     *
     * @return  string|null description for this stream.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set a description for this stream.
     *
     * @param   string|null $description    description for this stream.
     * @return  Stream      provides a fluent interface.
     * @throws  \InvalidArgumentException   Description is incorrect type.
     */
    public function setDescription($description)
    {
        if (!is_string($description) && !is_null($description)) {
            throw new \InvalidArgumentException('Description must be a string or null.');
        }

        return $this->setRawValue('Description', $description);
    }

    /**
     * Get options for this stream.
     * Returned array will contain one option per element e.g.:
     * array (
     *     0 => 'allsubmit',
     *     1 => 'toparent',
     *     2 => 'unlocked'
     * )
     *
     * @return  array  options which are set on this stream.
     */
    public function getOptions()
    {
        $options = $this->getRawValue('Options');
        $options = explode(' ', $options);

        // Explode will set key 0 to null for empty input; clean it up.
        if (count($options) == 1 && empty($options[0])) {
            $options = array();
        }

        return $options;
    }

    /**
     * Set the options for this stream.
     * Accepts an array, format detailed in getOptions, or a single string containing
     * a space seperated list of options.
     *
     * @param   array|string    $options    options to set on this stream in array or string.
     * @return  Steam       provides a fluent interface.
     * @throws  \InvalidArgumentException   Options are incorrect type.
     */
    public function setOptions($options)
    {
        if (is_array($options)) {
            $options = implode(' ', $options);
        }

        if (!is_string($options)) {
            throw new \InvalidArgumentException('Options must be an array or string');
        }

        return $this->setRawValue('Options', $options);
    }

    /**
     * Get the paths for this stream.
     * Path entries will be returned as an array with 'type', 'view' and 'depot' entries, e.g.:
     * array (
     *      0 => array (
     *          'type'  => 'share',
     *          'view'  => 'src/...',
     *          'depot' => null
     *      )
     *      1 => array (
     *          'type'  => 'import',
     *          'view'  => 'src/...',
     *          'depot' => '//over/there/src/...'
     *      )
     *  )
     *
     * @return  array  list path entries for this stream.
     */
    public function getPaths()
    {
        // The raw path data is formatted as:
        //  array (
        //      0 => 'share ...',
        //      1 => 'import imp/ //depot/other/local/...'
        //  )

        $paths = array();
        foreach ($this->getRawValue('Paths') ?: array() as $entry) {
            $entry = str_getcsv($entry, ' ');
            $paths[] = array_combine(
                array('type', 'view', 'depot'),
                $entry + array(null, null, null)
            );
        }

        return $paths;
    }

    /**
     * Set the paths for this stream.
     * Paths are passed as an array of path entries. Each patj entry can be an array with
     * 'type', 'view' and, optionally, 'depot' entries or a raw string.
     *
     * @param   array|string    $paths  Path entries, formatted as sub-arrays or strings.
     * @return  Stream          provides a fluent interface.
     * @throws  \InvalidArgumentException Paths array, or a path entry, is incorrect type.
     */
    public function setPaths($paths)
    {
        // we let the caller pass in a single path and normalize it below
        if (is_string($paths) || isset($paths['type'], $paths['view'])) {
            $paths = array($paths);
        }

        if (!is_array($paths)) {
            throw new \InvalidArgumentException('Paths must be passed as array or string.');
        }

        // The Paths array contains either:
        // - Child arrays keyed on type/view/[depot] which we glue together
        // - Raw strings which we simply leave as is
        // The below foreach run will normalize the whole thing for storage
        $parsedPaths = array();
        foreach ($paths as $path) {
            if (is_array($path)
                && isset($path['type'], $path['view'])
                && is_string($path['type'])
                && is_string($path['view'])
                && (!isset($path['depot']) || is_string($path['depot']))
            ) {
                // stringify the path quoting paths to be safe
                $string = $path['type'] . ' "' . $path['view'] . '"';
                if (isset($path['depot']) && strlen($path['depot'])) {
                    $string .= ' "' . $path['depot'] . '"';
                }

                $path = $string;
            }

            if (!is_string($path)) {
                throw new \InvalidArgumentException(
                    "Each path entry must be an array with type/view (and optionally depot) or a string."
                );
            }

            $validate = str_getcsv($path, ' ');
            if (count($validate) < 2 || count($validate) > 3
                || trim($validate[0]) === '' || trim($validate[1]) === ''
            ) {
                throw new \InvalidArgumentException(
                    "Each path entry must contain between two and three entries."
                );
            }

            $parsedPaths[] = $path;
        };

        return $this->setRawValue('Paths', $parsedPaths);
    }

    /**
     * Add a path to this stream.
     *
     * @param   string      $type   the path type (share/isolate/import/exclude)
     * @param   string      $view   the view path
     * @param   string|null $depot  the depot path (only used for import type)
     * @return  Stream      provides a fluent interface.
     */
    public function addPath($type, $view, $depot = null)
    {
        $paths   = $this->getPaths();
        $paths[] = array("type" => $type, "view" => $view, "depot" => $depot);

        return $this->setPaths($paths);
    }

    /**
     * Save this spec to Perforce.
     * Extends parent to provide a default value of 'none' for parent.
     *
     * @return  Stream      provides a fluent interface
     */
    public function save()
    {
        if (!$this->get('Parent')) {
            $this->set('Parent', 'none');
        }

        return parent::save();
    }

    /**
     * Remove this stream. Extend parent to remove all clients dedicated to this
     * stream first.
     *
     * @param   boolean     $force      pass true to force delete this stream, additionally
     *                                  attempts to delete any clients using this stream
     *                                  by default stream will only be deleted if there are
     *                                  no clients current using the stream and current user
     *                                  is the stream owner or the stream is unlocked.
     * @return  Stream      provides fluent interface.
     */
    public function delete($force = false)
    {
        // remove clients dedicated to this stream if force is true
        if ($force) {
            Client::fetchAll(
                array(Client::FETCH_BY_STREAM => $this->getId())
            )->invoke('delete', array($force));
        }

        return parent::delete($force ? array('-f') : null);
    }

    /**
     * Check if the given id is in a valid format for this spec type.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\StreamName;
        return $validator->isValid($id);
    }

    /**
     * Produce set of flags for the spec list command, given fetch all options array.
     * Extends parent to add support for filter option.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        $flags = parent::getFetchAllFlags($options);

        if (isset($options[static::FETCH_BY_FILTER])) {
            $filter = $options[static::FETCH_BY_FILTER];

            if (!is_string($filter) || trim($filter) === '') {
                throw new \InvalidArgumentException(
                    'Filter expects a non-empty string as input'
                );
            }

            $flags[] = '-F';
            $flags[] = $filter;
        }

        if (isset($options[static::FETCH_BY_PATH])) {
            $flags[] = $options[static::FETCH_BY_PATH];
        }

        return $flags;
    }

    /**
     * Given a spec entry from spec list output (p4 streams), produce
     * an instance of this spec with field values set where possible.
     *
     * @param   array                       $listEntry      a single spec entry from spec list output.
     * @param   array                       $flags          the flags that were used for this 'fetchAll' run.
     * @param   ConnectionInterface         $connection     a specific connection to use.
     * @return  Stream                      a (partially) populated instance of this spec class.
     */
    protected static function fromSpecListEntry($listEntry, $flags, ConnectionInterface $connection)
    {
        // move the description into place
        $listEntry['Description'] = $listEntry['desc'];
        unset($listEntry['desc']);

        return parent::fromSpecListEntry($listEntry, $flags, $connection);
    }

    /**
     * This method will ensure the list of streams is in the proper order
     * with children listed after their associated parents.
     *
     * @param   FieldedIterator     $streams    the streams to sort
     * @param   string|null         $parent     the parent id (used for recursion)
     * @return  FieldedIterator     The recursively sorted result
     */
    protected static function sortRecursively($streams, $parent = null)
    {
        // get branches with given parent and sort them
        $children = $streams->filter('Parent', array($parent), FieldedIterator::FILTER_COPY)
                            ->sortBy('Name', array(FieldedIterator::SORT_NATURAL));

        // assemble list and append sorted sub-entries below their parent
        $sorted = new FieldedIterator;
        foreach ($children as $stream) {
            $sorted[] = $stream;
            foreach (static::sortRecursively($streams, $stream->getId()) as $sub) {
                $sorted[] = $sub;
            }
        }

        return $sorted;
    }
}
