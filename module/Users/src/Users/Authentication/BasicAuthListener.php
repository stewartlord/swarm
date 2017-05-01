<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\Authentication;

use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\Exception\BasicAuthFailedException;
use Users\Authentication\Storage\BasicAuth;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;

/**
 * Intended to listen to MVC_DISPATCH and inject a basic auth listener
 * before the controller dispatch to enforce valid basic authentication (if detected)
 */
class BasicAuthListener extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_DISPATCH,
            array($this, 'registerControllerListener'),
            100
        );
    }

    /**
     * This is our phase 1 handler. Its job is to locate the target controller and
     * register the basic auth check on its dispatch event prior to the action running.
     *
     * @param $event
     */
    public function registerControllerListener(MvcEvent $event)
    {
        // register basic auth credential check on route listener
        $routeMatch       = $event->getRouteMatch();
        $controllerName   = $routeMatch->getParam('controller');
        $controllerLoader = $event->getApplication()->getServiceManager()->get('ControllerLoader');
        if ($controllerLoader->has($controllerName)) {
            try {
                $controller = $controllerLoader->get($controllerName);
            } catch (\Exception $e) {
                // let the dispatch listener handle bad controllers
                return;
            }

            $controller->getEventManager()->attach(
                MvcEvent::EVENT_DISPATCH,
                array($this, 'enforceBasicAuth'),
                100
            );
        }
    }

    /**
     * When using basic auth, this phase 2 handler checks credentials on every request:
     *
     * - with session-based auth the credentials are checked on login and we are happy
     *   to trust them thereafter unless we explicitly need the p4_user connection
     * - but, with basic-auth there is no login event, so we explicitly grab the p4_user
     *   connection here to (indirectly) verify the credentials and throw if invalid
     *
     * This occurs just prior to the action running. By throwing so late we get handled
     * just like exceptions in the action would have, resulting in a nice json or html error.
     *
     * @param  MvcEvent  $event         our precious event
     * @throws \Exception               if something went wrong, e.g., Service not created due to UnauthorizedException
     */
    public function enforceBasicAuth(MvcEvent $event)
    {
        $services = $event->getApplication()->getServiceManager();
        $storage  = $services->get('auth')->getStorage();

        if ($storage instanceof BasicAuth) {
            try {
                $services->get('p4_user');
            } catch (ServiceNotCreatedException $e) {
                if ($e->getPrevious() instanceof UnauthorizedException) {
                    $credentials = (array) $storage->read() + array('id' => '');
                    throw new BasicAuthFailedException('Basic Authentication Failure for user ' . $credentials['id']);
                }
                throw $e;
            }
        }
    }
}
