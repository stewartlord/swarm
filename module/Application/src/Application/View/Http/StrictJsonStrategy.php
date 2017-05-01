<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Http;

use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManagerInterface;
use Zend\Http\Response;
use Zend\Mvc\MvcEvent;
use Zend\View\Model\JsonModel;

class StrictJsonStrategy extends AbstractListenerAggregate
{
    /**
     * Attach the aggregate to the specified event manager
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(
            array(MvcEvent::EVENT_RENDER, MvcEvent::EVENT_RENDER_ERROR),
            array($this, 'injectStrictJsonResponse'),
            -200
        );
    }

    /**
     * Ensures JSON output is returned if JSON is requested. Errors are automatically converted to JSON.
     *
     * All other responses produce a 406 (Not Acceptable) error.
     *
     * @param  MvcEvent $event
     * @return void
     */
    public function injectStrictJsonResponse(MvcEvent $event)
    {
        $result   = $event->getResult();
        $request  = $event->getRequest();
        $response = $event->getResponse();

        // do nothing if the response is already JSON, or if JSON was not requested.
        if ($result instanceof JsonModel || strtolower($request->getQuery('format')) !== 'json') {
            return;
        }

        // if response code is not 4xx or 5xx, set the response code to HTTP 406. This indicates to the client that
        // invalid content was returned (in a way that preserves useful error messages).
        if (!$response->isClientError() && !$response->isServerError()) {
            $response->setStatusCode(Response::STATUS_CODE_406);
        }

        $model = new JsonModel(array('error' => $response->getReasonPhrase()));
        $event->setResult($model)
              ->setViewModel($model);
    }
}
