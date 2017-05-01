<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Projects\Validator;

use Application\Validator\ConnectedAbstractValidator;

/**
 * Check if the given path is valid branch path.
 */
class BranchPath extends ConnectedAbstractValidator
{
    const UNSUPPORTED_WILDCARDS = 'unsupportedWildcards';
    const INVALID_DEPOT         = 'invalidDepot';
    const UNFOLLOWED_DEPOT      = 'unfollowedDepot';
    const NULL_DIRECTORY        = 'nullDirectory';
    const NO_PATHS              = 'noPaths';

    protected $messageTemplates = array(
        self::UNSUPPORTED_WILDCARDS => "The only permitted wildcard is trailing '...'.",
        self::INVALID_DEPOT         => "The first path component must be a valid depot name.",
        self::UNFOLLOWED_DEPOT      => "Depot name must be followed by a path or '/...'.",
        self::NULL_DIRECTORY        => "The path cannot end with a '/'.",
        self::NO_PATHS              => "No depot paths specified."
    );

    /**
     * In-memory cache of existing depots in Perforce (per connection).
     */
    protected $depots = null;

    /**
     * Extend parent to also clear in-memory cache for depots.
     *
     * @param  mixed        $connection
     * @return BranchPath   provides a fluent interface
     */
    public function setConnection($connection)
    {
        $this->depots = null;
        return parent::setConnection($connection);
    }

    /**
     * Returns true if $value is a valid branch path or a list of valid branch paths.
     *
     * @param   string|array    $value  value or list of values to check for
     * @return  boolean         true if value is valid branch path, false otherwise
     */
    public function isValid($value)
    {
        // normalize to an array and knock out whitespace
        $value = array_filter(array_map('trim', (array) $value), 'strlen');

        // value must contain at least one path
        if (!count($value)) {
            $this->error(self::NO_PATHS);
            return false;
        }

        $depots = $this->getDepots();
        foreach ($value as $path) {
            // check for embedded '...' and '*' (anywhere) in the path;
            // reject them as '*' in path(s) makes it impossible to run p4 dirs,
            // and embedded '...' may cause p4 dirs to be very slow
            if (preg_match('#(\.{3}.|\*)#', $path)) {
                $this->error(self::UNSUPPORTED_WILDCARDS);
                return false;
            }

            // verify that the first path component is an existing depot
            preg_match('#^//([^/]+)#', $path, $match);
            if (!isset($match[1]) || !in_array($match[1], $this->getDepots())) {
                $this->error(self::INVALID_DEPOT);
                return false;
            }

            // check that depot name is followed by something ('//depot' or '//depot/'
            // are not permitted paths)
            if (!preg_match('#^//[^/]+/[^/]+#', $path)) {
                $this->error(self::UNFOLLOWED_DEPOT);
                return false;
            }

            // ensure that the path doesn't end with a slash as such a path is not allowed
            // in client view mappings
            if (substr($path, -1) === '/') {
                $this->error(self::NULL_DIRECTORY);
                return false;
            }
        }

        return true;
    }

    /**
     * Returns list of existing depots in Perforce based on the connection set on this instance.
     * Supports in-memory cache, so 'p4 depots' doesn't run every time this function is called
     * (as long as connection hasn't changed).
     */
    protected function getDepots()
    {
        if ($this->depots === null) {
            $this->depots = array_map('current', $this->getConnection()->run('depots')->getData());
        }

        return $this->depots;
    }
}
