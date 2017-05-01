<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files\Format;

use Files\MimeType;
use P4\File\File;
use Zend\Http\Request;

class Handler
{
    protected $canPreviewCallback    = null;
    protected $renderPreviewCallback = null;

    /**
     * Allow callbacks to be set at construction time.
     *
     * @param   callable|null   $canPreviewCallback     callback to check if a file is pre-viewable by this handler
     * @param   callable|null   $renderPreviewCallback  callback to render HTML preview
     * @throws  \InvalidArgumentException               beware, throws if callbacks are not callable
     */
    public function __construct($canPreviewCallback = null, $renderPreviewCallback = null)
    {
        $this->setCanPreviewCallback($canPreviewCallback);
        $this->setRenderPreviewCallback($renderPreviewCallback);
    }

    /**
     * Check if the given file is pre-viewable by this handler.
     *
     * @param   File    $file       the file to check
     * @param   Request $request    the request object for this request
     * @return  bool    true if the file is pre-viewable, false otherwise.
     */
    public function canPreview(File $file, Request $request)
    {
        if (!is_callable($this->canPreviewCallback)) {
            return false;
        }

        return call_user_func(
            $this->canPreviewCallback,
            $file,
            strtolower($file->getExtension()),
            MimeType::getTypeFromName($file->getBasename()),
            $request
        );
    }

    /**
     * Render HTML markup to preview the given file.
     *
     * @param   File    $file       the file to render a preview of
     * @param   Request $request    the request object for this request
     * @return  string  the rendered HTML preview
     * @throws  \RuntimeException   if the file is not pre-viewable or there is no render callback
     */
    public function renderPreview(File $file, Request $request)
    {
        if (!is_callable($this->renderPreviewCallback)) {
            throw new \RuntimeException("Cannot render file preview. The callback is not set.");
        }
        if (!$this->canPreview($file, $request)) {
            throw new \RuntimeException("Cannot render file preview. File is not pre-viewable.");
        }

        return call_user_func(
            $this->renderPreviewCallback,
            $file,
            strtolower($file->getExtension()),
            MimeType::getTypeFromName($file->getBasename()),
            $request
        );
    }

    /**
     * Set the 'canPreview' callback to check if a file is pre-viewable by this handler.
     * The expected function signature is:
     *
     *  function(File $file, $extension, $mimeType, Request $request) { return bool; }
     *
     * @param   callable|null   $callback   the callback function to check if a file is pre-viewable
     * @return  Handler         provides fluent interface
     * @throws  \InvalidArgumentException   if given callback is not callable
     */
    public function setCanPreviewCallback($callback)
    {
        if (!is_callable($callback) && !is_null($callback)) {
            throw new \InvalidArgumentException("Cannot set callback. Argument is not callable.");
        }

        $this->canPreviewCallback = $callback;

        return $this;
    }

    /**
     * Set the 'renderPreview' callback to prepare HTML markup for previewing a file.
     * The expected function signature is:
     *
     *  function(File $file, $extension, $mimeType, Request $request) { return 'HTML'; }
     *
     * @param   callable|null   $callback   the callback function to render HTML preview
     * @return  Handler         provides fluent interface
     * @throws  \InvalidArgumentException   if given callback is not callable
     */
    public function setRenderPreviewCallback($callback)
    {
        if (!is_callable($callback) && !is_null($callback)) {
            throw new \InvalidArgumentException("Cannot set callback. Argument is not callable.");
        }

        $this->renderPreviewCallback = $callback;

        return $this;
    }
}
