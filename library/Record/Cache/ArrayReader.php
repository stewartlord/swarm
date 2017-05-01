<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */
namespace Record\Cache;

/**
 * Memory efficient iterator for arrays that have been written AND indexed by ArrayWriter
 */
class ArrayReader implements \ArrayAccess, \Iterator, \Countable
{
    protected $file      = null;
    protected $indexFile = null;
    protected $handle    = null;
    protected $index     = null;
    protected $noCase    = array();

    /**
     * Setup a new array reader
     *
     * @param string    $file       the file to read array from
     * @param string    $indexFile  optional - the index file to use (e.g. an index with a different order)
     */
    public function __construct($file, $indexFile = null)
    {
        $this->file      = $file;
        $this->indexFile = $indexFile;
    }

    public function openFile()
    {
        $file      = $this->file;
        $indexFile = $this->indexFile ?: $file . ArrayWriter::INDEX_SUFFIX;
        if (!is_string($file) || !file_exists($file)) {
            throw new \RuntimeException("Cannot open file '" . $file . "'. File does not exist.");
        }

        $this->handle = @fopen($file, 'r');
        if ($this->handle === false) {
            throw new \RuntimeException("Unable to open file ('" . $file . "') for reading.");
        }

        // if anything goes wrong past this point, make sure we close/unlock our file
        try {
            // wait for a read lock to ensure file is not being actively written to
            $locked = flock($this->handle, LOCK_SH);
            if ($locked === false) {
                throw new \RuntimeException("Unable to lock file ('" . $file . "') for reading.");
            }

            if (!file_exists($indexFile)) {
                throw new \RuntimeException("Cannot open index file '" . $indexFile . "'. File does not exist.");
            }

            // read the entire index into memory (impractical to stream)
            $this->index = unserialize(file_get_contents($indexFile));
            if ($this->index === false) {
                throw new \RuntimeException("Cannot unserialize index file ('" . $indexFile . "').");
            }
        } catch (\Exception $e) {
            $this->closeFile();
            throw $e;
        }

        return $this;
    }

    public function closeFile()
    {
        if (!is_resource($this->handle)) {
            throw new \RuntimeException("Cannot close file. File is not open.");
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        return $this;
    }

    /**
     * Build and store a case-insensitive lookup table, then check if the given key exists
     *
     * @param   mixed   $key    the array key to look for
     * @return  mixed   the matching key (in actual case) or false if no match
     */
    public function noCaseLookup($key)
    {
        $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';

        // populate the $this->noCase lookup table (lowerKey => actualKey)
        // if duplicate lowerKey values are encountered, only keep the first value
        // note we make a local-scope (lazy) copy of $this->index to avoid mucking the cursor
        if (!$this->noCase) {
            $index = $this->index;
            foreach ($index as $candidate => $value) {
                $lowerKey                = $lower($candidate);
                $this->noCase[$lowerKey] = isset($this->noCase[$lowerKey])
                    ? $this->noCase[$lowerKey]
                    : $candidate;
            }
        }

        $key = $lower($key);
        return isset($this->noCase[$key]) ? $this->noCase[$key] : false;
    }

    public function offsetExists($key)
    {
        // attempt to match PHP's key casting behavior
        // http://php.net/manual/en/language.types.array.php
        if (is_object($key) || is_array($key)) {
            return false;
        }
        if (is_null($key)) {
            $key = "";
        }
        if (!is_string($key) && !is_int($key)) {
            $key = (int) $key;
        }
        return array_key_exists($key, $this->index);
    }

    public function offsetGet($key)
    {
        if (!$this->offsetExists($key)) {
            return null;
        }

        $offset = $this->index[$key][0];
        $length = $this->index[$key][1];
        fseek($this->handle, $offset);

        // need to wrap serialized key/value in array format 'a:1{...}'
        // so that it will unserialize correctly into key/value
        $entry = unserialize('a:1:{' . fread($this->handle, $length) . '}');
        return $entry ? current($entry) : false;
    }

    public function offsetSet($key, $value)
    {
        throw new \BadMethodCallException("Cannot set element. Array is read-only.");
    }

    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException("Cannot unset element. Array is read-only.");
    }

    public function current()
    {
        return $this->offsetGet(key($this->index));
    }

    public function key()
    {
        return key($this->index);
    }

    public function next()
    {
        next($this->index);
    }

    public function rewind()
    {
        reset($this->index);
    }

    public function valid()
    {
        return key($this->index) !== null;
    }

    public function count()
    {
        return count($this->index);
    }

    /**
     * Get the index (e.g. to manipulate it or avoid unserialization)
     *
     * @return  array   the underlying index (ids -> byte offsets)
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * Replace the index (e.g. with a sorted one).
     *
     * @param   array   $index      the new index (ids -> byte offsets)
     * @return  ArrayReader         provides fluent interface
     */
    public function setIndex(array $index = null)
    {
        $this->index = $index;
        return $this;
    }

    /**
     * Get the numeric position of the given key
     *
     * @param   string      $key    the key to find
     * @return  bool|int    the numeric position or false if not found
     */
    public function getKeyPosition($key)
    {
        $position = 0;
        foreach ($this->index as $elementKey => $elementValue) {
            if ($elementKey === $key) {
                return $position;
            }
            $position++;
        }
        return false;
    }

    /**
     * Keep a slice of the array, discard the rest. Operates in place.
     *
     * @param   int     $offset     start at this position
     * @param   int     $length     optional - the number of elements to keep
     * @return  ArrayReader         provides fluent interface
     */
    public function slice($offset, $length = null)
    {
        $this->index = array_slice($this->index, $offset, $length, true);
        return $this;
    }

    /**
     * Sort the array using the given comparison function. Sorts in place.
     *
     * @param   callable        $compare    the function to use to compare elements
     * @return  ArrayReader     provides fluent interface
     */
    public function sort($compare)
    {
        $reader = $this;
        uksort(
            $this->index,
            function ($a, $b) use ($compare, $reader) {
                return call_user_func($compare, $reader[$a], $reader[$b]);
            }
        );

        return $this;
    }
}
