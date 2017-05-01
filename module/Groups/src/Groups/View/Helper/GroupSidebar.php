<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Groups\View\Helper;

use Groups\Model\Group;
use Zend\View\Helper\AbstractHelper;

class GroupSidebar extends AbstractHelper
{
    /**
     * Returns the markup for a group sidebar.
     *
     * @param   Group|string  $group  the group to render sidebar for
     * @return  string        markup for the group sidebar
     */
    public function __invoke($group)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $group    = $group instanceof Group ? $group : Group::fetch($group, $services->get('p4_admin'));
        $owners   = $group->getOwners();
        $members  = Group::fetchAllMembers($group->getId());
        $user     = $services->get('user');
        $isMember = in_array($user->getId(), $members);

        $html = '<div class="span3 profile-sidebar group-sidebar">'
              .   '<div class="profile-info">'
              .     '<div class="title pad2 padw3">'
              .       '<h4>' . $view->te('About') . '</h4>'
              .     '</div>'
              .     '<div class="body">';

        $description = $group->getConfig()->getDescription();
        if ($description) {
            $html .= '<div class="description force-wrap pad3">'
                  .    $view->preformat($description)
                  .  '</div>';
        }

        $html .=     '<div class="metrics pad2 padw4">'
              .        '<ul class="force-wrap clearfix">'
              .          '<li class="owners pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($owners) . '</span><br>'
              .            $view->tpe('Owner', 'Owners', count($owners))
              .          '</li>'
              .          '<li class="members pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($members) . '</span><br>'
              .            $view->tpe('Member', 'Members', count($members))
              .          '</li>'
              .        '</ul>'
              .      '</div>'
              .    '</div>'
              .  '</div>';

        if ($owners) {
            $html .= '<div class="owners profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Owners') . '</div>'
                  .    $view->avatars($owners, 5)
                  .  '</div>';
        }

        if ($members) {
            $html .= '<div class="members profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Members') . '</div>'
                  .    $view->avatars($members, 5)
                  .  '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
