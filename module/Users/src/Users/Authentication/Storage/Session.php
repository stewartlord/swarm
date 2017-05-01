<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\Authentication\Storage;

use Application\Session\Container;
use Application\Session\SessionManager;
use Zend\Authentication\Storage\Session as ZendSession;

class Session extends ZendSession
{
    /**
     * Extends Zend's auth session storage to use our session container
     * to avoid needlessly re-starting the session.
     *
     * @param  mixed $namespace
     * @param  mixed $member
     * @param  SessionManager $manager
     */
    public function __construct($namespace = null, $member = null, SessionManager $manager = null)
    {
        if ($namespace !== null) {
            $this->namespace = $namespace;
        }
        if ($member !== null) {
            $this->member = $member;
        }
        $this->session = new Container($this->namespace, $manager);
    }
}
