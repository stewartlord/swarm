<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\View\Helper;

use Users\Model\User as UserModel;
use Zend\View\Helper\AbstractHelper;

class UserLink extends AbstractHelper
{
    /**
     * Outputs the user ID and linkifies it if the user exists
     *
     * @param   string  $user       the user id to output and, if able, link to
     * @param   bool    $strong     optional, if false (default) not strong, if true user id wrapped in strong tag
     * @param   string  $baseUrl    optional, if specified, given string will be prepended to links
     * @return  string  the user id as a link if the user exists
     */
    public function __invoke($user, $strong = false, $baseUrl = null)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $label    = $view->escapeHtml($user);

        if ($strong) {
            $label = '<strong>' . $label . '</strong>';
        }

        if (!UserModel::exists($user, $p4Admin)) {
            return $label;
        }

        return '<a href="' . $baseUrl . $view->url('user', array('user' => $user)) . '">' . $label . '</a>';
    }
}
