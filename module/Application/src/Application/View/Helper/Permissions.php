<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Zend\View\Helper\AbstractHelper;

/**
 * A convenience helper to expose the permissions service to the view.
 */
class Permissions extends AbstractHelper implements ServiceLocatorAwareInterface
{
    protected $serviceLocator = null;

    /**
     * Set the service locator.
     *
     * @param   ServiceLocator  $serviceLocator     the service locator
     * @return  Security        to maintain a fluent interface
     */
    public function setServiceLocator(ServiceLocator $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        return $this;
    }

    /**
     * Get the service locator.
     *
     * @return  ServiceLocator  the service locator set on this instance
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * Simply returns the permissions service.
     *
     * @return Application\Permissions\Permissions  the permissions class
     */
    public function __invoke()
    {
        return $this->getView()->getHelperPluginManager()->getServiceLocator()->get('permissions');
    }
}
