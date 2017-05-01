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

class Avatar extends AbstractHelper
{
    const   DEFAULT_COUNT = 6;

    /**
     * Renders a image tag and optional link for the given user's avatar.
     *
     * @param   string|UserModel|null   $user   a user id or user object (null for anonymous)
     * @param   string|int              $size   the size of the avatar (e.g. 64, 128)
     * @param   bool                    $link   optional - link to the user (default=true)
     * @param   bool                    $class  optional - class to add to the image
     * @param   bool                    $fluid  optional - match avatar size to the container
     */
    public function __invoke($user = null, $size = null, $link = true, $class = null, $fluid = true)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config') + array('avatars' => array());
        $config   = $config['avatars'] + array('https_url' => null, 'http_url' => null);

        if (!$user instanceof UserModel) {
            $p4Admin = $services->get('p4_admin');
            if ($user && UserModel::exists($user, $p4Admin)) {
                $user = UserModel::fetch($user, $p4Admin);
            } else {
                $user = $user ?: null;
                $link = false;
            }
        }

        $id       = $user instanceof UserModel ? $user->getId()       : $user;
        $email    = $user instanceof UserModel ? $user->getEmail()    : null;
        $fullName = $user instanceof UserModel ? $user->getFullName() : null;
        $size     = (int) $size ?: '64';

        // pick a default image and color for this user - if no user, pick system avatar
        // we do this by summing the ascii values of all characters in their id
        // then we modulo divide by 6 to get a remainder in the range of 0-5.
        $class .= ' as-' . $size;
        if ($id) {
            $i      = (array_sum(array_map('ord', str_split($id))) % static::DEFAULT_COUNT) + 1;
            $class .= ' ai-' . $i;
            $class .= ' ac-' . $i;
        } else {
            $class .= ' avatar-system';
        }

        // determine the url to use for this user's avatar based on the configured pattern
        // if user is null or no pattern is configured, fallback to a blank gif via data uri
        $url      = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            ? $config['https_url']
            : $config['http_url'];
        $url      = $url && $id
            ? $url
            : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $replace  = array(
            '{user}'    => $id,
            '{email}'   => $email,
            '{hash}'    => $email ? md5(strtolower($email)) : '00000000000000000000000000000000',
            '{default}' => 'blank',
            '{size}'    => $size
        );
        $url      = str_replace(array_keys($replace), array_map('rawurlencode', $replace), $url);

        // build the actual img tag we'll be using
        $fluid    = $fluid ? 'fluid' : '';
        $class    = $view->escapeHtmlAttr(trim('avatar ' . $class));
        $alt      = $view->escapeHtmlAttr($fullName);
        $html     = '<img width="' . $size . '" height="' . $size . '" alt="' . $alt . '"'
                  . ' src="' . $url . '" data-user="' . $view->escapeHtmlAttr($id) . '"'
                  . ' class="' . $class . '" onerror="$(this).trigger(\'img-error\')"'
                  . ' onload="$(this).trigger(\'img-load\')">';

        if ($link && $id) {
            $html = '<a href="' . $view->url('user', array('user' => $id)) . '" title="' . $alt . '"'
                  . ' class="avatar-wrapper avatar-link ' . $fluid . '">' . $html . '</a>';
        } else {
            $html = '<div class="avatar-wrapper ' . $fluid . '">' . $html . '</div>';
        }

        return $html;
    }
}
