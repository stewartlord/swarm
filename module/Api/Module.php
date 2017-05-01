<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Api;

use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to route event to set format=json query parameter for API requests
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $events      = $application->getEventManager();

        // default to json output for api requests
        // we do this early so that (early) exceptions are rendered as JSON
        $events->attach(
            MvcEvent::EVENT_ROUTE,
            function ($event) {
                $route = $event->getRouteMatch() ? $event->getRouteMatch()->getMatchedRouteName() : '';
                if (strpos($route, 'api/') === 0) {
                    $event->getRequest()->getQuery()->set('format', 'json');
                }
            }
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
