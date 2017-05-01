<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Application\Escaper\Escaper;
use Zend\View\Helper\AbstractHelper;

class Csrf extends AbstractHelper
{
    /**
     * Returns the CSRF token in use.
     *
     * @return string   the CSRF token
     */
    public function __invoke()
    {
        $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
        $csrf     = $services->get('csrf');
        $escaper  = new Escaper;
        return $escaper->escapeHtml($csrf->getToken());
    }
}
