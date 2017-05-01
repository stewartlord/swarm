<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Permissions\Csrf;

use Application\Session\Container as SessionContainer;
use P4\Uuid\Uuid;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

/**
 * Handles generation and testing of CSRF token.
 * Note if the user is not authenticated no token will be returned.
 */
class Service
{
    const CSRF_CONTAINER = 'csrf';

    protected $services = null;

    /**
     * Ensure we get a service locator on construction.
     *
     * @param   ServiceLocator  $services   the service locator to use
     */
    public function __construct(ServiceLocator $services)
    {
        $this->services = $services;
    }

    /**
     * Get the current CSRF token, if the user is authenticated and doesn't
     * already have a token; one will be created and returned.
     * For anonymous users null is returned.
     *
     * @return  string  the active CSRF token, one will be created if needed
     */
    public function getToken()
    {
        // if the user isn't authenticated simply return null
        $services = $this->services;
        if (!$services->get('permissions')->is('authenticated')) {
            return null;
        }

        // if we don't have a token; make one
        $session   = $services->get('session');
        $container = new SessionContainer(static::CSRF_CONTAINER, $session);
        if (!$container['token']) {
            $session->start();
            $container['token'] = (string) new Uuid;
            $session->writeClose();
        }

        return $container['token'];
    }

    /**
     * Ensures the passed token is valid/correct. If it isn't an exception is thrown.
     *
     * @param   string  $token  the token value to check
     * @return  Csrf            to maintain a fluent interface
     * @throws  Exception       if the token is invalid
     */
    public function enforce($token)
    {
        if (!$this->isValid($token)) {
            throw new Exception;
        }

        return $this;
    }

    /**
     * Checks if the passed token is valid/correct. Simply returns the result, no exceptions.
     *
     * @param   string  $token  the token to check
     * @return  bool    true if token is correct, false otherwise
     */
    public function isValid($token)
    {
        $expected = $this->getToken();
        return $expected === null || $expected === $token;
    }
}
