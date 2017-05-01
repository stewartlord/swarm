<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\View\Helper\AbstractHelper;

/**
 * Behaves just like the url view helper, but returns fully qualified urls.
 * For example: http://some-host:8080/path/to/action
 */
class QualifiedUrl extends AbstractHelper
{
    public function __invoke($name = null, array $params = array(), $options = array(), $reuseMatchedParams = false)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config');
        $strict   = isset($config['security']['https_strict']) && $config['security']['https_strict'];
        $uri      = $services->get('request')->getUri();
        $scheme   = $strict ? 'https' : ($uri->getScheme() ?: 'http');
        $host     = $config['environment']['hostname'] ?: $uri->getHost();
        $host     = $host ?: 'localhost';
        $port     = $uri->getPort() ?: ($scheme == 'https' ? 443 : 80);
        $port     = $scheme == 'https' && isset($config['security']['https_port']) && $config['security']['https_port']
            ? $config['security']['https_port']
            : $port;
        $origin   = $scheme . '://' . $host . ($port && $port != 80 && $port != 443 ? ':' . $port : '');
        $origin   = $view->escapeFullUrl($origin);

        // if no arguments given, return the baseUrl (origin + basePath)
        if (!func_num_args()) {
            return $origin . $view->basePath();
        }

        return $origin . $view->url($name, $params, $options, $reuseMatchedParams);
    }
}
