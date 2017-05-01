<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Disconnect from the browser to begin long-running tasks.
 */
class Disconnect extends AbstractPlugin
{
    /**
     * Does the actual work of disconnecting.
     *
     * @returns Disconnect  To maintain a fluent interface
     */
    public function __invoke()
    {
        ignore_user_abort(true);

        // disable gzip compression in apache, as it can result in this request
        // being buffered until it is complete, regardless of other settings.
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', 1);
        }

        // tell client to disconnect.
        $request  = $this->getController()->getRequest();
        $response = $this->getController()->getResponse();

        // if we have output buffering and this isn't a test
        // include any buffered content and end buffering
        if (ob_get_level() && !$request->isTest) {
            $response->setContent(ob_get_contents() . $response->getContent());
            ob_end_clean();
        }

        // ensure headers indicate this connection is done and include an accurate length
        $headers  = $response->getHeaders();
        $headers->addHeaderLine('Connection: close');
        $headers->addHeaderLine('Content-length: ' . strlen($response->getContent()));

        // send everything and clear out content just to be safe
        $response->send();
        $response->setContent(null);
        session_write_close();
        flush();

        // flush all response data to the client if using FastCGI PHP implementation (e.g. PHP-FPM)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return $this;
    }
}
