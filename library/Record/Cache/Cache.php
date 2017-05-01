<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */
namespace Record\Cache;

use P4\Model\Connected\ConnectedAbstract as Connected;
use P4\Model\Fielded\FieldedAbstract as FieldedModel;
use P4\Model\Fielded\Iterator as FieldedIterator;

/**
 * A basic filesystem cache with multi-server invalidation.
 * The interface is inspired by Zend\Cache, but way simpler.
 *
 * One unique aspect of this cache is that it uses Perforce counters to provide
 * cache validation. Every cache entry can have a corresponding counter. Anytime
 * we read or write a cache entry to a file, we include the counter value for
 * the entry in the filename. Anytime we need to invalidate the cache for a
 * given entry, we increment the corresponding counter value. This ensures that
 * the next read will produce a cache-miss.
 *
 * The cache counters are preferable to local clearing as they will invalidate
 * caches across web-servers in a multi-server environment. To minimize their
 * expense, we only fetch the cache counters once per-instance. The counters can
 * be forcibly refreshed by calling reset().
 *
 * When an item is read from the cache, we hang onto it in memory. This costs
 * memory, but saves time when the same item is read repeatedly. Repeated reads
 * are a common case at the time of this writing. The in-memory cache can be
 * cleared by calling reset().
 */
class Cache extends Connected
{
    const   COUNTER_PREFIX  = 'swarm-cache-';

    protected $items        = array();
    protected $readers      = array();
    protected $counters     = null;
    protected $cacheDir     = null;

    /**
     * Get an item from the cache.
     * Note: when getting cached objects, clone them before you modify them.
     * We hang onto an in-memory copy of cached items for performance.
     *
     * @param   string      $key        the item identifier
     * @param   bool        $success    true if successful, false otherwise
     * @return  mixed       data on success, null on failure
     * @throws  Exception   if cache directory is not set
     */
    public function getItem($key, &$success = null)
    {
        $file    = $this->getFile($key);
        $index   = $this->encodeKey($key);
        $success = null;

        // check for in-memory (already fetched) item
        if (array_key_exists($index, $this->items)) {
            $success = true;
            return $this->items[$index];
        }

        if (!is_readable($file)) {
            $success = false;
            return null;
        }

        // unserialize and hang onto item for next time
        $item = unserialize(file_get_contents($file));

        // if the item is a fielded iterator verify the field definitions
        // are still current (could be stale if upgrading/downgrading)
        if ($item instanceof FieldedIterator && $item->first() instanceof FieldedModel) {
            $cached = $item->first();
            $class  = get_class($cached);
            $fresh  = new $class;
            if ($cached->getFieldDefinitions() != $fresh->getFieldDefinitions()) {
                $success = false;
                return null;
            }
        }

        $this->items[$index] = $item;

        if ($item === false) {
            $success = false;
            return null;
        }

        $success = true;
        return $item;
    }

    /**
     * Store an item in the cache.
     *
     * @param   string      $key    the item identifier
     * @param   mixed       $value  the item value to store
     * @return  Cache       provides fluent interface
     * @throws  Exception   if unable to write to cache
     */
    public function setItem($key, $value)
    {
        $dir   = $this->getCacheDir();
        $file  = $this->getFile($key);
        $index = $this->encodeKey($key);

        // ensure cache dir exists and is writable
        static::ensureWritable($dir);

        if (is_file($file) && !is_writable($file)) {
            @chmod($file, 0700);
            if (!is_writable($file)) {
                throw new Exception(
                    "Cannot write to cache file ('" . $file . "'). Check permissions."
                );
            }
        }

        file_put_contents($file, serialize($value), LOCK_EX);

        // update in-memory copy
        $this->items[$index] = $value;

        return $this;
    }

    /**
     * Invalidate cache of the given key (across web-servers).
     * This is accomplished by incrementing the validation number.
     *
     * @param   string  $key    the cache key to invalidate
     * @return  Cache   provides fluent interface
     */
    public function invalidateItem($key)
    {
        $index   = $this->encodeKey($key);
        $counter = static::COUNTER_PREFIX . $index;
        $result  = $this->getConnection()->run(
            'counter',
            array('-u', '-i', $counter)
        );

        // clear in-memory copy of item
        unset($this->items[$index]);
        unset($this->readers[$index]);

        // if we have cached counters, update the affected one
        if (is_array($this->counters)) {
            $this->counters[$counter] = $result->getData(0, 'value');
        }

        return $this;
    }

    /**
     * Invalidate all items in the cache.
     * This is accomplished by incrementing all of the validation numbers.
     *
     * @return  Cache   provides fluent interface
     */
    public function invalidateAll()
    {
        $counters = $this->getCounters();
        foreach ($counters as $key => $value) {
            $result = $this->getConnection()->run(
                'counter',
                array('-u', '-i', $key)
            );
        }

        // clear in-memory items and counters
        $this->items    = array();
        $this->readers  = array();
        $this->counters = null;

        return $this;
    }

    /**
     * Set the filesystem path to write cache entries to.
     *
     * @param   string  $dir    the file-system path to write to (will be created)
     * @return  Cache   provides fluent interface
     */
    public function setCacheDir($dir)
    {
        $this->cacheDir = rtrim($dir, '/');

        return $this;
    }

    /**
     * Get the filesystem path to write cache entries to.
     *
     * @return  string      the file-system path to write to
     * @throws  Exception   if cache directory is not set
     */
    public function getCacheDir()
    {
        if (!$this->cacheDir) {
            throw new Exception("Cannot get cache directory. Directory is unset.");
        }

        return $this->cacheDir;
    }

    /**
     * Clear the in-memory items and validation numbers.
     * Useful for long-running processes and testing purposes.
     *
     * @return  Cache   provides fluent interface
     */
    public function reset()
    {
        $this->items    = array();
        $this->readers  = array();
        $this->counters = null;

        return $this;
    }

    /**
     * Clear the in-memory copy of a specific item.
     *
     * @param   string  $key    the cache key to reset
     * @return  Cache   provides fluent interface
     */
    public function resetItem($key)
    {
        $index = $this->encodeKey($key);
        unset($this->items[$index]);
        unset($this->readers[$index]);

        return $this;
    }

    /**
     * Deletes invalidated cache files from the cache directory.
     *
     * @return  Cache       provides fluent interface
     */
    public function removeInvalidatedFiles()
    {
        $prefix = strtoupper(md5($this->getConnection()->getPort()));
        $regex  = '/^' . $prefix . '\-(?P<key>.+)\-(?P<counter>[0-9]+)(\..*)?$/';
        $dir    = $this->getCacheDir();

        static::ensureWritable($dir);
        $handle = opendir($dir);
        while (($file = readdir($handle)) !== false) {
            if (preg_match($regex, $file, $matches)) {
                $count = $this->getCounter($this->decodeKey($matches['key']));
                if (!$count || (int)$matches['counter'] < $count) {
                    // either no counter found, or it is lower than the current one, so remove the file
                    unlink($dir . '/' . $file);
                }
            }
        }
        closedir($handle);

        return $this;
    }

    /**
     * Get the name of the file to read/write a given cache entry.
     * The file is under the cache dir and uses the encoded key and
     * the corresponding cache counter (for invalidation).
     *
     * @param   string  $key    the cache key to get the filename for
     * @return  string  the file to read/write
     */
    public function getFile($key)
    {
        return $this->getCacheDir()
            . '/' . strtoupper(md5($this->getConnection()->getPort()))
            . '-' . $this->encodeKey($key)
            . '-' . $this->getCounter($key);
    }

    /**
     * Get an ArrayReader of the file to read a given cache entry.
     *
     * @param   string  $key    the cache key to get the ArrayReader for
     * @return  ArrayReader
     */
    public function getReader($key)
    {
        $index = $this->encodeKey($key);
        if (!array_key_exists($index, $this->readers)) {
            $reader = new ArrayReader($this->getFile($key));
            $this->readers[$index] = $reader->openFile();
        }

        return $this->readers[$index];
    }

    /**
     * Throws an exception if $dir is not a directory or is not writable.
     *
     * @param $dir          string  the name of the directory to check
     * @throws Exception    thrown if $dir is not a directory or is not writable
     */
    public static function ensureWritable($dir)
    {
        // ensure cache dir exists and is writable
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }
    }

    /**
     * Get the validation counter for the given cache key.
     * If no counter exists, 0 will be returned.
     *
     * @param   string  $key    the cache key to get the counter for
     * @return  int     the cache validation number for the given key
     */
    protected function getCounter($key)
    {
        $counters = $this->getCounters();
        $counter  = static::COUNTER_PREFIX . $this->encodeKey($key);

        return isset($counters[$counter]) ? (int) $counters[$counter] : 0;
    }

    /**
     * Get the cache validation counters. Used to compose cache filenames.
     * The counters are stored in Perforce and read once per-instance or more
     * often if their in-memory copy is cleared.
     *
     * @return  array   the cache validation counters
     */
    protected function getCounters()
    {
        if ($this->counters === null) {
            $result = $this->getConnection()->run(
                'counters',
                array('-u', '-e', static::COUNTER_PREFIX . '*')
            );

            $counters = array();
            foreach ($result->getData() as $counter) {
                $counters[$counter['counter']] = $counter['value'];
            }

            $this->counters = $counters;
        }

        return $this->counters;
    }

    /**
     * Decodes and returns the key from the encoded version.
     *
     * @param   string  $key    encoded cache key to decode
     * @return  string  the decoded cache key
     */
    protected function decodeKey($key)
    {
        return pack('H*', $key);
    }

    /**
     * Make the given key safe for use in filenames and counters
     *
     * @param   string  $key    the cache key to encode
     * @return  string  the encoded cache key
     */
    protected function encodeKey($key)
    {
        return strtoupper(bin2hex($key));
    }
}
