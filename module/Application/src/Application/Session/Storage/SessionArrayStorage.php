<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Session\Storage;

use Zend\Session\Exception\RuntimeException as SessionRuntimeException;
use Zend\Session\Storage\SessionArrayStorage as ZendSessionArrayStorage;

/**
 * Extends the basic session array storage to add support for
 * marking it back to a mutable state. Needed as part of session
 * restarting.
 */
class SessionArrayStorage extends ZendSessionArrayStorage
{
    /**
     * Extend storage initialization to NOT set the request access time.
     * It is not safe to write to the session at this time (no one has started it).
     *
     * @param  array $input
     * @return void
     */
    public function init($input = null)
    {
        if ((null === $input) && isset($_SESSION)) {
            $input = $_SESSION;
            if (is_object($input) && !$_SESSION instanceof \ArrayObject) {
                $input = (array) $input;
            }
        } elseif (null === $input) {
            $input = array();
        }
        $_SESSION = $input;
    }

    /**
     * Extend load from array to NOT set the request access time (consistent with init)
     *
     * @param  array          $array
     * @return SessionStorage
     */
    public function fromArray(array $array)
    {
        $_SESSION = $array;

        return $this;
    }

    /**
     * Offset Set
     * Extends parent to throw is storage is immutable
     *
     * @param   mixed   $key    the key to set a new value on
     * @param   mixed   $value  the value
     * @throws  SessionRuntimeException     if session is immutable
     */
    public function offsetSet($key, $value)
    {
        if ($this->isImmutable()) {
            throw new SessionRuntimeException('Cannot clear storage as it is marked immutable');
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Offset Unset
     * Extends parent to throw is storage is immutable
     *
     * @param   mixed   $key    the key to unset/remove
     * @throws  SessionRuntimeException     if session is immutable
     */
    public function offsetUnset($key)
    {
        if ($this->isImmutable()) {
            throw new SessionRuntimeException('Cannot clear storage as it is marked immutable');
        }

        unset($_SESSION[$key]);
    }

    /**
     * Mark object as Mutable
     *
     * @return SessionArrayStorage
     */
    public function markMutable()
    {
        unset($_SESSION['_IMMUTABLE']);

        return $this;
    }
}
