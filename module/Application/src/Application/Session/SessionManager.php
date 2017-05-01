<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Session;

use Zend\Session\SessionManager as ZendSessionManager;

/**
 * Extends the basic SessionManager to add support for
 * restarting sessions after they were stopped.
 */
class SessionManager extends ZendSessionManager
{
    protected $defaultStorageClass = 'Application\Session\Storage\SessionArrayStorage';

    /**
     * Extends parent to allow re-starting a session.
     *
     * @param  bool $preserveStorage    If set to true, current session storage will not be overwritten by the
     *                                  contents of $_SESSION. Doesn't apply for restarted sessions.
     * @return void
     * @throws \Zend\Session\Exception\RuntimeException
     */
    public function start($preserveStorage = false)
    {
        // if we already have a session but its immutable; fire it back up
        $storage = $this->getStorage();
        if ($storage && $storage->isImmutable() && method_exists($storage, 'markMutable')) {
            session_start();
            $storage->markMutable();
            return;
        }

        parent::start($preserveStorage);
    }
}
