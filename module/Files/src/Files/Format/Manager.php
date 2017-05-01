<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files\Format;

use P4\File\Exception\Exception;
use P4\File\File;
use Zend\Http\Request;

class Manager
{
    protected $handlers = array();

    /**
     * Check if the given file is pre-viewable by any of the handlers.
     *
     * @param   File    $file       the file to check
     * @param   Request $request    the request object for this request
     * @return  bool    true if the file is pre-viewable, false otherwise
     */
    public function canPreview(File $file, Request $request)
    {
        // to prevent data leaks, we must verify that the file is not a symbolic link
        if ($file->isSymlink()) {
            return false;
        }

        foreach (array_reverse($this->handlers) as $handler) {
            if ($handler->canPreview($file, $request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render HTML preview for the given file using the last handler that can render it.
     *
     * @param   File    $file       the file to render a preview of
     * @param   Request $request    the request object for this request
     * @return  string  the rendered HTML preview
     * @throws  \RuntimeException   if the file is not pre-viewable
     */
    public function renderPreview(File $file, Request $request)
    {
        // to prevent data leaks, we must verify that the file is not a symbolic link
        if ($file->isSymlink()) {
            return false;
        }

        foreach (array_reverse($this->handlers) as $handler) {
            if ($handler->canPreview($file, $request)) {
                return $handler->renderPreview($file, $request);
            }
        }

        throw new \RuntimeException("Cannot render file. No suitable file format handler.");
    }

    /**
     * Register a new file format handler (last handler wins).
     *
     * @param   Handler             $handler    a file format handler to register
     * @param   string|int|null     $key        optional key/name for this handler (recommended)
     *                                          an existing handler with the same key will be clobbered
     * @return  Manager             provides a fluent interface
     */
    public function addHandler(Handler $handler, $key = null)
    {
        if (is_int($key) || is_string($key)) {
            $this->handlers[$key] = $handler;
        } else {
            $this->handlers[] = $handler;
        }

        return $this;
    }

    /**
     * Set multiple handlers. Overwrites any existing handlers.
     *
     * @param   array|null  $handlers   a list of handlers to use or null to clear handlers
     * @return  Manager     provides a fluent interface
     */
    public function setHandlers(array $handlers = null)
    {
        $this->handlers = array();
        foreach ((array) $handlers as $key => $handler) {
            $this->addHandler($handler, $key);
        }

        return $this;
    }

    /**
     * Get the format handlers.
     *
     * @return  array   zero or more format handlers
     */
    public function getHandlers()
    {
        return $this->handlers;
    }
}
