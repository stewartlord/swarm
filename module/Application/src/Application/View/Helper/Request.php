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
 * Returns the current request object for use in views.
 */
class Request extends AbstractHelper
{
    public function __invoke()
    {
        $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
        return $services->get('request');
    }
}
