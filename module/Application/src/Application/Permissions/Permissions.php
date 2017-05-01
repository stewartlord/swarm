<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Permissions;

use Application\Permissions\Exception\Exception;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Session\Container as SessionContainer;
use Groups\Model\Group;
use Projects\Model\Project;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

/**
 * Allows enforcing or simply testing for various permissions:
 * - authenticated
 * - admin
 * - super
 * - member (of $project or $group)
 *
 * The 'checks' can be passed as a string (for all but member) or in an array.
 * Note, the member check is passed as the key with the project to test the value.
 *
 * e.g.:
 * is('admin')
 * or
 * isOne(array('admin', 'member' => $project))
 */
class Permissions
{
    const MAX_ACCESS_CACHE_CONTAINER = 'max_access';
    const MAX_ACCESS_CACHE_EXPIRY    = 3600;

    protected $serviceLocator        = null;

    /**
     * Ensure we get a service locator on construction.
     *
     * @param   ServiceLocator  $serviceLocator     the service locator to use
     */
    public function __construct(ServiceLocator $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * Ensure all of the specified checks pass but simply return the result.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, all must pass
     * @return  bool            true if all checks pass, false otherwise
     */
    public function is($checks)
    {
        try {
            $this->enforce($checks);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Ensure at least one of the specified checks passes but simply return the result.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, one must pass
     * @return  bool            true if at least one checks passes, false otherwise
     */
    public function isOne($checks)
    {
        try {
            $this->enforceOne($checks);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Ensure all of the specified checks pass throwing on failure.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, all must pass
     * @return  Permissions     to maintain a fluent interface
     * @throws  UnauthorizedException       if the user is not authenticated
     * @throws  ForbiddenException          if the user is logged in but fails a check
     * @throws  \InvalidArgumentException   for invalid checks or invalid data on a check
     * @throws  \Exception                  if unexpected errors occur
     */
    public function enforce($checks)
    {
        $checks = (array) $checks;

        // all checks require access to the user's account so we actually start with
        // the authenticated test even if it isn't requested
        try {
            $p4User = $this->serviceLocator->get('p4_user');
        } catch (ServiceNotCreatedException $e) {
            // dig down a level if possible; should result in 'unauthorized' exception
            throw $e->getPrevious() ?: $e;
        }

        foreach ($checks as $check => $value) {
            if ((string) $check === (string)(int) $check) {
                $check = $value;
                $value = null;
            }

            switch ($check) {
                case 'authenticated':
                    // this has already been handled
                    break;
                case 'admin':
                    if ($this->getMaxAccess() !== 'admin' && $this->getMaxAccess() !== 'super') {
                        throw new ForbiddenException("Your account does not have admin privileges.");
                    }
                    break;
                case 'super':
                    if ($this->getMaxAccess() !== 'super') {
                        throw new ForbiddenException("Your account does not have super privileges.");
                    }
                    break;
                case 'member':
                    // this check has different meaning based on the input:
                    // - if the value is an instance of the Project class, it will enforce a user
                    //   to be a member of that project
                    // - if the value is an instance of the Group class, it will enforce a user
                    //   to be direct or indirect member of that group
                    // - if the value is a string, it will consider the value as Perforce group id
                    //   and check if the user is a direct or indirect member
                    // An array of Group IDs and/or Project objects may be passed. The result will
                    // be true if the user is a member of at least one of the passed items.

                    // Deal with an array of values; we'll just call ourselves for each and complain if none hit
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            if ($this->is(array('member' => $item))) {
                                break 2;
                            }
                        }

                        throw new ForbiddenException("Your account is not a member of any of the passed items.");
                    }

                    // Deal with projects
                    if ($value instanceof Project) {
                        if (!$value->isMember($p4User->getUser())) {
                            throw new ForbiddenException("This operation is limited to project members.");
                        }

                        break;
                    }

                    // Deal with groups
                    $value = $value instanceof Group ? $value->getId() : $value;
                    if (is_string($value)) {
                        $p4Admin = $this->serviceLocator->get('p4_admin');
                        if (!Group::isMember($p4User->getUser(), $value, true, $p4Admin)) {
                            throw new ForbiddenException("This operation is limited to group members.");
                        }

                        break;
                    }

                    // Looks like an invalid input value; complain loudly
                    throw new \InvalidArgumentException(
                        "The member test requires a project, group or a group ID as input."
                    );
                    break;
                case 'owner':
                    if (!$value instanceof Project && !$value instanceof Group) {
                        throw new \InvalidArgumentException(
                            "The owner access test requires a project or group as input."
                        );
                    }
                    if (!in_array($p4User->getUser(), $value->getOwners())) {
                        throw new ForbiddenException("This operation is limited to project or group owners.");
                    }
                    break;
                case 'projectAddAllowed':
                    // this check will pass if user is allowed to add projects and fails otherwise
                    // user is allowed to add projects if:
                    //  - is authenticated (this is handled implicitly by this method)
                    //  - is also an admin if $config['projects']['add_admin_only'] is set to true
                    //  - is also a member of at least one of the groups specified in
                    //    $config['projects']['add_groups_only'] if this value is set and not empty
                    $config = $this->serviceLocator->get('config');

                    // check admin restriction if required (for backwards compatibility, we take
                    // values from $config['security']['add_project_admin_only'] as defaults)
                    $adminOnly = isset($config['security']['add_project_admin_only'])
                        && $config['security']['add_project_admin_only'];
                    $adminOnly = isset($config['projects']['add_admin_only'])
                        ? (bool) $config['projects']['add_admin_only']
                        : $adminOnly;
                    if ($adminOnly) {
                        $this->enforce('admin');
                    }

                    // check project groups restriction if specified (for backwards compatibility,
                    // we take values from $config['security']['add_project_groups'] as defaults)
                    $addProjectGroups = isset($config['security']['add_project_groups'])
                        ? array_filter((array) $config['security']['add_project_groups'])
                        : false;
                    $addProjectGroups = isset($config['projects']['add_groups_only'])
                        ? array_filter((array) $config['projects']['add_groups_only'])
                        : $addProjectGroups;
                    if ($addProjectGroups) {
                        $this->enforce(array('member' => $addProjectGroups));
                    }
                    break;
                case 'groupAddAllowed':
                    // this check will pass if user is allowed to add groups in Swarm and fails otherwise
                    // we honour Perforce restrictions, i.e. user must be admin if server >=2012.1
                    // or super for older servers (<2012.1)
                    $this->enforce($p4User->isServerMinVersion('2012.1') ? 'admin' : 'super');
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'The specified permission is unknown/invalid'
                    );
            }
        }

        return $this;
    }

    /**
     * Ensure at least one of the specified checks passes throwing on failure.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, one must pass
     * @return  Permissions     to maintain a fluent interface
     * @throws  UnauthorizedException       if the user is not logged in
     * @throws  ForbiddenException          if the user is logged in but fails a check
     * @throws  \InvalidArgumentException   for invalid checks or invalid data on a check
     * @throws  \Exception                  if unexpected errors occur
     */
    public function enforceOne($checks)
    {
        foreach ($checks as $key => $value) {
            try {
                $this->enforce(array($key => $value));
                return $this;
            } catch (Exception $e) {
                // ignored if we hit at least one success
            }
        }

        // if we didn't encounter any passing checks, throw either the last exception
        // we hit or, if no checks were present, a complaint about the lack of checks.
        throw isset($e) ? $e : new ForbiddenException('Permissions enforce called with no conditions.');
    }

     /**
     * Get max access level for the given connection. The value is stored in time-based session cache.
     *
     * @return  string|false    max level access or false
     */
    protected function getMaxAccess()
    {
        $p4      = $this->serviceLocator->get('p4_user');
        $session = $this->serviceLocator->get('session');
        $key     = md5(serialize(array($p4->getUser(), $p4->getPort())));
        $cache   = new SessionContainer(static::MAX_ACCESS_CACHE_CONTAINER, $session);

        try {
            $maxAccess = $cache[$key];
        } catch (\Zend\Session\Exception\RuntimeException $e) {
            if (strpos($e->getMessage(), 'isImmutable') === false) {
                throw $e;
            }
            $session->start();
            $maxAccess = $cache[$key];
            $session->writeClose();
        }

        // if max-access is cached, we're done
        if ($maxAccess) {
            return $maxAccess;
        }

        // session cache is empty or has expired, determine max access level from the connection
        $remoteIp     = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $maxAccess    = $p4->getMaxAccess();
        $remoteAccess = $remoteIp ? $p4->getMaxAccess($remoteIp) : $maxAccess;

        // we take the weaker value of general vs. host-restricted access if they differ
        if ($maxAccess !== $remoteAccess) {
            // if we don't recognize the access level, log the case and return false
            $levels = array_flip(array('list', 'read', 'open', 'write', 'admin', 'super'));
            if (!isset($levels[$maxAccess]) || !isset($levels[$remoteAccess])) {
                $logger = $this->serviceLocator->get('logger');
                $logger->warn(
                    "Unrecognized access level '" . (!isset($levels[$maxAccess]) ? $maxAccess : $remoteAccess)
                );

                return false;
            }

            $maxAccess = $levels[$remoteAccess] < $levels[$maxAccess] ? $remoteAccess : $maxAccess;
        }

        // update the max-access cache and flag it to expire after 1 hour
        $session->start();
        $cache[$key] = $maxAccess;
        $cache->setExpirationSeconds(static::MAX_ACCESS_CACHE_EXPIRY, $key);
        $session->writeClose();

        return $maxAccess;
    }
}
