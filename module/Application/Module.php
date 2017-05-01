<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application;

use Application\Permissions\Csrf\Listener as CsrfListener;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Response\CallbackResponseSender;
use Application\View\Http\ExceptionStrategy;
use Application\View\Http\RouteNotFoundStrategy;
use Application\View\Http\StrictJsonStrategy;
use Zend\Http\Response as HttpResponse;
use Zend\Http\Request as HttpRequest;
use Zend\Log\Logger;
use Zend\Mvc\MvcEvent;
use Zend\Mvc\ResponseSender\SendResponseEvent;
use Zend\Mvc\Router\Http\RouteMatch;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Zend\Stdlib\Parameters;
use Zend\Validator\AbstractValidator;

class Module
{
    const   PROPERTY_SWARM_URL          = 'P4.Swarm.URL';
    const   PROPERTY_SWARM_COMMIT_URL   = 'P4.Swarm.CommitURL';

    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $config      = $services->get('config');
        $events      = $application->getEventManager();

        $services->setAllowOverride(true);

        // attach our custom exception strategy to control http result codes
        $exceptionStrategy = new ExceptionStrategy;
        $exceptionStrategy->attach($events);

        // attach our JSON strategy to strictly enforce JSON output when requested
        $strictJsonStrategy = new StrictJsonStrategy;
        $strictJsonStrategy->attach($events);

        // remove Zend's route not found strategy and attach our own
        // ours does not muck with JSON responses (we think that is bad)
        $this->replaceNotFoundStrategy($event);

        // attempt to select a UTF-8 compatible locale, this is important
        // to avoid corrupting unicode characters when manipulating strings
        $this->setUtf8Locale($services);

        // enable localized validator messages
        $translator = $services->get('translator');
        AbstractValidator::setDefaultTranslator($translator);

        // only allow same origin framing to deter clickjacking
        if ($application->getResponse() instanceof HttpResponse
            && (isset($config['security']['x_frame_options']) && $config['security']['x_frame_options'])
        ) {
            $application->getResponse()->getHeaders()->addHeaderLine(
                'X-Frame-Options: ' . $config['security']['x_frame_options']
            );
        }

        // if strict https is set, tell browsers to only use SSL for the next 30 days
        // if strict https is set, along with redirect, and we're on http add a meta-refresh to goto https
        if ($application->getResponse() instanceof HttpResponse
            && isset($config['security']['https_strict']) && $config['security']['https_strict']
        ) {
            // always add the HSTS header, HTTP Clients will just ignore it
            $application->getResponse()->getHeaders()->addHeaderLine('Strict-Transport-Security: max-age=2592000');

            // if we came in on http and redirection is enabled, add a meta-refresh
            $uri = $application->getRequest()->getUri();
            if ($uri->getScheme() == 'http'
                && isset($config['security']['https_strict_redirect']) && $config['security']['https_strict_redirect']
            ) {
                $port = isset($config['security']['https_port']) ? $config['security']['https_port'] : null;
                $uri  = clone $uri;
                $uri->setScheme('https');
                $uri->setPort($port);
                $services->get('ViewHelperManager')->get('HeadMeta')->appendHttpEquiv('Refresh', '0;URL=' . $uri);
            }
        }

        // ensure a timezone was set to quell later warnings
        date_default_timezone_set(@date_default_timezone_get());

        // enable the display of errors in development mode.
        $dev    = isset($config['environment']['mode']) && $config['environment']['mode'] == 'development';
        ini_set('display_errors',         $dev ? 1 : 0);
        ini_set('display_startup_errors', $dev ? 1 : 0);
        if ($dev) {
            $services->get('viewmanager')
                     ->getExceptionStrategy()
                     ->setDisplayExceptions(true);
        }

        // attach callback response sender, making it the penultimate listener (to avoid hitting the catch-all)
        // default listeners are registered in Zend\Mvc\SendResponseListener::attachDefaultListeners()
        $responseListener = $services->get('SendResponseListener')->getEventManager();
        $responseListener->attach(SendResponseEvent::EVENT_SEND_RESPONSE, new CallbackResponseSender(), -3500);

        // log exceptions.
        $events->attach(
            array(MvcEvent::EVENT_DISPATCH_ERROR, MvcEvent::EVENT_RENDER_ERROR),
            function ($event) use ($services) {
                $exception = $event->getParam('exception');
                $logger    = $services->get('logger');
                $priority  = Logger::CRIT;

                if (!$exception) {
                    return;
                }

                if ($exception instanceof UnauthorizedException || $exception instanceof ForbiddenException) {
                    $priority = Logger::DEBUG;
                }

                $logger->log($priority, $exception);
            }
        );

        // require login to view swarm if the require_login parameter is set to true
        if (isset($config['security']['require_login']) && $config['security']['require_login']) {
            $events->attach(
                MvcEvent::EVENT_ROUTE,
                function ($event) use ($services) {
                    $config              = $services->get('config');
                    $routeMatch          = $event->getRouteMatch();
                    $config['security'] += array('login_exempt' => array());
                    $exemptRoutes        = (array) $config['security']['login_exempt'];

                    // continue if route is login exempt
                    if (in_array($routeMatch->getMatchedRouteName(), $exemptRoutes)) {
                        return;
                    }

                    // forward to login method if the user isn't logged in
                    if (!$services->get('permissions')->is('authenticated')) {
                        $routeMatch = new RouteMatch(
                            array(
                                'controller' => 'Users\Controller\Index',
                                'action'     => 'login'
                            )
                        );

                        // clear out the post and query parameters, preserving the "format" if it specifies JSON
                        $query = new Parameters;
                        $post  = new Parameters;
                        if (strtolower($event->getRequest()->getQuery('format')) === 'json') {
                            $query->set('format', 'json');
                        }

                        $routeMatch->setMatchedRouteName('login');
                        $event->setRouteMatch($routeMatch);
                        $event->getRequest()->setPost($post)->setQuery($query);
                        $event->getResponse()->setStatusCode(401);
                    }
                },
                -1000 // execute last after the route has been determined
            );
        }

        // enforce CSRF protection
        // if this isn't a get request, isn't an exempt route and isn't an anonymous user; you best be token'ed
        $csrfListener = new CsrfListener($services);
        $csrfListener->attach($events);

        // normalize the hostname if one is set.
        // users might erroneously include a scheme or port when all we want is a host.
        if (!empty($config['environment']['hostname'])) {
            preg_match('#^([a-z]+://)?(?P<hostname>[^:]+)?#', $config['environment']['hostname'], $matches);
            $config['environment']['hostname'] = isset($matches['hostname']) ? $matches['hostname'] : null;
            $services->setService('config', $config);
        }

        // derive the hostname from the request if one isn't set.
        if (empty($config['environment']['hostname']) && $application->getRequest() instanceof HttpRequest) {
            $config['environment']['hostname'] = $application->getRequest()->getUri()->getHost();
            $services->setService('config', $config);
        }

        // ensure the various view helpers use our escaper as it
        // will replace invalid utf-8 byte sequences with an inverted
        // question mark, zend's version would simply blow up.
        $escaper = new Escaper\Escaper;
        $helpers = $services->get('ViewHelperManager');
        $helpers->get('escapeCss')->setEscaper($escaper);
        $helpers->get('escapeHtml')->setEscaper($escaper);
        $helpers->get('escapeHtmlAttr')->setEscaper($escaper);
        $helpers->get('escapeJs')->setEscaper($escaper);
        $helpers->get('escapeUrl')->setEscaper($escaper);
        $helpers->get('escapeFullUrl')->setEscaper($escaper);

        // define the version constants
        $file    = BASE_PATH . '/Version';
        $values  = file_exists($file) ? parse_ini_file($file) : array();
        $values += array('RELEASE' => 'unknown', 'PATCHLEVEL' => 'unknown', 'SUPPDATE' => date('Y/m/d'));
        if (!defined('VERSION_NAME')) {
            define('VERSION_NAME',       'SWARM');
        }
        if (!defined('VERSION_RELEASE')) {
            define('VERSION_RELEASE',    strtr(preg_replace('/ /', '.', $values['RELEASE'], 1), ' ', '-'));
        }
        if (!defined('VERSION_PATCHLEVEL')) {
            define('VERSION_PATCHLEVEL', $values['PATCHLEVEL']);
        }
        if (!defined('VERSION_SUPPDATE')) {
            define('VERSION_SUPPDATE',   strtr($values['SUPPDATE'], ' ', '/'));
        }
        if (!defined('VERSION')) {
            define(
                'VERSION',
                VERSION_NAME . '/' . VERSION_RELEASE . '/' . VERSION_PATCHLEVEL . ' (' . VERSION_SUPPDATE . ')'
            );
        }

        // attach cache-clearing task to worker #1 shutdown
        $services->get('queue')->getEventManager()->attach(
            'worker.shutdown',
            function ($event) use ($services) {
                // only run for the first worker
                if ($event->getParam('slot') !== 1) {
                    return;
                }

                try {
                    $p4Admin = $services->get('p4_admin');
                    $cache   = $p4Admin->getService('cache');
                    $cache->removeInvalidatedFiles();
                } catch (Exception $e) {
                    $services->get('logger')->err($e);
                }
            }
        );

        // set base url on the request
        // we take base url from the config if set, otherwise from the server (defaults to empty string)
        $request = $application->getRequest();
        $baseUrl = isset($config['environment']['baseurl'])
            ? $config['environment']['baseurl']
            : $request->getServer()->get('REQUEST_BASE_URL', '');
        $request->setBaseUrl($baseUrl);

        // connect to worker startup to set the swarm host url properties
        $services->get('queue')->getEventManager()->attach(
            'worker.startup',
            function ($event) use ($services, $config) {
                $p4       = $services->get('p4_admin');
                $register = isset($config['p4']['auto_register_url']) && $config['p4']['auto_register_url'];

                // only run for the first worker on new enough servers (2013.1+).
                if ($event->getParam('slot') !== 1 || !$p4->isServerMinVersion('2013.1')) {
                    return;
                }

                $mainKey    = Module::PROPERTY_SWARM_URL;
                $commitKey  = Module::PROPERTY_SWARM_COMMIT_URL;
                $value      = $p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
                $url        = $services->get('viewhelpermanager')->get('qualifiedUrl');
                $info       = $p4->getInfo();
                $serverType = isset($info['serverServices']) ? $info['serverServices'] : null;
                $isEdge     = strpos($serverType, 'edge-server')   !== false;
                $isCommit   = strpos($serverType, 'commit-server') !== false;

                // set main URL property so that P4V (or others) can find Swarm
                // set if empty or doesn't match and this is not an edge server
                // we don't change the value if we are talking to an edge server
                // because the value could point to a commit Swarm
                if ($register && (!$value || ($value !== $url() && !$isEdge))) {
                    $p4->run('property', array('-a', '-n', $mainKey, '-v', $url(), '-s0'));
                }

                // set commit url property so that edge Swarm's can find commit Swarm's
                if ($isCommit) {
                    $p4->run('property', array('-a', '-n', $commitKey, '-v', $url(), '-s0'));
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

    /**
     * Remove Zend's route not found strategy and attach our own
     * ours does not muck with JSON responses (we think that is bad)
     *
     * @param MvcEvent $event
     */
    public function replaceNotFoundStrategy(MvcEvent $event)
    {
        $application      = $event->getApplication();
        $services         = $application->getServiceManager();
        $events           = $application->getEventManager();
        $notFoundStrategy = $services->get('viewmanager')->getRouteNotFoundStrategy();
        $sharedEvents     = $events->getSharedManager();
        $sharedListeners  = $sharedEvents->getListeners('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH);

        // detach from the general event manager
        $notFoundStrategy->detach($events);

        // detach from the shared events manager
        foreach ($sharedListeners as $listener) {
            if (current($listener->getCallback()) === $notFoundStrategy) {
                $sharedEvents->detach('Zend\Stdlib\DispatchableInterface', $listener);
            }
        }

        $oldNotFoundStrategy = $notFoundStrategy;
        $notFoundStrategy    = new RouteNotFoundStrategy;

        // preserve behaviour from old strategy instance
        $notFoundStrategy->setDisplayExceptions($oldNotFoundStrategy->displayExceptions());
        $notFoundStrategy->setDisplayNotFoundReason($oldNotFoundStrategy->displayNotFoundReason());
        $notFoundStrategy->setNotFoundTemplate($oldNotFoundStrategy->getNotFoundTemplate());

        // update the stored service and attach the strategy to the event manager
        $services->setService('RouteNotFoundStrategy', $notFoundStrategy);
        $notFoundStrategy->attach($events);
    }

    /**
     * Set the locale to one that supports UTF-8.
     *
     * Note: we only change the locale for LC_CTYPE as we only
     * want to affect the behavior of string manipulation.
     *
     * @param ServiceLocator $services the service locator for logging purposes
     */
    protected function setUtf8Locale(ServiceLocator $services)
    {
        $logger  = $services->get('logger');
        $pattern = '/\.utf\-?8$/i';

        // if we are already using a utf8 locale, nothing to do.
        if (preg_match($pattern, setlocale(LC_CTYPE, 0))) {
            return;
        }

        // we don't want to run 'locale -a' for every request - cache it for 1hr.
        $cacheFile = DATA_PATH . '/cache/system-locales';
        \Record\Cache\Cache::ensureWritable(dirname($cacheFile));
        if (file_exists($cacheFile) && (time() - (int) filemtime($cacheFile)) < 3600) {
            $fromCache = true;
            $locales   = unserialize(file_get_contents($cacheFile));
        } else {
            $fromCache = false;
            exec('locale -a', $locales, $result);
            if ($result) {
                $logger->err("Failed to exec 'locale -a'. Exit status: $result.");
                $locales = array();
            }
            file_put_contents($cacheFile, serialize($locales));
        }

        foreach ($locales as $locale) {
            if (preg_match($pattern, $locale) && setlocale(LC_CTYPE, $locale) !== false) {
                return;
            }
        }

        // we don't want to complain for every request - only report errors every 1hr.
        if (!$fromCache) {
            $logger->err("Failed to set a UTF-8 compatible locale.");
        }
    }
}
