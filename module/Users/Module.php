<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users;

use Users\Authentication\BasicAuthListener;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to clear cache on user updates
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();

        // invalidate user cache on user form-commits and deletes
        $events->attach(
            array('task.user', 'task.userdel'),
            function ($event) use ($services) {
                $p4Admin = $services->get('p4_admin');

                // ignore git-fusion-reviews-* users - these are regularly updated
                // and used internally by git-fusion in ways that don't concern us
                if (strpos($event->getParam('id'), 'git-fusion-reviews-') === 0) {
                    return;
                }

                try {
                    $cache = $p4Admin->getService('cache');
                    $cache->invalidateItem('users');
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            100
        );

        // validate credentials when using basic-auth
        $basicAuthListener = new BasicAuthListener;
        $basicAuthListener->attach($application->getEventManager());
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
