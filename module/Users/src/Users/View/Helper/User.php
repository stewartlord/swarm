<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\View\Helper;

use Zend\View\Helper\AbstractHelper;

class User extends AbstractHelper
{
    /**
     * Provides access to the current user from the view.
     */
    public function __invoke()
    {
        try {
            return $this->getView()
                        ->getHelperPluginManager()
                        ->getServiceLocator()
                        ->get('user');
        } catch (\Exception $e) {
            return new \Users\Model\User;
        }
    }
}
