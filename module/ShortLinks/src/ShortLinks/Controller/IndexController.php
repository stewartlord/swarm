<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace ShortLinks\Controller;

use Record\Exception\NotFoundException;
use ShortLinks\Model\ShortLink;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $request  = $this->getRequest();
        $services = $this->getServiceLocator();
        $config   = $services->get('config');
        $p4Admin  = $services->get('p4_admin');
        $route    = $this->getEvent()->getRouteMatch();
        $link     = $route->getParam('link');
        $qualify  = $services->get('viewhelpermanager')->get('qualifiedUrl');

        // a post with no link id indicates a request to shorten a URI.
        if ($request->isPost() && !strlen($link)) {
            $uri = $request->getPost('uri');
            if (!$uri) {
                $this->getResponse()->setStatusCode(400);
                return new JsonModel(
                    array(
                        'isValid'   => false,
                        'error'     => 'Cannot shorten link. No URI given.'
                    )
                );
            }

            // URIs must start with http(s) or a slash '/'.
            if (!preg_match('#^https?://|^/#i', $uri)) {
                $this->getResponse()->setStatusCode(400);
                return new JsonModel(
                    array(
                        'isValid'   => false,
                        'error'     => "Cannot shorten link. URI must start with 'http(s)://' or '/'."
                    )
                );
            }

            // check for existing short-link
            try {
                $link = ShortLink::fetchByUri($uri, $p4Admin);
            } catch (NotFoundException $e) {
                // this just means we need to make one
            }

            // if no existing short-link, make one
            if (!$link) {
                $link = new ShortLink($p4Admin);
                $link->set('uri', $uri)->save();
            }

            // prepare uri (qualify it)
            // if we have a short host, use it and put the link id at the root
            // otherwise, just use short-link route on the standard host
            $id        = $link->getObfuscatedId();
            $shortHost = !empty($config['short_links']['hostname']) ? $config['short_links']['hostname'] : null;
            $uri       = $shortHost
                ? 'http://' . $shortHost . '/' . $id
                : trim($qualify('short-link', array('link' => $id)), '/');

            return new JsonModel(
                array(
                    'isValid' => true,
                    'id'      => $id,
                    'uri'     => $uri
                )
            );
        }

        // still here? we must be resolving a link
        // note: we don't use the short-host when redirecting to relative URIs
        // because it is possible (although unlikely) to be ambiguous
        if (strlen($link)) {
            try {
                $link = ShortLink::fetchByObfuscatedId($link, $p4Admin);
                $uri  = ShortLink::qualifyUri($link->getUri(), $qualify());
                return $this->redirect()->toUrl($uri);
            } catch (NotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
                // we'll handle these as a 404 below
            }
        }

        // no link found, report 404 error
        $this->getResponse()->setStatusCode(404);
    }
}
