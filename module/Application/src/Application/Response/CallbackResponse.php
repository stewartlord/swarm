<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Response;

use Zend\Http\Response;

/**
 * CallbackResponse, for when you need more control than a StreamResponse
 *
 * Usage:
 *
 *      $response = new CallbackResponse();
 *      $response->setCallback(function () use ($depot, $attachment) {
 *          return $depot->stream($attachment->get('depotFile'));
 *      });
 */
class CallbackResponse extends Response
{
    /**
     * @var Callback
     */
    public $callback;

    /**
     * Provide the output function that will drive the response.
     * @param $callback a closure or anonymous function or other valid call_user_func() value
     */
    public function setCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('CallbackResponse->setCallback() parameter must be a valid Callable.');
        }

        $this->callback = $callback;
    }

    /**
     * Blindly grab the callback.
     * @return mixed will return the callback function (or null if it has not been set)
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * Output buffers the stream into a string. Defeats the purpose of streaming, but useful for testing, and also
     * makes the class respond more sanely in a few scenarios.
     *
     * @return string   the streamed content
     */
    public function getContent()
    {
        ob_start();
        call_user_func($this->callback);
        $output = ob_get_clean();

        return $output;
    }
}
