<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace ShortLinks;

use Record\Exception\NotFoundException;
use ShortLinks\Model\ShortLink;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * If a short hostname is set, requests for short link ids at the root should
     * first try to match a short-link and if found, redirect to the stored URI.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $config      = $services->get('config');

        // nothing to do here if no short-host has been set
        if (empty($config['short_links']['hostname'])) {
            return;
        }

        // normalize short-host to ensure no scheme and no port
        $shortHost = $config['short_links']['hostname'];
        preg_match('#^([a-z]+://)?(?P<hostname>[^:]+)?#', $shortHost, $matches);
        $shortHost = isset($matches['hostname']) ? $matches['hostname'] : null;
        $config['short_links']['hostname'] = $shortHost;
        $services->setService('config', $config);

        // we should only honor short-links at the root if the request is on the short-host
        // and the short-host differs from the standard host
        $uri = $application->getRequest()->getUri();
        if ($uri->getHost() !== $shortHost || $config['environment']['hostname'] === $shortHost) {
            return;
        }

        // at this point, we know a short-host is set, and the request is for the short-host
        // if the requested path looks like a short-link ID, try to look it up
        if (preg_match('#^/+([a-z0-9]{4,})/?$#i', $uri->getPath(), $matches)) {
            try {
                $link     = ShortLink::fetchByObfuscatedId($matches[1], $services->get('p4_admin'));
                $qualify  = $services->get('viewhelpermanager')->get('qualifiedUrl');
                $redirect = ShortLink::qualifyUri($link->getUri(), $qualify());
            } catch (NotFoundException $e) {
                // we expected this could happen
            }
        }

        // if we didn't match a short-link, we still want to get off the short-host
        // rewrite the original request URI to use the standard hostname
        if (!isset($redirect)) {
            $uri->setHost($config['environment']['hostname']);
            $redirect = $uri->toString();
        }

        // we need to stop the regular route/dispatch processing and send a redirect header
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $redirect);
        $response->setStatusCode(302);
        $response->sendHeaders();

        exit();
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
