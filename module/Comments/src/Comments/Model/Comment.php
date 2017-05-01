<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Comments\Model;

use Application\Permissions\Protections;
use Attachments\Model\Attachment;
use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator as ModelIterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\Exception;
use Record\Key\AbstractKey as KeyRecord;
use Users\Model\User;

/**
 * Provides persistent storage and indexing of comments.
 */
class Comment extends KeyRecord
{
    const KEY_PREFIX        = 'swarm-comment-';
    const KEY_COUNT         = 'swarm-comment:count';

    const FETCH_BY_TOPIC    = 'topic';

    const COUNT_INDEX       = 1101;

    const TASK_COMMENT          = 'comment';
    const TASK_OPEN             = 'open';
    const TASK_ADDRESSED        = 'addressed';
    const TASK_VERIFIED         = 'verified';
    const TASK_VERIFIED_ARCHIVE = 'verified:archive';

    const ACTION_ADD          = 'add';
    const ACTION_EDIT         = 'edit';
    const ACTION_NONE         = 'none';
    const ACTION_STATE_CHANGE = 'state';
    const ACTION_LIKE         = 'like';
    const ACTION_UNLIKE       = 'unlike';

    protected $userObject   = null;
    protected $fields       = array(
        'topic'     => array(               // object being commented on (e.g. changes/1)
            'index'     => 1102             // note we index by topic for later retrieval
        ),
        'context'   => array(               // specific context e.g.:
            'accessor'  => 'getContext',    // {file: '//depot/foo', leftLine: 85, rightLine: null}
            'mutator'   => 'setContext'
        ),
        'attachments' => array(             // optional file attachments
            'accessor' => 'getAttachments',
            'mutator'  => 'setAttachments'
        ),
        'flags'     => array(               // list of flags
            'accessor'  => 'getFlags',
            'mutator'   => 'setFlags'
        ),
        'taskState'     => array(
            'accessor' => 'getTaskState',
            'mutator'  => 'setTaskState'
        ),
        'likes'     => array(               // list of users who like this comment
            'accessor' => 'getLikes',
            'mutator'  => 'setLikes'
        ),
        'user',                             // user making the comment (e.g. 'jdoe')
        'time',                             // timestamp when the comment was created
        'updated',                          // timestamp when the comment was last updated
        'edited',                           // timestamp when the comment body or attachments were last edited
        'body'                              // the actual message
    );

    public function getContext()
    {
        return (array) $this->getRawValue('context');
    }

    public function setContext(array $context = null)
    {
        return $this->setRawValue('context', $context);
    }

    /**
     * Get the list of attachments to this comment.
     *
     * @return array    An array of attachment IDs
     */
    public function getAttachments()
    {
        return $this->normalizeAttachments($this->getRawValue('attachments'));
    }

    /**
     * Store attachment IDs.
     *
     * @param   $attachments    array   Store the list of attachments on this comment
     * @return  Comment                 to maintain a fluent interface
     */
    public function setAttachments($attachments)
    {
        return $this->setRawValue('attachments', $this->normalizeAttachments($attachments));
    }

    protected function normalizeAttachments($attachments)
    {
        $attachments = (array) $attachments;
        foreach ($attachments as $key => $value) {
            if (!ctype_digit((string)$value)) {
                unset($attachments[$key]);
                continue;
            }

            $attachments[$key] = (int)$value;
        }

        return array_values($attachments ?: array());
    }

    /**
     * Returns an array of flags set on this comment.
     *
     * @return  array   flag names for all flags current set on this comment
     */
    public function getFlags()
    {
        return (array) $this->getRawValue('flags');
    }

    /**
     * Set an array of active flag names on this comment.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  Comment     to maintain a fluent interface
     */
    public function setFlags(array $flags = null)
    {
        // only grab unique flags, and reset the index
        $flags = array_values(array_unique((array) $flags));

        return $this->setRawValue('flags', $flags);
    }

    /**
     * Adds the flags names in the passed array to the existing flags on this comment
     *
     * @param   array|string|null  $flags  an array of flags to add, an individual flag string or null
     * @return  Comment            to maintain a fluent interface
     */
    public function addFlags($flags = null)
    {
        $flags = array_merge($this->getFlags(), (array) $flags);
        return $this->setFlags($flags);
    }

    /**
     * Removes the flags names in the passed array from the existing flags on this comment
     *
     * @param   array|null  $flags  an array of flags to remove or null
     * @return  Comment     to maintain a fluent interface
     */
    public function removeFlags(array $flags = null)
    {
        $flags = array_diff($this->getFlags(), (array) $flags);
        return $this->setFlags($flags);
    }

    /**
     * Get list of users who like this comment.
     *
     * @return  array   list of users who like this comment
     */
    public function getLikes()
    {
        $likes = (array) $this->getRawValue('likes');
        return array_values(array_unique(array_filter($likes, 'strlen')));
    }

    /**
     * Set list of users that like this comment.
     *
     * @param   array       $users  list with users that like this comment
     * @return  Comment     to maintain a fluent interface
     */
    public function setLikes(array $users)
    {
        $users = array_values(array_unique(array_filter($users, 'strlen')));
        return $this->setRawValue('likes', $users);
    }

    /**
     * Add like for the given user for this comment.
     *
     * @param   string      $user   user to add like for
     * @return  Comment     to maintain a fluent interface
     */
    public function addLike($user)
    {
        return $this->setLikes(array_merge($this->getLikes(), array($user)));
    }

    /**
     * Remove like for the given user for this comment.
     *
     * @param   string      $user   user to remove like for
     * @return  Comment     to maintain a fluent interface
     */
    public function removeLike($user)
    {
        return $this->setLikes(array_diff($this->getLikes(), array($user)));
    }

    /**
     * Returns the current state of this comment.
     *
     * @return  string  current state of this comment
     */
    public function getTaskState()
    {
        return $this->getRawValue('taskState') ?: static::TASK_COMMENT;
    }

    /**
     * Returns a list of state transitions that are allowed from the current state.
     *
     * @return  array       list of allowed state transitions from the current state if $state is false
     */
    public function getTaskTransitions()
    {
        $translator  = $this->getConnection()->getService('translator');
        $transitions = array(
            static::TASK_COMMENT         => array(
                static::TASK_OPEN                   => $translator->t('Flag as Task ')
            ),
            static::TASK_OPEN            => array(
                static::TASK_ADDRESSED              => $translator->t('Task Addressed'),
                static::TASK_COMMENT                => $translator->t('Not a Task')
            ),
            static::TASK_ADDRESSED           => array(
                static::TASK_VERIFIED               => $translator->t('Verify Task'),
                static::TASK_VERIFIED_ARCHIVE       => $translator->t('Verify and Archive'),
                static::TASK_OPEN                   => $translator->t('Reopen Task')
            ),
            static::TASK_VERIFIED        => array(
                static::TASK_OPEN                   => $translator->t('Reopen Task')
            )
        );

        $state       = $this->getTaskState();
        $transitions = isset($transitions[$state]) ? $transitions[$state] : array();

        // ensure if we're already archived, that transitions involving archiving are removed
        if (in_array('closed', $this->getFlags())) {
            unset($transitions[static::TASK_VERIFIED_ARCHIVE]);
        }

        return $transitions;
    }

    /**
     * Sets the current state of this comment.
     *
     * @param   string      $state  the new state for this comment
     * @return  Comment     to maintain a fluent interface
     * @throws  Exception   if an invalid task state is passed
     */
    public function setTaskState($state)
    {
        // ensure we're being passed a valid task state
        $states = array(
            static::TASK_COMMENT,
            static::TASK_OPEN,
            static::TASK_ADDRESSED,
            static::TASK_VERIFIED,
            static::TASK_VERIFIED_ARCHIVE
        );

        // a null state is equivalent to the initial comment state
        $state = strlen($state) ? $state : static::TASK_COMMENT;
        if (!in_array($state, $states)) {
            throw new Exception('Invalid task state: ' . $state . '. Valid states: ' . implode(', ', $states));
        }

        // remove the pseudo-flag for archiving a comment, so it just gets set to verified
        if ($state == static::TASK_VERIFIED_ARCHIVE) {
            $state = static::TASK_VERIFIED;
        }

        return $this->setRawValue('taskState', $state);
    }

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by topic.
     *
     * @param   array               $options       an optional array of search conditions and/or options
     *                                             supported options are:
     *                                             FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                             'max' number of entries.
     *                                             FETCH_AFTER - set to an id _after_ which we start collecting
     *                                             FETCH_BY_TOPIC - set to a 'topic' id to limit results
     *                                             FETCH_BY_IDS - provide an array of ids to fetch.
     *                                                            not compatible with FETCH_SEARCH or FETCH_AFTER.
     * @param   Connection          $p4            the perforce connection to use
     * @param   Protections|null    $protections   optional - if set, comments associated with files the user cannot
     *                                             read according to given protections will be removed before returning
     * @return  ModelIterator                      the list of zero or more matching activity objects
     */
    public static function fetchAll(array $options, Connection $p4, Protections $protections = null)
    {
        // normalize options
        $options += array(static::FETCH_BY_TOPIC  => null);

        // build a search expression for topic.
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            array('topic' => $options[static::FETCH_BY_TOPIC])
        );

        $comments = parent::fetchAll($options, $p4);

        // filter comments according to given protections (if given)
        if ($protections) {
            $comments->filterByCallback(
                function (Comment $comment) use ($protections) {
                    $context = $comment->getContext();
                    $file    = isset($context['file']) ? $context['file'] : null;
                    return !$file || $protections->filterPaths($file, Protections::MODE_READ);
                }
            );
        }

        return $comments;
    }

    /**
     * Saves the records values and updates indexes as needed.
     *
     * Extends the basic save behavior to also:
     * - set timestamp to current time if one isn't already set
     * - remove existing count indices for this topic and add a current one
     *
     * @return  Comment     to maintain a fluent interface
     * @throws  Exception   if no topic is set or an id is present but the record was not fetched
     */
    public function save()
    {
        if (!strlen($this->get('topic'))) {
            throw new Exception('Cannot save, no topic has been set.');
        }

        // always set update time to now
        $this->set('updated', time());

        // if no time is already set, use now as a default
        $this->set('time', $this->get('time') ?: $this->get('updated'));

        // scan for new attachments
        $attachments = $this->getAttachments();
        $original    = isset($this->original['attachments']) ? (array) $this->original['attachments'] : array();
        $attachments = array_diff($attachments, $original);

        // let parent actually save before we go about indexing
        parent::save();

        $this->updateCountIndex();

        // update attachment references
        if ($attachments) {
            $attachments = Attachment::fetchAll(
                array(Attachment::FETCH_BY_IDS => $attachments),
                $this->getConnection()
            );

            foreach ($attachments as $attachment) {
                $attachment->addReference('comment', $this->getId());
                $attachment->save();
            }
        }

        return $this;
    }

    /**
     * Delete this comment record.
     * Extends parent to update topic count index.
     *
     * @return Comment  provides fluent interface
     */
    public function delete()
    {
        parent::delete();
        $this->updateCountIndex();

        return $this;
    }

    /**
     * Try to fetch the associated user as a user spec object.
     *
     * @return User     the associated user object
     * @throws SpecNotFoundException    if user does not exist
     */
    public function getUserObject()
    {
        if (!$this->userObject) {
            $this->userObject = User::fetch($this->get('user'), $this->getConnection());
        }

        return $this->userObject;
    }

    /**
     * Check if the associated user is valid (exists)
     *
     * @return bool     true if the user exists, false otherwise.
     */
    public function isValidUser()
    {
        try {
            $this->getUserObject();
        } catch (SpecNotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * Extract file information from context.
     *
     * @return  array  array of file info - values may be null.
     */
    public function getFileContext()
    {
        $context = $this->get('context') + array(
            'file'      => null,
            'change'    => null,
            'review'    => null,
            'leftLine'  => null,
            'rightLine' => null,
            'content'   => null,
            'version'   => null,
        );

        $file            = $context['file'];
        $context['md5']  = $file ? md5($file) : null;
        $context['name'] = basename($file);
        $context['line'] = $context['rightLine'] ?: $context['leftLine'];

        return $context;
    }

    /**
     * Returns the iterator with attachment models for given comments. Fetching is done
     * efficiently by collecting all attachment ids from all given comments and then
     * doing a single fetch query on Attachments.
     *
     * @param   ModelIterator   $comments   comments to fetch attachments for
     * @param   Connection      $p4         Perforce connection to use
     * @return  ModelIterator               iterator with attachments for given comments
     */
    public static function fetchAttachmentsByComments(ModelIterator $comments, Connection $p4)
    {
        $attachments = $comments->invoke('getAttachments');
        $attachments = $attachments ? call_user_func_array('array_merge', $attachments) : null;
        return $attachments
            ? Attachment::fetchAll(array(Attachment::FETCH_BY_IDS => $attachments), $p4)
            : new ModelIterator;
    }

    /**
     * Retrieves the open/closed comment counts stored by p4 index. This can
     * be lower than the actual comment counts due to the potential for race
     * conditions when saving, but should generally be correct.
     *
     * @param  string|array $topics     one or more topics to count open/closed comments in.
     * @param  Connection   $p4         the perforce connection to use
     * @return array        a single array with open/closed count if a string topic is given,
     *                      otherwise an array of open/closed count arrays keyed by topic.
     */
    public static function countByTopic($topics, Connection $p4)
    {
        // encode the topics for searching.
        $scalar = !is_array($topics);
        $topics = array_filter((array) $topics, 'strlen');
        $counts = array_fill_keys($topics, array(0, 0));
        foreach ($topics as $key => $topic) {
            $topics[$key] = static::encodeIndexValue($topic);
        }

        // early exit if we don't have any topics to search for.
        if (!$topics) {
            return array();
        }

        $query  = static::COUNT_INDEX . '=' . implode('|' . static::COUNT_INDEX . '=', $topics);
        $result = $p4->run('search', $query)->getData();

        // search should return one (possibly more) results for each topic
        // results take the form of 'encodedTopic-openedCount-closedCount'
        // take the highest total count we find for each topic.
        foreach ($result as $count) {
            if (!strpos($count, '-')) {
                continue;
            }

            // pre 2015.2 counts did not include closed comments, so default that to 0
            $parts  = explode('-', $count);
            $topic  = static::decodeIndexValue($parts[0]);
            $opened = (int) $parts[1];
            $closed = isset($parts[2]) ? (int) $parts[2] : 0;

            if (isset($counts[$topic]) && array_sum($counts[$topic]) < $opened + $closed) {
                $counts[$topic] = array($opened, $closed);
            }
        }

        return $scalar ? current($counts) : $counts;
    }

    /**
     * Update the open/closed comment count index for this comment's topic.
     *
     * For each topic we maintain an index of the number of open and closed
     * comments in that topic. This makes it easy to fetch the number of
     * comments in arbitrary topics with one call to p4 search.
     *
     * @return Comment  provides fluent interface
     */
    protected function updateCountIndex()
    {
        // retrieve all existing indices for this topic count and delete them
        $p4      = $this->getConnection();
        $topic   = static::encodeIndexValue($this->get('topic'));
        $query   = static::COUNT_INDEX . '=' . $topic;
        $indices = $p4->run('search', $query)->getData();
        foreach ($indices as $index) {
            $p4->run(
                'index',
                array('-a', static::COUNT_INDEX, '-d', $index),
                $topic
            );
        }

        // read out all comments for this topic so we can get the new counts
        // we try and do this as close to writing out the index to improve
        // our chances of getting an accurate count.
        $comments = static::fetchAll(
            array(static::FETCH_BY_TOPIC => $this->get('topic')),
            $p4
        );

        // early exit if no comments (zero count)
        if (!count($comments)) {
            return $this;
        }

        // count open/closed (aka 'archived') comments separately
        $opened = 0;
        $closed = 0;
        foreach ($comments as $comment) {
            in_array('closed', $comment->getFlags()) ? $closed++ : $opened++;
        }

        // write out our current count to the index.
        // we include the encoded topic id in the key so that we can tell
        // which topic each count is for when searching for multiple topics.
        $p4->run(
            'index',
            array('-a', static::COUNT_INDEX, $topic . '-' . $opened . '-' . $closed),
            $topic
        );

        return $this;
    }

    /**
     * Attempts to derive the action (add, edit, state-change) from the provided Comments
     *
     * @param   Comment|array       $new    the "newer" incarnation of the comment
     * @param   Comment|null|array  $old    the "older" incarnation of the comment
     *                                      (null or empty array will short circuit to an "Add" action)
     * @return  string  A string corresponding to one of the action constants:
     *                      Comment::ACTION_ADD
     *                      Comment::ACTION_EDIT
     *                      Comment::ACTION_STATE_CHANGE
     *                      Comment::ACTION_LIKE
     *                      Comment::ACTION_UNLIKE
     *                      Comment::ACTION_NONE
     */
    public static function deriveAction($new, $old = null)
    {
        $old = $old instanceof self ? $old->get() : $old;
        $new = $new instanceof self ? $new->get() : $new;

        if (!is_array($new) || (!is_array($old) && !is_null($old))) {
            throw new \InvalidArgumentException(
                'Cannot derive action: New and Old must be comment instances or arrays. '
                . 'Old may be null to indicate an add.'
            );
        }

        if ($new && !$old) {
            return static::ACTION_ADD;
        }

        // normalize arrays to avoid key lookup failures
        $old += array('body' => null, 'attachments' => null, 'taskState' => null, 'likes' => null);
        $new += array('body' => null, 'attachments' => null, 'taskState' => null, 'likes' => null);

        if ($old['body'] != $new['body'] || $old['attachments'] != $new['attachments']) {
            return static::ACTION_EDIT;
        }

        if ($old['taskState'] != $new['taskState']) {
            return static::ACTION_STATE_CHANGE;
        }

        $newLikes = count((array) $new['likes']);
        $oldLikes = count((array) $old['likes']);
        if ($newLikes !== $oldLikes) {
            return $newLikes > $oldLikes ? static::ACTION_LIKE : static::ACTION_UNLIKE;
        }

        return static::ACTION_NONE;
    }
}
