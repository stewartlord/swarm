<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Http;

use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use P4\Connection\Exception\ConnectException;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManagerInterface;
use Zend\Http\Response;
use Zend\Mvc\Application;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;

class ExceptionStrategy extends AbstractListenerAggregate
{
    /**
     * Attach the aggregate to the specified event manager
     *
     * We only attach dispatch error and we attach late to let the standard strategy run first.
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_DISPATCH_ERROR,
            array($this, 'prepareExceptionViewModel'),
            100
        );
    }

    /**
     * Create an exception view model, and set the HTTP status code
     *
     * Replaces parent to set the status code more selectively.
     *
     * @param  MvcEvent $event
     * @return void
     */
    public function prepareExceptionViewModel(MvcEvent $event)
    {
        // Do nothing if not an exception or not an HTTP response
        if ($event->getError() != Application::ERROR_EXCEPTION
            || !$event->getResponse() instanceof Response
        ) {
            return;
        }

        $exception = $event->getParam('exception');

        // if a service was not created properly, attempt to extract the previous exception that caused the failure
        if ($exception instanceof ServiceNotCreatedException) {
            $exception = $exception->getPrevious() ?: $exception;
        }

        if ($exception instanceof UnauthorizedException) {
            $event->getResponse()->setStatusCode(401);
        }
        if ($exception instanceof ForbiddenException) {
            $event->getResponse()->setStatusCode(403);
        }
        if ($exception instanceof ConnectException) {
            $event->getResponse()->setStatusCode(503);
        }
    }
}
