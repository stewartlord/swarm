<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Xhprof;

use Zend\Mvc\MvcEvent;

/**
 * Enables xhprof-based profiling of execution paths/times. Useful for optimizing code.
 */
class Module
{
    /**
     * Write xhprof output on shutdown when profiling is enabled
     *
     * @param   MvcEvent $event the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();

        // exit early if xhprof is not loaded or the request is in Test mode
        if (!extension_loaded('xhprof') || $application->getRequest()->isTest) {
            return;
        }

        $services    = $application->getServiceManager();
        $events      = $application->getEventManager();
        $config      = $services->get('config') + array('xhprof' => array());
        $config      = $config['xhprof'] + array(
                           'slow_time'            => 3,
                           'report_file_lifetime' => 86400 * 7,
                           'ignored_routes'       => array()
                       );

        // listen for post-routing event so that the shutdown function can be registered
        // this allows us to block out some routes that we want to ignore
        $module = $this;
        $events->attach(
            MvcEvent::EVENT_ROUTE,
            function ($event) use ($config, $services, $module) {
                $routeMatch    = $event->getRouteMatch();
                $seconds       = $config['slow_time'];
                $ignoredRoutes = $config['ignored_routes'];

                // if current route is ignored: halt profiling, discard output, do not register shutdown handler
                if (in_array($routeMatch->getMatchedRouteName(), $ignoredRoutes)) {
                    xhprof_disable();
                    return;
                }

                register_shutdown_function(array($module, 'shutdownHandler'), $seconds, $services);
            },
            -1010 // execute after the security subsystem has determined the route is OK
        );

        // clean up the xhprof folder in case it gets too full
        $services->get('queue')->getEventManager()->attach(
            'worker.shutdown',
            function ($event) use ($config, $services) {
                // only run for the first worker.
                if ($event->getParam('slot') !== 1) {
                    return;
                }

                $path = DATA_PATH . '/xhprof';

                if (!is_dir($path)) {
                    return;
                }

                // delete xhprof files older than 'report_file_lifetime' (default: 1 week)
                $files  = glob($path . '/*.swarm.xhprof');
                $errors = array();
                foreach ($files as $file) {
                    if (filemtime($file) < time() - $config['report_file_lifetime']) {
                        @unlink($file);
                        if (file_exists($file)) {
                            $errors[] = $file;
                        }
                    }
                }

                if ($errors) {
                    $message = 'Unable to clean up ' . count($errors) . ' stale xhprof file(s). '
                             . 'Please verify that Swarm has write permission on ' . $path . '. '
                             . (count($errors) > 5 ? 'Some of the affected files: ' : 'Affected file(s): ')
                             . implode(', ', array_slice($errors, 0, 5));
                    $services->get('logger')->err($message);
                }
            }
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Shutdown handler for writing profile information on exit.
     *
     * @param $seconds      only write profiling information when runtime > $seconds
     * @param $services     service container - will be used to fetch logger, if necessary
     */
    public function shutdownHandler($seconds, $services)
    {
        // return if xhprof is not loaded (nothing to do)
        if (!extension_loaded('xhprof')) {
            return;
        }

        $data = (array) xhprof_disable();

        // skip writing if $data is empty or malformed; log findings
        if (!$data || !isset($data['main()']['wt'])) {
            $services->get('logger')->err('Discarding unexpected result from xhprof_disable()');
            return;
        }

        $totalTime = $data['main()']['wt'];
        $slowTime  = $seconds * 1000 * 1000;

        // capture executions longer than $seconds (converted to microseconds)
        if ($totalTime > $slowTime) {
            $path = DATA_PATH . '/xhprof';
            $file = $path . '/' . uniqid() . '.swarm.xhprof';

            // ensure cache dir exists and is writable
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                @chmod($path, 0755);
            }

            // if the path is unwritable, there's nothing to do
            if (!is_dir($path) || !is_writable($path)) {
                $services->get('logger')->err('Unable to write to directory ' . $path);
                return;
            }

            $extra = array_intersect_key(
                $_SERVER,
                array_flip(
                    array(
                        'REQUEST_URI',
                        'QUERY_STRING',
                        'HTTP_REFERER',
                        'HTTP_USER_AGENT',
                    )
                )
            );

            $extra['timestamp']      = time();
            $data['main()']['extra'] = $extra;

            file_put_contents($file, serialize($data));
        }
    }
}
