<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 *              Portions of this file are copyright 2005-2013 Zend Technologies USA Inc. licensed under New BSD License
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Response;

use Zend\Mvc\ResponseSender\AbstractResponseSender;
use Zend\Mvc\ResponseSender\SendResponseEvent;

/**
 * Handles detection and dispatching for CallbackResponses
 */
class CallbackResponseSender extends AbstractResponseSender
{
    /**
     * Process the callback and update the event's ContentSent flag
     *
     * @param SendResponseEvent $event an event containing a CallbackResponse
     */
    public function fireCallback(SendResponseEvent $event)
    {
        if ($event->contentSent()) {
            return $this;
        }
        $response = $event->getResponse();
        $stream   = $response->getCallback();
        call_user_func($stream);
        $event->setContentSent();
    }

    /**
     * Examine incoming events and fireCallback if CallbackResponse detected
     *
     * @param SendResponseEvent $event
     * @return $this
     */
    public function __invoke(SendResponseEvent $event)
    {
        $response = $event->getResponse();
        if (!$response instanceof CallbackResponse) {
            return $this;
        }

        // disable output buffers to facilitate streaming (unless testing)
        if (!$event->getParam('isTest')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->sendHeaders($event);
        $this->fireCallback($event);
        $event->stopPropagation(true);

        return $this;
    }
}
