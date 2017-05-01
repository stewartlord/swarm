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

class Avatars extends AbstractHelper
{
    public function __invoke($users = null, $columns = 5, $size = null, $link = true, $class = null)
    {
        // re-index users so that keys are reliably numeric
        $users    = array_values((array) $users);
        $html     = '<div class="avatars">';
        $total    = count($users);
        foreach ($users as $index => $user) {
            $html .= ($index % $columns == 0) ? "<div>" : "";
            $html .= '<span class="border-box">' . $this->getView()->avatar($user, $size, $link, $class) . "</span>";
            $html .= (($index + 1) % $columns == 0 || $index + 1 >= $total) ? "</div>" : "";
        }
        $html     .= '</div>' . PHP_EOL;

        return $html;
    }
}
