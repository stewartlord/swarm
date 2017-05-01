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
 * Array serializer that streams elements into a file.
 * This allows large arrays to be serialized with minimal memory usage.
 * Optionally builds an index file for efficient lookups (see ArrayReader).
 */
class ArrayWriter
{
    const INDEX_SUFFIX  = '.index';

    protected $file     = null;
    protected $handle   = null;
    protected $count    = 0;
    protected $index    = false;
    protected $writing  = false;

    /**
     * Setup a new streaming array writer/serializer
     *
     * @param string    $file   the file to write the array to
     * @param bool      $index  optional - build a separate index file of all array entries
     *                          the index provides byte offset information to lookup specific elements
     *                          in the main file without unserializing everything - the index itself is
     *                          a serialized array in the form of: [ key => [byte-offset, length], ... ]
     */
    public function __construct($file, $index = false)
    {
        $this->file = $file;

        if ($index) {
            $this->index = new static($file . static::INDEX_SUFFIX, false);
        }
    }

    /**
     * If the file doesn't exist (or appears to be corrupt), it will be (re)created.
     * If the file already exists and has valid content, the file will not be modified
     * and a runtime exception will be thrown.
     *
     * @return  $this   provides fluent interface
     * @throws  \RuntimeException   if a valid looking file already exists
     */
    public function createFile()
    {
        if (!is_string($this->file) || !strlen($this->file)) {
            throw new \RuntimeException("Cannot create file. Filename must be set to a non-empty string.");
        }

        Cache::ensureWritable(dirname($this->file));

        // open with 'c+' (to create if missing, but not truncate if existing)
        $this->handle = @fopen($this->file, 'c+');
        if ($this->handle === false) {
            throw new \RuntimeException("Unable to create file ('" . $this->file . "').");
        }

        // if anything goes wrong past this point, make sure we close/unlock our file
        try {
            // write lock to ensure no one else reads/writes until we are done
            $locked = flock($this->handle, LOCK_EX);
            if ($locked === false) {
                throw new \RuntimeException("Unable to lock file ('" . $file . "') for writing.");
            }

            // now that we have acquired a write lock, we want to verify that the
            // file is empty or invalid - if we're indexing, we only validate the
            // index file (handled by our index instance), if we're not indexing
            // (or we are ourselves the index instance) we validate $this->file
            if ($this->index) {
                $this->index->createFile();
            } elseif (unserialize(file_get_contents($this->file)) !== false) {
                throw new \RuntimeException("Unable to create file ('" . $this->file . "'). File has valid content.");
            }

            // prime file for array entries - we don't know how many entries there are
            // so we write a zero-padded length for now and update it on close.
            // we ftrunctate in case the file already exists, but has bad content
            ftruncate($this->handle, 0);
            fwrite($this->handle, 'a:0000000000:{');
        } catch (\Exception $e) {
            $this->closeFile();
            throw $e;
        }

        // if we get this far we are committed to writing the file
        // we need a flag for this so we can safely touch-up in close
        $this->writing = true;

        return $this;
    }

    public function writeElement($key, $value)
    {
        if (!is_resource($this->handle)) {
            throw new \RuntimeException("Cannot write element. File is not open.");
        }

        // offset is needed if we're indexing
        $offset = $this->index ? ftell($this->handle) : null;

        fwrite($this->handle, serialize($key));
        fwrite($this->handle, serialize($value));
        $this->count++;

        // if we're indexing, record the position and length of this element
        if ($this->index) {
            $this->index->writeElement($key, array($offset, ftell($this->handle) - $offset));
        }

        return $this;
    }

    public function closeFile()
    {
        if (!$this->isOpen()) {
            throw new \RuntimeException("Cannot close file. File is not open.");
        }

        // close the array, rewind and touch up length (but only if we are writing)
        if ($this->writing) {
            fwrite($this->handle, '}');
            fseek($this->handle, 2);
            fwrite($this->handle, str_pad($this->count, 10, '0', STR_PAD_LEFT));
            $this->writing = false;
        }

        // if we're indexing, need to close index file now
        if ($this->index && $this->index->isOpen()) {
            $this->index->closeFile();
        }

        // readers don't bother locking the index file (just the main file), so we wait until
        // the index is fully closed to unlock the main file - this ensures a complete index
        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        return $this;
    }

    public function isOpen()
    {
        return is_resource($this->handle);
    }
}
