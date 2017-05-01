<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Projects\Model;

use Groups\Model\Group;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Model\Fielded\Iterator;
use P4\OutputHandler\Limit as LimitHandler;
use P4\Spec\Change;
use P4\Spec\Client;
use P4\Spec\Job;
use P4\Spec\Key;
use Projects\Validator\BranchPath as BranchPathValidator;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Users\Model\Config as UserConfig;

class Project extends AbstractKey
{
    const KEY_PREFIX            = 'swarm-project-';

    const FETCH_BY_MEMBER       = 'member';
    const FETCH_COUNT_FOLLOWERS = 'countFollowers';
    const FETCH_INCLUDE_DELETED = 'includeDeleted';
    const FETCH_NO_CACHE        = 'noCache';

    const FORMAT_URL            = 'URL';
    const FORMAT_JSON           = 'JSON';

    protected $needsGroup       = false;
    protected $fields           = array(
        'name'          => array(
            'accessor'  => 'getName',
            'mutator'   => 'setName'
        ),
        'description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'members'       => array(
            'accessor'  => 'getMembers',
            'mutator'   => 'setMembers'
        ),
        'subgroups'     => array(
            'accessor'  => 'getSubgroups',
            'mutator'   => 'setSubgroups',
            'unstored'  => true
        ),
        'owners'        => array(
            'accessor'  => 'getOwners',
            'mutator'   => 'setOwners',
        ),
        'branches'      => array(
            'accessor'  => 'getBranches',
            'mutator'   => 'setBranches'
        ),
        'jobview'       => array(
            'accessor'  => 'getJobview',
            'mutator'   => 'setJobview'
        ),
        'emailFlags'    => array(
            'accessor'  => 'getEmailFlags',
            'mutator'   => 'setEmailFlags'
        ),
        'tests'         => array(
            'accessor'  => 'getTests',
            'mutator'   => 'setTests',
            'hidden'    => true
        ),
        'deploy'        => array(
            'accessor'  => 'getDeploy',
            'mutator'   => 'setDeploy',
            'hidden'    => true
        ),
        'deleted'       => array(
            'accessor'  => 'isDeleted',
            'mutator'   => 'setDeleted'
        )
    );

    /**
     * Verifies if the specified record(s) exists.
     * Its better to call 'fetch' directly in a try block if you will
     * be retrieving the record on success.
     *
     * @param   string|int|array    $id     the entry id or an array of ids to filter
     * @param   Connection          $p4     the connection to use
     * @return  bool|array          true/false for single arg, array of existent ids for array input
     */
    public static function exists($id, Connection $p4)
    {
        // if projects are cached, we can avoid calling fetchAll() that has performance impact due to
        // cloning models and filtering them (which is unnecessary when checking just the existence)
        try {
            $projects = static::getCachedProjects($p4);
        } catch (ServiceNotFoundException $e) {
            $projects = static::fetchAll(array(static::FETCH_BY_IDS => (array) $id), $p4);
        }

        $ids = array();
        foreach ((array) $id as $projectId) {
            $project = isset($projects[$projectId]) ? $projects[$projectId] : null;
            if ($project && !$project->isDeleted()) {
                $ids[] = $projectId;
            }
        }

        return is_array($id) ? $ids : count($ids) != 0;
    }

    /**
     * Extends fetch to use cache if available.
     * Note: Deleted projects are not included in the result.
     *
     * @param   string          $id         the id of the entry to fetch
     * @param   Connection      $p4         a specific connection to use
     * @return  Project                     instance of the requested entry
     * @throws  RecordNotFoundException     if project with the given id doesn't exist
     */
    public static function fetch($id, Connection $p4)
    {
        try {
            $projects = static::getCachedProjects($p4);

            // if we have a cached project, clone it and give it a connection
            if (isset($projects[$id])) {
                $project = clone $projects[$id];
                $project->setConnection($p4);
            }
        } catch (ServiceNotFoundException $e) {
            $project = parent::fetch($id, $p4);
        }

        // if we have a project and it is not deleted, return it
        if (isset($project) && !$project->isDeleted()) {
            return $project;
        }

        throw new RecordNotFoundException("Cannot fetch entry. Id does not exist.");
    }

    /**
     * Extends parent to add additional options (listed below) and to use cache if available.
     * To simplify the code, we support only a subset of options that are available in parent.
     * By default, deleted projects will not be included in the result. To include them,
     * FETCH_INCLUDE_DELETED option with value set to true must be passed in options.
     *
     * @param   array       $options    currently supported options are:
     *                                        FETCH_BY_IDS - provide an array of ids to fetch
     *                                     FETCH_BY_MEMBER - set to limit results to include only projects
     *                                                       having the given member
     *                               FETCH_COUNT_FOLLOWERS - if true, each project will include a 'followers'
     *                                                       flag indicating the number of followers
     *                               FETCH_INCLUDE_DELETED - set to true to also include deleted projects
     *                                      FETCH_NO_CACHE - set to true to avoid using the cache
     * @param   Connection  $p4             the perforce connection to use
     * @return  Iterator                    the list of zero or more matching project objects
     * @throws  \InvalidArgumentException   if the caller passed option(s) we don't support
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        // prepare default values for supported options
        $defaults = array(
            static::FETCH_BY_IDS          => null,
            static::FETCH_BY_MEMBER       => null,
            static::FETCH_COUNT_FOLLOWERS => null,
            static::FETCH_INCLUDE_DELETED => false,
            static::FETCH_NO_CACHE        => null,
        );

        // throw if user passed option(s) we don't support
        $unsupported = array_diff(array_keys($options), array_keys($defaults));
        if (count($unsupported)) {
            throw new \InvalidArgumentException(
                'Following option(s) are not valid for fetching projects: ' . implode(', ', $unsupported) . '.'
            );
        }

        $options += $defaults;

        // fetch all projects, try to get them from cache if available and not explicitly disabled
        if (!$options[static::FETCH_NO_CACHE]) {
            try {
                $cached = static::getCachedProjects($p4);

                // projects from cache need to be cloned and given a connection
                $projects = new Iterator;
                foreach ($cached as $key => $project) {
                    $projects[$key] = clone $project;
                    $projects[$key]->setConnection($p4);
                }

                // we need to take care of FETCH_BY_IDS option that is otherwise handled by parent
                if ($options[static::FETCH_BY_IDS] !== null) {
                    $projects->filter('id', (array) $options[static::FETCH_BY_IDS]);
                }
            } catch (ServiceNotFoundException $e) {
                // not using cache - projects will be populated later
            }
        }

        // get projects from parent if either user requested fetching with no cache or cache is not available
        $projects = isset($projects) ? $projects : parent::fetchAll($options, $p4);

        // unless explicitly requested, filter out deleted projects
        if (!$options[static::FETCH_INCLUDE_DELETED]) {
            $projects->filter('deleted', false);
        }

        // handle FETCH_BY_MEMBER
        $member = $options[static::FETCH_BY_MEMBER];
        if ($member) {
            $projects->filterByCallback(
                function (Project $project) use ($member) {
                    return $project->isMember($member);
                }
            );
        }

        // if caller requested follower counts, add them now.
        if ($options[static::FETCH_COUNT_FOLLOWERS]) {
            $followers = UserConfig::fetchFollowerCounts(
                array(UserConfig::COUNT_BY_TYPE => 'project'),
                $p4
            );

            foreach ($projects as $project) {
                $key   = 'project:' . $project->getId();
                $project->set('followers', isset($followers[$key]) ? $followers[$key]['count'] : 0);
            }
        }

        return $projects;
    }

    /**
     * Attempts to clear cache for projects.
     *
     * @param   Connection  $p4     specific connection to use
     */
    public static function clearCache(Connection $p4)
    {
        try {
            $cache = $p4->getService('cache');
            $cache->invalidateItem('projects');
        } catch (ServiceNotFoundException $e) {
            // no cache - nothing to invalidate
        }
    }

    /**
     * The friendly name for this project.
     *
     * @return  string|null     the name for this project.
     */
    public function getName()
    {
        return $this->getRawValue('name');
    }

    /**
     * Set a friendly name for this project.
     *
     * @param   string|null     $name   the friendly name for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setName($name)
    {
        return $this->setRawValue('name', $name);
    }

    /**
     * The description for this project.
     *
     * @return  string|null     the description for this project.
     */
    public function getDescription()
    {
        return $this->getRawValue('description');
    }

    /**
     * Set a description for this project.
     *
     * @param   string|null     $description    the description for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue('description', $description);
    }

    /**
     * Returns an array of member ids associated with this project.
     *
     * @return  array   ids of all members for this project
     */
    public function getMembers()
    {
        $this->loadGroup();
        return (array) $this->getRawValue('members');
    }

    /**
     * Returns an array of subgroups that are members of this project
     * (i.e. subgroups of the project group).
     *
     * @return  array   ids of all subgroups under this project
     */
    public function getSubgroups()
    {
        $this->loadGroup();
        return (array) $this->getRawValue('subgroups');
    }

    /**
     * Get all members of this project recursively.
     *
     * @param   bool    $flip       if true array keys are the user ids (default is false)
     * @return  array   flat list of all members
     */
    public function getAllMembers($flip = false)
    {
        // only use groups on 2012.1+
        $connection     = $this->getConnection();
        $supportsGroups = $connection->isServerMinVersion('2012.1');
        if (!$supportsGroups) {
            return $flip ? array_flip((array) $this->getRawValue('members')) : (array) $this->getRawValue('members');
        }

        return Group::fetchAllMembers($this->id, $flip, null, null, $connection);
    }

    /**
     * Returns an array of owner ids associated with this project.
     *
     * @return  array   ids of all owners for this project
     */
    public function getOwners()
    {
        return (array) $this->getRawValue('owners');
    }

    /**
     * Returns true if this project has one or more owners and false otherwise.
     *
     * @return  bool    true if project has at least one owner, false otherwise
     */
    public function hasOwners()
    {
        return count($this->getOwners()) > 0;
    }

    /**
     * Set owners for this project.
     *
     * @param   array|null  the owners for this project
     * @return  Project     to maintain a fluent interface
     */
    public function setOwners($owners)
    {
        return $this->setRawValue('owners', $owners);
    }

    /**
     * Get a list of users that follow this project.
     * Members are implicitly followers but are not listed by default.
     *
     * @param   bool|array  $excludeMembers     optional - exclude members (defaults to true)
     *                                          the list of members can be given (useful for performance)
     * @return  array       a list of ids of users that are following this project
     */
    public function getFollowers($excludeMembers = true)
    {
        $followers = UserConfig::fetchFollowerIds(
            $this->getId(),
            'project',
            $this->getConnection()
        );

        // optionally exclude members
        if ($excludeMembers) {
            $members   = is_array($excludeMembers) ? $excludeMembers : $this->getAllMembers();
            $followers = array_diff($followers, $members);
        }

        return $followers;
    }

    /**
     * Set an array of member ids for this project.
     *
     * @param   array|null  $members    an array of members or null
     * @return  Project     to maintain a fluent interface
     */
    public function setMembers($members)
    {
        return $this->setRawValue('members', $members);
    }

    /**
     * Set an array of subgroup ids for this project.
     *
     * @param   array|null  $groups     an array of groups or null
     * @return  Project     to maintain a fluent interface
     * @throws  RuntimeException            if the server is too old to manage groups
     * @throws  InvalidArgumentException    if groups contains project id
     */
    public function setSubgroups($groups)
    {
        if (!$this->canAdminsManageGroups()) {
            throw new \RuntimeException("Cannot set subgroups. Server is too old.");
        }

        if (in_array($this->id, $groups, true)) {
            throw new \InvalidArgumentException("Cannot set project as a subgroup of itself.");
        }

        return $this->setRawValue('subgroups', $groups);
    }

    /**
     * The resulting array with entries for all known branches.
     *
     * This will be an array of arrays. Each sub-array should contain
     * keys for: id, name, paths and moderators.
     *
     * @param   string|null     $sortField  optional - field to sort branches list on (using natural,
     *                                      case-insensitive sort)
     * @param   array           $mainlines  optional - branch names to appear on top of the list when sorted
     * @return  array           the branches for this project
     */
    public function getBranches($sortField = null, array $mainlines = array())
    {
        if (!is_null($sortField) && !is_string($sortField)) {
            throw new \InvalidArgumentException('Invalid $sortField format: $sortField must be a string or null.');
        }

        // normalize the branches array we are about to return.
        // we do this on read as there is the unlikely possibility
        // the data was modified externally.
        $branches = (array) $this->getRawValue('branches');
        foreach ($branches as $id => $branch) {
            $branch += array(
                'id'            => null,
                'name'          => null,
                'paths'         => array(),
                'moderators'    => array()
            );
            $branch['paths'] = array_map('trim', (array) $branch['paths']);
            $branches[$id]   = $branch;
        }

        // sort branches with special handling for mainline branches (will appear first)
        if ($sortField) {
            usort(
                $branches,
                function ($a, $b) use ($sortField, $mainlines) {
                    if (!array_key_exists($sortField, $a) || !array_key_exists($sortField, $b)) {
                        throw new \InvalidArgumentException("Cannot sort branches: branch has no '$sortField' field.");
                    }

                    if (in_array(strtolower($a[$sortField]), $mainlines)) {
                        return -1;
                    } elseif (in_array(strtolower($b[$sortField]), $mainlines)) {
                        return 1;
                    }
                    return strnatcasecmp($a[$sortField], $b[$sortField]);
                }
            );
        }

        return $branches;
    }

    /**
     * Get a particular branch definition.
     *
     * @param   string  $id     the id of the branch definition to get
     * @return  array   the branch definition (id, name and paths)
     * @throws  InvalidArgumentException    if no such branch defined
     */
    public function getBranch($id)
    {
        $branches = $this->getBranches();
        foreach ($branches as $branch) {
            if ($branch['id'] === $id) {
                return $branch;
            }
        }

        throw new \InvalidArgumentException("Cannot get branch '$id'. Branch is not defined.");
    }

    /**
     * Set a branches array for this project.
     *
     * This should be an array of arrays. Each sub-array should contain
     * keys for: id, name, paths and moderators.
     *
     * @param   array|null  $branches   the branches for this project
     * @return  Project     to maintain a fluent interface
     */
    public function setBranches($branches)
    {
        return $this->setRawValue('branches', $branches);
    }

    /**
     * Get list of moderators from given branches of this project.
     *
     * @param   array|null  $branches   optional - limit branches to collect moderators from
     * @return  array       list of moderators for specified branches
     */
    public function getModerators(array $branches = null)
    {
        $moderators = array();
        foreach ($this->getBranches() as $branch) {
            if (is_null($branches) || in_array($branch['id'], $branches)) {
                $moderators = array_merge($moderators, (array) $branch['moderators']);
            }
        }

        return array_values(array_unique($moderators));
    }

    /**
     * The jobview for this project.
     *
     * @return  string|null     the jobview for this project.
     */
    public function getJobview()
    {
        return $this->getRawValue('jobview');
    }

    /**
     * Set a jobview for this project.
     *
     * @param   string|null     $jobview    the jobview for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setJobview($jobview)
    {
        return $this->setRawValue('jobview', $jobview);
    }

    /**
     * Returns an array of email/notification flags set on this project.
     *
     * @return  array   names for all email flags currently set on this project
     */
    public function getEmailFlags()
    {
        return (array) $this->getRawValue('emailFlags');
    }

    /**
     * Returns the value of the specified email flag, if it exists, or null if it could not be found.
     *
     * @param   string      $flag   specific email flag we are looking for
     * @return  mixed|null  value of the flag if found, or null if the flag was not found
     */
    public function getEmailFlag($flag)
    {
        $emailFlags = $this->getEmailFlags();
        return isset($emailFlags[$flag]) ? $emailFlags[$flag] : null;
    }

    /**
     * Set an array of active email/notification flags on this comment.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  Project     to maintain a fluent interface
     */
    public function setEmailFlags($flags)
    {
        return $this->setRawValue('emailFlags', (array)$flags);
    }

    /**
     * An array containing the keys 'enabled' and 'url'
     * to reflect the test settings.
     *
     * @param   string|null     $key    optional - a specific key to retreive
     * @return  array|null      an array with keys for enabled and url.
     */
    public function getTests($key = null)
    {
        $values = (array) $this->getRawValue('tests') + array('enabled' => false, 'url' => null);

        // handle 2015.4 to 2016.1 upgrade on the fly
        // - renamed 'postParams' key to 'postBody'
        // - renamed 'postFormat' value 'GET' to 'URL'
        if (array_key_exists('postParams', $values) && !array_key_exists('postBody', $values)) {
            $values['postBody'] = $values['postParams'];
            unset($values['postParams']);
        }
        if (array_key_exists('postFormat', $values) && $values['postFormat'] === 'GET') {
            $values['postFormat'] = static::FORMAT_URL;
        }

        if ($key) {
            return isset($values[$key]) ? $values[$key] : null;
        }

        return $values;
    }

    /**
     * Set tests enabled and url properties.
     *
     * @param   array|null  $tests  array with keys for enabled and url or null
     * @return  Project     to maintain a fluent interface
     */
    public function setTests($tests)
    {
        return $this->setRawValue('tests', $tests);
    }

    /**
     * An array containing the keys 'enabled' and 'url'
     * to reflect the deployment settings.
     *
     * @param   string|null     $key    optional - a specific key to retrieve
     * @return  array|null      an array with keys for enabled and url.
     */
    public function getDeploy($key = null)
    {
        $values = (array) $this->getRawValue('deploy') + array('enabled' => false, 'url' => null);

        if ($key) {
            return isset($values[$key]) ? $values[$key] : null;
        }

        return $values;
    }

    /**
     * Set deployment enabled and url properties.
     *
     * @param   array|null  $deploy     array with keys for enabled and url or null
     * @return  Project     to maintain a fluent interface
     */
    public function setDeploy($deploy)
    {
        return $this->setRawValue('deploy', $deploy);
    }

    /**
     * Boolean value indicating whether this project is deleted.
     *
     * There might be records missing this field (it has been added later).
     * We convert missing values to false indicating the project is considered to
     * be deleted only if the 'deleted' field is present and its value is true.
     *
     * @return  boolean     true if this projects is deleted, false otherwise
     */
    public function isDeleted()
    {
        return (bool) $this->getRawValue('deleted');
    }

    /**
     * Set whether this project is deleted (true) or not (false).
     *
     * @param   boolean     $deleted    pass true to indicate that this project is deleted
     *                                  and false to indicate that this project is active
     * @return  Project     to maintain a fluent interface
     */
    public function setDeleted($deleted)
    {
        return $this->setRawValue('deleted', (bool) $deleted);
    }

    /**
     * Return client specific for this project.
     * Each branch of this project is mapped as top-level folder in client's view:
     *
     *  <branch-path> //<client-id>/<branch-id>/...
     *
     * At the moment, branches with multiple paths are mapped to the same folder.
     * Client will be created if does't exist.
     *
     * @return string   name of the client specific to this project
     */
    public function getClient()
    {
        $p4     = $this->getConnection();
        $client = 'swarm-project-' . $this->getId();

        // prepare view mappings based on the project's branches
        $view       = array();
        $branchPath = new BranchPathValidator(array('connection' => $p4));
        foreach ($this->getBranches() as $branch) {
            $id = $branch['id'];
            foreach ($branch['paths'] as $path) {
                $path   = trim($path, '"\'');
                $suffix = basename($path);

                // add path to the view if it is a valid branch path
                // note: we use plus/overlay mappings to merge multiple paths together
                if ($branchPath->isValid($path)) {
                    $view[] = '"+' . $path . '" "//' . $client . '/' . $id . '/' . $suffix . '"';
                }
            }
        }

        // normalize and verify the client view spec
        $data = $p4->run('client', array('-o', $client))->expandSequences()->getData(-1);
        $old  = new Client;
        $old->setView((array) $data['View']);
        $new  = new Client;
        $new->setView($view);

        if ($old->getView() != $new->getView() || !isset($data['Update'])) {
            $p4->run(
                'client',
                '-i',
                array(
                    'Host' => '',
                    'View' => $view,
                    'Root' => DATA_PATH . '/tmp'
                ) + $data
            );
        }

        return $client;
    }

    /**
     * Determine which projects are affected by the given job.
     *
     * @param   Job         $job        the job to examine
     * @param   Connection  $p4         the perforce connection to use
     * @return  array       a list of affected projects as values (auto-incrementing keys).
     */
    public static function getAffectedByJob(Job $job, Connection $p4)
    {
        // loop over projects and, for those with a valid job view,
        // see which are impacted by the passed job.
        $projects = static::fetchAll(array(), $p4);
        $affected = array();
        foreach ($projects as $project) {
            // extract the job view and break out the various key=value filter(s) on whitespace
            // we generate a conditions array with field ids as keys and a regex pattern as value
            $matched = false;
            $jobview = trim($project->getJobview());
            $filters = preg_split('/\s+/', $jobview);
            $fields  = array_combine(
                array_map('strtolower', $job->getFields()),
                $job->getFields()
            );
            foreach ($filters as $filter) {
                if (!preg_match('/^([^=()|]+)=([^=()|]+)$/', $filter, $matches)) {
                    continue;
                }

                // we escape the pattern but re-activate originally un-escaped '*'
                // characters as being wildcard matches
                list(, $field, $pattern) = $matches;
                $field   = strtolower($field);
                $pattern = '/^' . preg_quote($pattern, '/') . '$/i';
                $pattern = preg_replace('/(^|[^\\\\])\\\\\*/', '$1.*', $pattern);

                // if the job lacks the requested field or pattern doesn't match; skip this project
                // we use the 'fields' array to do a case insensitive lookup of the field name
                if (!isset($fields[$field]) || !preg_match($pattern, $job->get($fields[$field]))) {
                    continue 2;
                }

                $matched = true;
            }

            // only include the project if it matched at least one expression. we don't
            // want projects that lack a job view, or contain only invalid views, to hit.
            if ($matched) {
                $affected[] = $project->getId();
            }
        }

        return $affected;
    }

    /**
     * Determine which projects (and branches) are affected by the given change.
     *
     * @param   Change      $change     the change to examine
     * @param   Connection  $p4         the perforce connection to use
     * @return  array       a list of affected projects as keys with a list of affected
     *                      branches under those keys (as the value)
     */
    public static function getAffectedByChange(Change $change, Connection $p4)
    {
        // get the list of projects and their branches
        $projects = static::fetchAll(array(), $p4);

        // make a flat list of maps for all branches in all projects.
        $maps = array();
        foreach ($projects as $project) {
            foreach ($project->get('branches') as $branch) {
                // ensure all paths are quoted. based on experiments with p4 client,
                // the \ char is literal and it can't contain double-quotes. As such
                // the simple approach of quote wrapping if it isn't already works.
                $paths  = array();
                foreach ((array) $branch['paths'] as $path) {
                    if ($path[0] != '"' || substr($path, -1) != '"') {
                        $path = '"' . $path . '"';
                    }
                    $paths[] = $path;
                }

                $maps[] = array(
                    'project'   => $project->getId(),
                    'branch'    => $branch['id'],
                    'map'       => new \P4_Map($paths)
                );
            }
        }

        // if we can cheaply query the common path (won't work on shelved changes)
        // remove any projects/branches which clearly won't be affected
        $path = $change->isSubmitted() ? $change->getPath(false) : null;
        if ($path) {
            $pathMap = new \P4_Map((array) $path);
            foreach ($maps as $key => $map) {
                // if the project/branch potentially maps this change, keep it
                if ($map['map']->includes($path)) {
                    continue;
                }

                // if the change potentially maps this project/branch, keep it
                foreach ((array) $map['map']->lhs() as $branchPath) {
                    if ($pathMap->includes($branchPath)) {
                        continue 2;
                    }
                }

                // looks like these don't overlap, nuke it
                unset($maps[$key]);
            }
        }

        // if no projects/branches are candidates simply exit out at this point
        if (!$maps) {
            return array();
        }

        // stream/loop over files in change
        $affected = array();
        $inHeader = true;
        $handler  = new LimitHandler;
        $handler->setOutputCallback(
            function ($data) use (&$inHeader, &$maps, &$affected) {
                // skip empty data blocks
                $data = trim($data, "\r\n");
                if (!strlen($data)) {
                    return LimitHandler::HANDLER_HANDLED;
                }

                // if we are in the header check to see if we have hit the start
                // of the file list and return that we took care of this block.
                if ($inHeader) {
                    if ($data === "Affected files ..." || $data === "Shelved files ...") {
                        $inHeader = false;
                    }
                    return LimitHandler::HANDLER_HANDLED;
                }

                // if we have run out of maps just stop the command
                // we do this on the block after we run out to avoid
                // cancelling if no more data was going to come over.
                if (empty($maps)) {
                    return LimitHandler::HANDLER_HANDLED | LimitHandler::HANDLER_CANCEL;
                }

                // remove the trailing '#rev action' text and see which
                // project/branch maps this file fits under (if any)
                $file = substr($data, 0, strrpos($data, '#'));
                foreach ($maps as $key => $map) {
                    // if the map includes this file add the project/branch to affected and
                    // remove this map so we don't waste time on future files checking it
                    if ($map['map']->includes($file)) {
                        $project   = $map['project'];
                        $branch    = $map['branch'];
                        $affected += array($project => array());
                        $affected[$project][] = $branch;

                        unset($maps[$key]);
                    }
                }

                return LimitHandler::HANDLER_HANDLED;
            }
        );
        $p4->runHandler($handler, 'describe', array('-Ss', $change->getId()), null, false);

        return $affected;
    }

    /**
     * Extends parent to also save the group if server allows admins to do so.
     *
     * @return  Project     to maintain a fluent interface
     */
    public function save()
    {
        // if admins can manage groups, mark the members field as not being
        // directly stored on the key to avoid data duplication.
        $this->fields['members']['unstored'] = $this->canAdminsManageGroups();

        // if the server is too old for admins to manage groups or the 'members'
        // and 'subgroups' fields have not been populated, we're done
        if (!$this->canAdminsManageGroups()
            || (!array_key_exists('members', $this->values) && !array_key_exists('subgroups', $this->values))
        ) {
            parent::save();

            // clear cache and return
            static::clearCache($this->getConnection());
            return $this;
        }

        // attempt to fetch any existing group with this projects raw id
        $group = false;
        $isAdd = false;
        try {
            $group = Group::fetch($this->id, $this->getConnection());
        } catch (\P4\Spec\Exception\NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // early exit if member and subgroups lists haven't changed
        if ($group
            && $group->getUsers() == $this->getRawValue('members')
            && $group->getSubgroups() == $this->getRawValue('subgroups')
        ) {
            parent::save();

            // clear cache and return
            static::clearCache($this->getConnection());
            return $this;
        }

        // if the fetch failed, setup a new group as its an add
        if (!$group) {
            $isAdd = true;
            $group = new Group($this->getConnection());
            $group->setId($this->id);
            $group->addOwner($this->getConnection()->getUser());
        }

        // ensure the group has the new list of members and subgroups
        $group->setUsers((array) $this->getRawValue('members'));
        $group->setSubgroups((array) $this->getRawValue('subgroups'));

        // if this is an edit and we're an owner pass editAsOwner = true to allow admin access
        // if this is an add and we're not a super user pass addAsAdmin = true to improve our chances
        $group->save(
            !$isAdd && in_array($this->getConnection()->getUser(), $group->getOwners()),
            $isAdd  && !$this->getConnection()->isSuperUser()
        );

        // invalidate group cache because we know groups have changed.
        // @todo    this will happen again due to the group trigger (which is inefficient)
        //          we do it here anyway because the trigger can take a moment to propagate
        //          and we don't want the user to see stale data.
        try {
            $cache = $this->getConnection()->getService('cache');
            $cache->invalidateItem('groups');
        } catch (ServiceNotFoundException $e) {
            // no cache? nothing to invalidate.
        }

        parent::save();

        // clear cache and return
        static::clearCache($this->getConnection());
        return $this;
    }

    /**
     * Tests whether the given user is a member of this project.
     *
     * @param   string  $userId     ID of the user we are checking membership
     * @return  bool    whether or not the user is a member of this project
     */
    public function isMember($userId)
    {
        if (!$userId) {
            return false;
        }

        $members = $this->getAllMembers(true);
        return isset($members[$userId]);
    }

    /**
     * Return true if groups are supported, false otherwise.
     */
    public function canAdminsManageGroups()
    {
        return $this->getConnection()->isServerMinVersion('2012.1');
    }

    /**
     * Fetch project group to populate members and subgroups if needed.
     *
     * On 2012.1+ p4d instances members and subgroups are stored in a group
     * with the id swarm-project-<projectId>. On servers older than 2012.1
     * admin users are unable to manage groups so members are stored in the
     * project key and subgroups are not supported.
     */
    protected function loadGroup()
    {
        $setMembers   = !array_key_exists('members',   $this->values) && $this->needsGroup;
        $setSubgroups = !array_key_exists('subgroups', $this->values) && $this->needsGroup;

        if (!$setMembers && !$setSubgroups) {
            return;
        }

        $members   = array();
        $subgroups = array();
        try {
            $group     = Group::fetch($this->id, $this->getConnection());
            $members   = $group->getUsers();
            $subgroups = $group->getSubgroups();
        } catch (\P4\Spec\Exception\NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        $this->setRawValue('members',   $setMembers   ? $members   : $this->getRawValue('members'));
        $this->setRawValue('subgroups', $setSubgroups ? $subgroups : $this->getRawValue('subgroups'));
        $this->needsGroup = false;
    }

    /**
     * Get projects from the cache. If the cache is empty, populate it.
     *
     * @param   Connection  $p4     specific connection to use
     * @return  Iterator            cached project records
     */
    protected static function getCachedProjects(Connection $p4)
    {
        // get models from cache if available
        $cache    = $p4->getService('cache');
        $projects = $cache->getItem('projects');

        // if cache is empty, build it
        if ($projects === null) {
            $projects = static::fetchAll(
                array(
                    static::FETCH_NO_CACHE        => true,
                    static::FETCH_INCLUDE_DELETED => true
                ),
                $p4
            );
            $cache->setItem('projects', $projects);
        }

        return $projects;
    }

    /**
     * Extends parent to flag models as requiring a group populate if
     * the server version is new enough to make it possible for admins.
     *
     * @param   Key     $key    the key to 'record'ize
     * @return  AbstractKey     the record based on the passed key's data
     */
    protected static function keyToModel($key)
    {
        $model = parent::keyToModel($key);
        $model->needsGroup = $model->canAdminsManageGroups();

        return $model;
    }
}
