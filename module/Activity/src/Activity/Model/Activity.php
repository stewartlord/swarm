<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Activity\Model;

use Application\Escaper\Escaper;
use P4\Key\Key;
use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator as FieldedIterator;
use Projects\Filter\ProjectList as ProjectListFilter;
use Projects\Model\Project;
use Record\Key\AbstractKey as KeyRecord;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zend\View\Helper\AbstractHelper;

/**
 * Provides persistent storage and indexing of activity entries.
 */
class Activity extends KeyRecord
{
    const   KEY_PREFIX      = 'swarm-activity-';
    const   KEY_COUNT       = 'swarm-activity:count';

    const   FETCH_BY_STREAM = 'streams';
    const   FETCH_BY_TYPE   = 'type';
    const   FETCH_BY_CHANGE = 'change';

    public $fields       = array(
        'type'          => array(       // type of activity
            'index'     => 1001
        ),
        'link',                         // string url or array to build url to target
        'user',                         // id of user that performed action
        'action',                       // past tense action (e.g. committed)
        'target',                       // label for target of action (e.g. change 12345)
        'preposition'   => array(       // relationship to project(s) (e.g. for swarm:main)
            'default'   => 'for'
        ),
        'description',                  // description of object or activity
        'details'       => array(       // additional adhoc information about activity
            'accessor'  => 'getDetails',
            'mutator'   => 'setDetails'
        ),
        'topic',                        // topic for comments
        'depotFile',                    // depot filename for activity events related to files
        'time',                         // time of activity
        'behalfOf',                     // null or string. if set, the activity 'user' carried out the action as a
                                        // representative of the behalfOf user
        'projects'      => array(       // an array with project id's as keys and branches as values
            'accessor'  => 'getProjects',
            'mutator'   => 'setProjects'
        ),
        'followers'     => array(       // list of individuals following/participating/interested
            'accessor'  => 'getFollowers',
            'mutator'   => 'setFollowers',
            'unstored'  => true
        ),
        'streams'       => array(       // list of streams this should appear on
            'accessor'  => 'getStreams',
            'mutator'   => 'setStreams',
            'index'     => 1002
        ),
        'change'        => array(
            'index'     => 1003
        )
    );

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by stream or type.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  supported options are:
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                     FETCH_AFTER - set to an id _after_ which we start collecting
     *                                    FETCH_BY_IDS - provide an array of ids to fetch.
     *                                                   not compatible with FETCH_SEARCH or FETCH_AFTER.
     *                                 FETCH_BY_STREAM - set to a stream to limit results (e.g. 'user-joe')
     *                                   FETCH_BY_TYPE - set to a type to limit results (e.g. 'change')
     *                                 FETCH_BY_CHANGE - set to a change id to limit results (e.g. '123')
     * @param   Connection  $p4             the perforce connection to run on
     * @return  FieldedIterator         the list of zero or more matching activity objects
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        // normalize options
        $options += array(
            static::FETCH_BY_STREAM  => null,
            static::FETCH_BY_TYPE    => null,
            static::FETCH_BY_CHANGE  => null
        );

        // build a search expression for type and/or stream.
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            array(
                'type'    => $options[static::FETCH_BY_TYPE],
                'streams' => $options[static::FETCH_BY_STREAM],
                'change'  => $options[static::FETCH_BY_CHANGE]
            )
        );

        return parent::fetchAll($options, $p4);
    }

    /**
     * Set the projects (and their associated branches) that are impacted by this event.
     * @see ProjectListFilter for details on input format.
     *
     * @param   array|string    $projects   the projects to associate with this activity.
     * @return  Activity        provides fluent interface
     * @throws  \InvalidArgumentException   if input is not correctly formatted.
     */
    public function setProjects($projects)
    {
        $filter = new ProjectListFilter;
        return $this->setRawValue('projects', $filter->filter($projects));
    }

    /**
     * Add one or more projects (and optionally associated branches)
     *
     * @param   string|array    $projects   one or more projects
     * @return  Activity        provides fluent interface
     */
    public function addProjects($projects)
    {
        $filter = new ProjectListFilter;
        return $this->setRawValue('projects', $filter->merge($this->getRawValue('projects'), $projects));
    }

    /**
     * Get the projects this activity record is associated with.
     * Each entry in the resulting array will have the project id as the key and
     * an array of zero or more branches as the value. An empty branch array is
     * intended to indicate the project is affected but not a specific branch.
     *
     * @return  array   the projects set on this record.
     */
    public function getProjects()
    {
        $projects = (array) $this->getRawValue('projects');

        // remove deleted projects
        foreach ($projects as $project => $branches) {
            if (!Project::exists($project, $this->getConnection())) {
                unset($projects[$project]);
            }
        }

        return $projects;
    }

    /**
     * Get the followers for this activity record. Anyone who is participating
     * in the affected object, following it or otherwise involved should be listed.
     * This field isn't stored its simply used to derive additional streams from.
     *
     * @return  array   the followers set on this activity.
     */
    public function getFollowers()
    {
        return (array) $this->getRawValue('followers');
    }

    /**
     * Set the followers for this activity record.
     *
     * @param   array|string    $followers  the follers for this record.
     * @return  Activity        provides fluent interface
     */
    public function setFollowers($followers)
    {
        return $this->setRawValue('followers', array_unique((array) $followers));
    }

    /**
     * Add one or more followers to this activity record.
     *
     * @param   string|array    $followers  one or more new followers
     * @return  Activity        provides fluent interface
     */
    public function addFollowers($followers)
    {
        return $this->setFollowers(array_merge($this->getFollowers(), (array) $followers));
    }

    /**
     * Set the streams this activity record should be shown on.
     *
     * @param   array|string    $streams    the stream names (e.g. user-joe, project-swarm)
     * @return  Activity        provides fluent interface
     */
    public function setStreams($streams)
    {
        return $this->setRawValue('streams', array_values(array_unique((array) $streams)));
    }

    /**
     * Get the streams this activity record should be shown on.
     *
     * @return  array   the streams set on this record.
     */
    public function getStreams()
    {
        return $this->getRawValue('streams');
    }

    /**
     * Add a stream that this event should appear on.
     *
     * @param   string  $name   the stream name (e.g. user-joe, project-swarm)
     * @return  Activity        provides fluent interface
     */
    public function addStream($name)
    {
        $streams   = $this->getStreams();
        $streams[] = $name;

        return $this->setStreams($streams);
    }

    /**
     * Get additional information about this activity.
     *
     * The model itself knows nothing about this field, aside from the
     * fact that it is an array. Third-parties are expected to contribute
     * to it for their own purposes.
     *
     * @param   mixed   a specific key to retrieve, returns null if not found
     * @return  array   additional information about the activity
     */
    public function getDetails($key = null)
    {
        $details = (array) $this->getRawValue('details');
        if ($key) {
            return isset($details[$key]) ? $details[$key] : null;
        }
        return $details;
    }

    /**
     * Store additional information about this activity.
     *
     * As above, there are no expectations about this information aside
     * from it being an array. Use it for whatever, but beware that too
     * much data could impact performance. Keep it short and sweet.
     * Note, empty values will be stripped.
     *
     * @param   array|null  $details    information to store about this activity
     * @return  KeyRecord   to maintain a fluent interface
     */
    public function setDetails($details)
    {
        return $this->setRawValue('details', array_filter((array) $details));
    }

    /**
     * Get a url to the target of this activity.
     * The link field can be a string value indicating a literal url,
     * or an array with two elements: route name and route params.
     * If the route params has a 'fragment' element, it will be used as the fragment.
     *
     * @param   object  $urlHelper  the url helper to use
     * @return  string|null         a url for the target, null if no valid link is set.
     * @throws  \InvalidArgumentException   if the url helper is invalid
     */
    public function getUrl($urlHelper)
    {
        // url helper must be a view helper or controller plugin.
        if (!$urlHelper instanceof AbstractPlugin && !$urlHelper instanceof AbstractHelper) {
            throw new \InvalidArgumentException(
                "Url helper must be a controller plugin or view helper."
            );
        }

        $link = $this->get('link');
        if (is_string($link) && strlen($link)) {
            return $link;
        }

        // validate link value as a hash
        if (!is_array($link)
            || !isset($link[0], $link[1])
            || !is_string($link[0])
            || !is_array($link[1])
        ) {
            return null;
        }

        try {
            // pull out, and escape, the fragment if one is set
            $hash = '';
            if (isset($link[1]['fragment']) && $link[1]['fragment']) {
                // escape full url is a bit more forgiving and should be
                // ok in the fragment section; we just want to avoid XSS
                $escaper = new Escaper;
                $hash    = '#' . $escaper->escapeFullUrl($link[1]['fragment']);
            }

            return $urlHelper instanceof AbstractPlugin
                ? $urlHelper->fromRoute($link[0], $link[1]) . $hash
                : $urlHelper($link[0], $link[1]) . $hash;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extends parent to set time to now if none was specified.
     *
     * @return  Activity    to maintain a fluent interface
     */
    public function save()
    {
        // if no time is already set, use now as a default
        $this->set('time', $this->get('time') ?: time());

        return parent::save();
    }

    /**
     * Breaks out the case of fetching everything sans 'p4 search' filters.
     *
     * In some cases we want to use parent's method of fetching via p4 keys
     * with an output handler. In other cases where we think it will involve
     * a small enough number of commands, we fetch each key individually.
     *
     * @param   array           $options    a normalized array of filters
     * @param   Connection      $p4         the perforce connection to run on
     * @return  FieldedIterator the list of zero or more matching activity objects
     */
    protected static function fetchAllNoSearch(array $options, Connection $p4)
    {
        // pull out options for easy access
        // note we decode the 'after' option to make it easier to work with
        $max   = $options[static::FETCH_MAXIMUM];
        $after = static::decodeId($options[static::FETCH_AFTER]);

        // if we have no 'after' and no 'max' parent's approach of running
        // p4 counters will always be faster. if we have a max and the
        // server is new enough to support -m that will also be best.
        if (!$after && (!$max || $p4->isServerMinVersion('2013.1'))) {
            return parent::fetchAllNoSearch($options, $p4);
        }

        // determine the current count so we know our bounds
        try {
            $count = Key::fetch(static::KEY_COUNT, $p4)->get();
        } catch (\P4\Exception $e) {
            // no count exists, means we have no activity
            $count = 0;
        }

        // if we have no records or the specified 'after' id is outside
        // the range of possible id's simply return an empty result.
        if (!$count || ($after && ($after > $count || $after <= 1))) {
            return new FieldedIterator;
        }

        // if we think it will take more than 100 commands to fetch the
        // entries individually, just let parent do it (should be faster)
        // to determine how many, we calculate the starting record (as
        // per after and count), then take the lesser of start and max.
        // note 2013.1+ servers support multi-fetch which reduces the
        // number of commands required substantially.
        $start    = ($after && $after <= $count) ? ($after - 1) : $count;
        $commands = $max ? min($start, $max) : $start;
        $commands = $commands / ($p4->isServerMinVersion('2013.1') ? $p4->getOptionLimit() : 1);
        if ($commands > 100) {
            return parent::fetchAllNoSearch($options, $p4);
        }

        // determine the last id so we can generate the range.
        // just to be defensive we ensure this is at least 1.
        $stop = $max ? $start - $max + 1 : 1;
        $stop = $stop >= 1 ? $stop : 1;
        $ids  = $start >= $stop ? range($start, $stop) : array();

        return parent::fetchAllNoSearch(array(Key::FETCH_BY_IDS => $ids), $p4);
    }

    /**
     * Extends parent to flip the ids ordering and hex encode.
     *
     * @param   string|int  $id     the user facing id
     * @return  string      the stored id used by p4 key
     */
    protected static function encodeId($id)
    {
        // nothing to do if the id is null
        if (!strlen($id)) {
            return null;
        }

        // subtract our id from max 32 bit int value to ensure proper sorting
        // we use a 32 bit value even on 64 bit systems to allow interoperability.
        $id = 0xFFFFFFFF - $id;

        // start with our prefix and follow up with hex encoded id
        // (the higher base makes it slightly shorter)
        $id = str_pad(dechex($id), 8, '0', STR_PAD_LEFT);
        return static::KEY_PREFIX . $id;
    }

    /**
     * Extends parent to undo our flip logic and hex decode.
     *
     * @param   string  $id     the stored id used by p4 key
     * @return  string|int      the user facing id
     */
    protected static function decodeId($id)
    {
        // nothing to do if the id is null
        if ($id === null) {
            return null;
        }

        // strip off our key prefix
        $id = substr($id, strlen(static::KEY_PREFIX));

        // hex decode it and subtract from 32 bit int to undo our sorting trick
        return (int) (0xFFFFFFFF - hexdec($id));
    }
}
