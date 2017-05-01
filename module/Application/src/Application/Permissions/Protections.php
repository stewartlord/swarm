<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Permissions;

class Protections
{
    const MODE_LIST            = 'list';
    const MODE_READ            = 'read';
    const MODE_OPEN            = 'open';
    const MODE_WRITE           = 'write';
    const MODE_ADMIN           = 'admin';
    const MODE_SUPER           = 'super';

    protected $protections     = array();
    protected $enabled         = true;
    protected $isCaseSensitive = true;
    protected $cache           = array();

    /**
     * Enable/disable protections.
     *
     * @param   boolean         $enabled    set to true/false to enable/disable protections
     * @return  Protections     provides fluent interface
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /**
     * Set protections on this instance.
     *
     * @param   array       $protections        protections to set, each protection must be an array
     *                                          with at least 'perm' and 'depotFile' keys set
     * @param   bool        $isCaseSensitive    optional, if true (default), then protections will be
     *                                          treated as case sensitive (assuming this code is executed
     *                                          on case sensitive machine), otherwise case in-sensitive
     * @return  Protections                     provides fluent interface
     */
    public function setProtections(array $protections = null, $isCaseSensitive = true)
    {
        // ensure the protections passed to this method have the correct structure:
        // in general, each protection is assumed to have the same structure as output from p4 protects
        // (that contains 'line', 'perm', 'user', 'host', 'depotFile' and optionally 'unmap'), but since
        // this class depends only on 'perm' and 'depotFile', we only require presence of these
        $protections = (array) $protections;
        foreach ($protections as $key => $protection) {
            if (!is_array($protection) || !isset($protection['perm']) || !isset($protection['depotFile'])) {
                throw new \InvalidArgumentException(
                    "Invalid protections: each protection must be an array with 'perm' and 'depotFile' keys set."
                );
            }

            // be 'helpful' and strip balanced double-quotes - but complain for any other double-quotes
            $protections[$key]['depotFile'] = preg_replace('/^"(.*)"$/', '$1', $protection['depotFile']);
            if (strpos($protection['depotFile'], '"') !== false) {
                throw new \InvalidArgumentException(
                    "Invalid protections: invalid double-quote(s) detected in depotFile."
                );
            }
        }

        // input is valid, set the protections, case sensitivity flag and reset the cache
        $this->protections     = $protections;
        $this->isCaseSensitive = (bool) $isCaseSensitive;
        $this->cache           = array();

        return $this;
    }

    /**
     * Filter given paths against the protections set on this class with respect to a given permission mode.
     * The mode influences which protection rules will be applied - only protections with the same or weaker
     * mode will be accounted for.
     *
     * @param   string|array        $paths          path or list of paths to filter
     * @param   string              $mode           permission mode to filter against
     *                                              (one of list, read, write etc.)
     * @param   string|callable     $pluck          optional - pluck to assist with paths conversion before
     *                                              filtering, we support 2 formats:
     *                                               function - function to convert a path value before filtering,
     *                                                          assuming it takes one parameter and returns a string
     *                                                 string - key to get path value if path is an array
     * @return  string|array|false                  list of $paths (in the same format as input) comply with
     *                                              protections defined by this class or false if single path on input
     *                                              doesn't pass this filter
     * @throws  \InvalidArgumentException           if $mode is not a supported permission mode or $pluck has invalid
     *                                              format
     */
    public function filterPaths($paths, $mode, $pluck = null)
    {
        // do nothing if protections are disabled
        if (!$this->enabled) {
            return $paths;
        }

        // check if the passed mode is supported
        if (!in_array($mode, $this->getModes())) {
            throw new \InvalidArgumentException("Unsupported permission mode: $mode.");
        }

        // pluck must be either a callable function or a string
        if ($pluck !== null && !is_string($pluck) && !is_callable($pluck)) {
            throw new \InvalidArgumentException('Pluck must be either string or a callable function.');
        }

        // remember the paths input scheme
        $singular = is_string($paths);

        // get the protections mappings for the given mode
        $map = $this->getProtectionsMap($mode);

        // filter paths to remove those not included in the map
        $filtered = array();
        $paths    = $singular ? array($paths) : $paths;
        foreach ($paths as $key => $path) {
            // if pluck was specified, use it to get the value
            if (is_callable($pluck)) {
                $path = $pluck($path);
            } elseif (is_string($pluck) && is_array($path)) {
                $path = isset($path[$pluck]) ? $path[$pluck] : $path;
            }

            // throw if path value is not a string at this point as it would blow right after
            if (!is_string($path)) {
                throw new \InvalidArgumentException('Invalid paths format, cannot get depot path.');
            }

            // check whether the path is included in the map:
            // normally, we could simply ask the map whether the path is included
            // via P4_Map::includes(), however it won't work for checking LIST
            // access on directories as inclusionary paths also imply 'list' access
            // to their parents
            // so instead, we test via joining protections map with map containing
            // only the tested path and check whether the resulting map contains
            // any (non-exclusionary) paths
            // this will work when testing directories or files, but for directories
            // we need to append '...' at the end of the path.
            //
            // Note: the first call to P4_Map::join() migh be slow if the map size
            // is big, but all the following calls will be fast (as long as the map
            // doesn't change) as the map is internally tracking whether its ambiguous
            // (before the first call) or not (after the first call)

            // remove wrapping quotes (we add them back later)
            // and append '...' if testing list access to dirs
            $path = trim($path, '"');
            if ($mode === static::MODE_LIST && substr($path, -1) === '/') {
                $path .= '...';
            }

            // convert path to lowercase if in case in-sensitive mode
            $path = $this->isCaseSensitive ? $path : $this->stringToLower($path);

            $join = \P4_Map::join($map, new \P4_Map(array('"' . $path . '"')));
            foreach ($join->as_array() as $entry) {
                // path is included if join contains at least one non-exclusionary entry
                $entry = trim($entry, '"');
                if (substr($entry, 0, 1) !== '-') {
                    continue 2;
                }
            };

            // making it thus far means that tested path is not included
            unset($paths[$key]);
        }

        return $singular ? (isset($paths[0]) ? $paths[0] : false) : $paths;
    }

    /**
     * Limit the given view by merging in protections from this instance.
     *
     * @param   array   $view   list of view mapping (each entry must have 'depot' and 'client' keys)
     * @return  array           updated view mapping to include restrictions given by protections of this instance
     */
    public function limitView(array $view)
    {
        // do nothing if protections are disabled
        if (!$this->enabled) {
            return $view;
        }

        // create a map from the given view
        $viewMap = new \P4_Map;
        foreach ($view as $entry) {
            if (!is_array($entry) || !isset($entry['depot']) || !isset($entry['client'])) {
                throw new \InvalidArgumentException(
                    "Invalid view: each entry must be an array with 'depot' and 'view' keys set."
                );
            }

            $viewMap->insert('"' . $entry['depot'] . '" "' . $entry['client'] . '"');
        }

        // resulting view map is simply protections map joined with the view map
        $map = \P4_Map::join(
            $this->getProtectionsMap(static::MODE_READ),
            $viewMap
        );

        return array_map(
            function ($depot, $client) {
                return array('depot' => $depot, 'client' => $client);
            },
            $map->lhs(),
            $map->rhs()
        );
    }

    /**
     * Return a mapping of path patterns given by the protections for the given mode.
     * Supports in-memory cache based on a given mode.
     *
     * @param   string      $mode           permission mode
     * @return  \P4_Map     mapping of path pattens given by the the protections for the given mode.
     */
    public function getProtectionsMap($mode)
    {
        if (!isset($this->cache[$mode])) {
            // prepare list of supported permission modes as [mode] => [rank] where rank
            // is an integer indicating its strength (i.e. stronger permission => higher rank)
            $modesRanks = array_flip($this->getModes());

            // prepare the map that includes all lines that apply to the given mode
            $map      = new \P4_Map;
            $modeRank = $modesRanks[$mode];
            foreach ($this->protections as $line) {
                $protectionMode = $line['perm'];
                $isExclusion    = isset($line['unmap']);
                $depotFile      = $this->isCaseSensitive
                    ? $line['depotFile']
                    : $this->stringToLower($line['depotFile']);

                // check if protection mode is a right (prefixed by '=') or a permission level
                $isRight        = substr($protectionMode, 0, 1) === '=';
                $protectionMode = $isRight ? substr($protectionMode, 1) : $protectionMode;

                // skip if protection mode is not contained in our supported modes
                if (!isset($modesRanks[$protectionMode])) {
                    continue;
                }

                // depending on the protection mode, insert the path pattern into the map:
                // if the protection mode is a right, it must be the same as mode
                // if the protection mode is a permission level, it must be:
                //  - same or stronger than mode if we are granting access
                //  - any if we are denying (even if only one level is specified)
                $protectionModeRank = $modesRanks[$protectionMode];
                if (($isRight && $modeRank === $protectionModeRank)
                    || (!$isRight && ($isExclusion || $modeRank <= $protectionModeRank))
                ) {
                    // add mapping - we can't use shorter $map->insert($depotFile) or $map->insert('-$depotFile')
                    // because it will break when $depotFile path contains spaces
                    // we can't use simpler syntax $map->insert($depotFile, $depotFile) either because that will
                    // remove some characters like dashes from the path
                    // to avoid both of these issues, we use syntax with one argument (must be quoted) representing
                    // both sides
                    $map->insert('"' . ($isExclusion ? '-' : '') . $depotFile . '" "' . $depotFile . '"');
                }
            }

            $this->cache[$mode] = $map;
        }

        return $this->cache[$mode];
    }

    /**
     * Return list of supported protection modes, ordered by their strength
     * (i.e. higher mode includes all weaker ones).
     *
     * @return  array   list of supported protection modes
     */
    protected function getModes()
    {
        return array(
            static::MODE_LIST,
            static::MODE_READ,
            static::MODE_OPEN,
            static::MODE_WRITE,
            static::MODE_ADMIN,
            static::MODE_SUPER
        );
    }

    /**
     * Convert input string to lower case. Use multibyte conversion if available,
     * otherwise strtolower().
     *
     * @param   string  $string     string to lowercase
     * @return  string  input converted to lowercase
     */
    protected function stringToLower($string)
    {
        $string = (string) $string;
        return function_exists('mb_strtolower')
            ? mb_strtolower($string, 'UTF-8')
            : strtolower($string);
    }
}
