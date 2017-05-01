<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Users\Model;

use P4\Connection\ConnectionInterface as Connection;
use Projects\Model\Project;
use Record\Key\AbstractKey;

class Config extends AbstractKey
{
    const KEY_PREFIX        = 'swarm-user-';

    const COUNT_INDEX       = 1201;
    const COUNT_PREFIX      = 'swarm-followers-';
    const COUNT_BY_TYPE     = 'countByType';

    protected $followers    = null;
    protected $fields       = array(
        'follows'       => array(       // list of things this user follows
            'index'         => 1202,
            'indexFlatten'  => true,    // preserve keys and flatten for index
            'accessor'      => 'getFollows',
            'mutator'       => 'setFollows'
        ),
        'delayedComments'
    );

    /**
     * Get delayed comments for the given topic. Delayed comments are saved
     * immediately, but email notifications are delayed.
     *
     * @param   string  $topic  topic for delayed comments
     * @return  array   delayed comments with ids as keys and times as values
     */
    public function getDelayedComments($topic)
    {
        $topics = (array) $this->getRawValue('delayedComments');
        return isset($topics[$topic]) ? (array) $topics[$topic] : array();
    }

    /**
     * Set delayed comments for the given topic. If no comments are given,
     * the topic will be removed.
     *
     * @param   string      $topic      topic for delayed comments
     * @param   array|null  $comments   delayed comments with ids as keys and times as values
     * @return  Config
     */
    public function setDelayedComments($topic, array $comments = null)
    {
        $topics = (array) $this->getRawValue('delayedComments');
        if ($comments) {
            $topics[$topic] = $comments;
        } else {
            unset($topics[$topic]);
        }

        return $this->setRawValue('delayedComments', $topics);
    }

    /**
     * Get a list of things that have followers and how many followers they have.
     * You can specify options to limit the items to get follower counts for.
     *
     * This method relies upon an index of follower counts to get the number of
     * followers quickly (in a single p4 search command). The follower counts are
     * updated anytime a user follows or un-follows something. For details see
     * updateFollowCountIndex().
     *
     * @param   array       $options    COUNT_BY_TYPE - set to a type (e.g. 'user') to get a list of
     *                                                  things of that type and how many followers they
     *                                                  have (only items with followers are listed)
     * @param   Connection  $p4         the connection to use
     * @return  array       a list of things with follower counts
     *                      each entry is an array with 'id', 'type' and 'count' elements
     *                      the list is keyed on type:id.
     */
    public static function fetchFollowerCounts(array $options, Connection $p4)
    {
        $options += array(static::COUNT_BY_TYPE => null);
        $lookup   = static::COUNT_PREFIX;

        // narrow search if getting counts for a particular type
        if ($options[static::COUNT_BY_TYPE]) {
            $lookup .= $options[static::COUNT_BY_TYPE] . '-';
        }

        $counts  = array();
        $lookup  = static::COUNT_INDEX . '=' . static::encodeIndexValue($lookup) . '*';
        $results = $p4->run('search', array($lookup));
        foreach ($results->getData() as $result) {
            preg_match('/([^-]+)\-(.+)\-([0-9]+)/', $result, $matches);
            if (count($matches) === 4 && (int) $matches[3]) {
                $counts[$matches[1] . ':' . $matches[2]] = array(
                    'id'    => $matches[2],
                    'type'  => $matches[1],
                    'count' => (int) $matches[3]
                );
            }
        }

        return $counts;
    }

    /**
     * Get list of users that are following a given thing (ie. user or project)
     *
     * @param   string  $id     the id of the thing to get followers of (e.g. 'jdoe')
     * @param   string  $type   the type of thing to get followers of (e.g. 'user')
     * @return  array   a list of ids of users that are following this thing
     */
    public static function fetchFollowerIds($id, $type, Connection $p4)
    {
        $result = $p4->run(
            'search',
            static::makeSearchExpression(array('follows' => $type . ':' . $id))
        );

        $followers = array_map('static::decodeId', array_unique($result->getData()));
        natcasesort($followers);

        return $followers;
    }

    /**
     * Get this user's followers
     *
     * @return array    a list of other users following this user
     */
    public function getFollowers()
    {
        if ($this->followers === null) {
            $this->followers = static::fetchFollowerIds($this->getId(), 'user', $this->getConnection());
        }

        return $this->followers;
    }

    /**
     * Check if this user is currently following the given thing.
     *
     * @param   string      $id     the id of the thing to check (e.g. 'jdoe')
     * @param   string|null $type   optional - type of thing to check (default is 'user')
     * @return  bool        true if following thing, false otherwise
     */
    public function isFollowing($id, $type = 'user')
    {
        return in_array($id, $this->getFollows($type));
    }

    /**
     * Check if the given user is currently following this user.
     *
     * @param   string      $id     the id of the user to check if they are a follower
     * @return  bool        true if user is a follower, false otherwise
     */
    public function isFollower($id)
    {
        return in_array($id, $this->getFollowers());
    }

    /**
     * Get list of things (e.g. users, projects) this user is following.
     *
     * @param   string|null     $type   optional - get follows of a specific type.
     * @return  array           things being followed grouped by type.
     *                          each entry in the array has a string key identifying the type
     *                          and an array value listing the follow ids - unless type is given.
     *                          if type is given, a flat list of follow ids is returned.
     */
    public function getFollows($type = null)
    {
        $follows = $this->normalizeFollows($this->getRawValue('follows'));

        if ($type) {
            return isset($follows[$type]) ? $follows[$type] : array();
        }

        return $follows;
    }

    /**
     * Set list of things to follow.
     *
     * Input should be given as an array of ids to follow grouped by type.
     * Each entry in the array should have a string key identifying the
     * type and a array value listing the ids to follow.
     *
     * @param   array|null  $follows    list of follows grouped by type
     * @return  Config      to maintain a fluent interface
     */
    public function setFollows($follows)
    {
        return $this->setRawValue(
            'follows',
            $this->normalizeFollows($follows)
        );
    }

    /**
     * Add a follow for this user (e.g. to follow another user).
     *
     * @param   string      $id     the id of the thing to follow (e.g. 'jdoe')
     * @param   string|null $type   optional - type of thing to follow (default is 'user')
     * @return  Config      provides fluent interface
     */
    public function addFollow($id, $type = 'user')
    {
        $follows = $this->getFollows() + array($type => array());
        $follows[$type][] = $id;

        $this->setFollows($follows);

        return $this;
    }

    /**
     * Remove a follow for this user (e.g. to unfollow another user).
     *
     * @param   string      $id     the id of the thing to unfollow (e.g. 'jdoe')
     * @param   string|null $type   optional - type of thing to unfollow (default is 'user')
     * @return  Config      provides fluent interface
     */
    public function removeFollow($id, $type = 'user')
    {
        $follows = $this->getFollows() + array($type => array());
        $index   = array_search($id, $follows[$type]);
        unset($follows[$type][$index]);

        $this->setFollows($follows);

        return $this;
    }

    /**
     * Ensure a consistent structure for the follows array.
     *
     * For example:
     *   array(
     *     'user'    => array('john', 'jane'),
     *     'project' => array('biz', 'bang')
     *   )
     *
     * Note: the type cannot contain hyphens. Hyphens would create
     * ambiguity when reading out the follower count index (splitting
     * apart type-id).
     *
     * @param   array   $follows    input to normalize
     * @return  array   normalized follows array
     */
    protected function normalizeFollows(array $follows = null)
    {
        if (!is_array($follows)) {
            return array();
        }

        $normalized = array();
        foreach ($follows as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }

            // don't permit hyphens in the type
            if (strpos($key, '-') !== false) {
                throw new \InvalidArgumentException(
                    "Hyphens not permitted in follow item type."
                );
            }

            $value = array_unique(array_filter($value, 'is_string'));
            natcasesort($value);
            $normalized[$key] = $value;
        }

        // strip out empty follow types
        $normalized = array_filter($normalized);

        return $normalized;
    }

    /**
     * Extends parent to perform additional specialized indexing of follower counts.
     * For any newly followed or unfollowed items, we count how many followers they
     * now have and record/index the count so we can look them up quickly later.
     *
     * @param   int                     $code   the index code/number of the field
     * @param   string                  $name   the field/name of the index
     * @param   string|array|null       $value  one or more values to index
     * @param   string|array|null|false $remove one or more old values that need to be de-indexed
     *                                          pass false if this is an add and de-index can be skipped.
     * @return  AbstractKey     provides fluent interface
     * @throws  \Exception      if no id has been set
     */
    protected function index($code, $name, $value, $remove)
    {
        parent::index($code, $name, $value, $remove);

        // if this is the follows field, update count indices for affected items
        if ($name == 'follows') {
            $types = array_merge(
                array_keys((array) ($value  ?: null)),
                array_keys((array) ($remove ?: null))
            );

            foreach ($types as $type) {
                $new     = isset($value[$type])  ? $value[$type]  : array();
                $old     = isset($remove[$type]) ? $remove[$type] : array();
                $changed = array_merge(array_diff($new, $old), array_diff($old, $new));
                foreach ($changed as $id) {
                    static::updateFollowCountIndex($id, $type, $this->getConnection());
                }
            }
        }
    }

    /**
     * Update the follower count for the given item (e.g. 'jdoe', 'user').
     *
     * The index value is prefix-type-id (e.g. swarm-followers-user-jdoe)
     * The reason we prefix it is because it makes wildcard searches faster
     * (due to pushing followers deep to one branch of the btree). The
     * entire value is encoded so that p4 index won't break it into words
     *
     * The index key is type-id-count (e.g. user-slord-23). We need to
     * include the item particulars (type and id) in the key so that we can
     * tell what item the count is for when it is returned by p4 search.
     *
     * @param   string      $id     the item identifier (e.g. jdoe)
     * @param   string      $type   the type of item (e.g. user)
     * @param   Connection  $p4     the connection to use
     */
    protected static function updateFollowCountIndex($id, $type, Connection $p4)
    {
        // get the old count and de-index it (should only be one, but
        // could actually be multiple if race conditions occur)
        $lookup  = static::COUNT_PREFIX . $type . '-' . $id;
        $lookup  = static::encodeIndexValue($lookup);
        $results = $p4->run('search', array($lookup));
        foreach ($results->getData() as $result) {
            $p4->run(
                'index',
                array('-a', static::COUNT_INDEX, '-d', $result),
                $lookup
            );
        }

        // determine the new follower count and write it out
        // if item is a project, use the project model to screen out members
        $project   = $type == 'project' ? Project::fetch($id, $p4) : null;
        $followers = $project ? $project->getFollowers() : static::fetchFollowerIds($id, $type, $p4);
        $indexKey  = $type . '-' . $id . '-' . count($followers);
        $p4->run(
            'index',
            array('-a', static::COUNT_INDEX, $indexKey),
            $lookup
        );
    }
}
