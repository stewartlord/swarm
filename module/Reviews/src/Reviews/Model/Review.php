<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\Model;

use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\CommandResult;
use P4\Connection\Exception\CommandException;
use P4\Log\Logger;
use P4\OutputHandler\Limit;
use P4\Spec\Client;
use P4\Spec\Depot;
use P4\Spec\Exception\NotFoundException;
use P4\Spec\Change;
use P4\Uuid\Uuid;
use Users\Model\User;
use Projects\Filter\ProjectList as ProjectListFilter;
use Projects\Model\Project;
use Record\Exception\Exception;
use Record\Key\AbstractKey as KeyRecord;

/**
 * Provides persistent storage and indexing of reviews.
 */
class Review extends KeyRecord
{
    const KEY_PREFIX            = 'swarm-review-';
    const UPGRADE_LEVEL         = 4;

    const LOCK_CHANGE_PREFIX    = 'change-review-';

    const FETCH_BY_AUTHOR       = 'author';
    const FETCH_BY_CHANGE       = 'change';
    const FETCH_BY_PARTICIPANTS = 'participants';
    const FETCH_BY_HAS_REVIEWER = 'hasReviewer';
    const FETCH_BY_PROJECT      = 'project';
    const FETCH_BY_GROUP        = 'group';
    const FETCH_BY_STATE        = 'state';
    const FETCH_BY_TEST_STATUS  = 'testStatus';

    const STATE_NEEDS_REVIEW    = 'needsReview';
    const STATE_NEEDS_REVISION  = 'needsRevision';
    const STATE_APPROVED        = 'approved';
    const STATE_REJECTED        = 'rejected';
    const STATE_ARCHIVED        = 'archived';

    const COMMIT_CREDIT_AUTHOR  = 'creditAuthor';
    const COMMIT_DESCRIPTION    = 'description';
    const COMMIT_JOBS           = 'jobs';
    const COMMIT_FIX_STATUS     = 'fixStatus';

    const TEST_STATUS_PASS      = 'pass';
    const TEST_STATUS_FAIL      = 'fail';

    protected $userObjects      = array();
    protected $fields           = array(
        'type'          => array(
            'accessor'      => 'getType',
            'readOnly'      => true
        ),
        'changes'       => array(       // changes associated with this review
            'index'         => 1301,
            'accessor'      => 'getChanges',
            'mutator'       => 'setChanges'
        ),
        'commits'       => array(
            'accessor'      => 'getCommits',
            'mutator'       => 'setCommits'
        ),
        'versions'      => array(
            'hidden'        => true,
            'accessor'      => 'getVersions',
            'mutator'       => 'setVersions'
        ),
        'author'        => array(       // author of code under review
            'index'         => 1302
        ),
        'participants'  => array(       // anyone who has touched the review (workflow change, commented on, etc.)
            'index'         => 1304,    // we return just user ids but properties (e.g. votes) are stored here too
            'indexOnlyKeys' => true,
            'accessor'      => 'getParticipants',
            'mutator'       => 'setParticipants'
        ),
        'participantsData' => array(
            'accessor'      => 'getParticipantsData',
            'mutator'       => 'setParticipantsData',
            'unstored'      => true
        ),
        'hasReviewer'   => array(       // flag to indicate if review has one or more reviewers
            'index'         => 1305     // necessary to avoid using wildcards in p4 search
        ),
        'description'   => array(       // change description
            'accessor'      => 'getDescription',
            'mutator'       => 'setDescription',
            'index'         => 1306,
            'indexWords'    => true
        ),
        'created',                      // timestamp when the review was created
        'updated',                      // timestamp when the review was last updated
        'projects'      => array(       // an array with project id's as keys and branches as values
            'index'         => 1307,
            'accessor'      => 'getProjects',
            'mutator'       => 'setProjects'
        ),
        'state'         => array(       // one of: needsReview, needsRevision, approved, rejected
            'index'         => 1308,
            'default'       => 'needsReview',
            'accessor'      => 'getState',
            'mutator'       => 'setState'
        ),
        'stateLabel'    => array(
            'accessor'      => 'getStateLabel',
            'unstored'      => true
        ),
        'testStatus'    => array(       // one of: pass, fail
            'index'         => 1309
        ),
        'testDetails'   => array(
            'accessor'      => 'getTestDetails',
            'mutator'       => 'setTestDetails'
        ),
        'deployStatus',                 // one of: success, fail
        'deployDetails' => array(
            'accessor'      => 'getDeployDetails',
            'mutator'       => 'setDeployDetails'
        ),
        'pending'       => array(
            'index'         => 1310,
            'accessor'      => 'isPending',
            'mutator'       => 'setPending'
        ),
        'commitStatus'  => array(
            'accessor'      => 'getCommitStatus',
            'mutator'       => 'setCommitStatus'
        ),
        'token'         => array(
            'accessor'      => 'getToken',
            'mutator'       => 'setToken',
            'hidden'        => true
        ),
        'upgrade'       => array(
            'accessor'      => 'getUpgrade',
            'hidden'        => true
        ),
        'groups'        => array(       // an array with associated groups
            'index'         => 1311,
            'accessor'      => 'getGroups',
            'mutator'       => 'setGroups'
        ),
    );

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by various fields.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  supported options are:
     *                                  FETCH_BY_CHANGE       - set to a 'changes' value(s) to limit results
     *                                  FETCH_BY_HAS_REVIEWER - set to limit results to include only records that:
     *                                                          * have at least one reviewer (if value is '1')
     *                                                          * don't have any reviewers   (if value is '0')
     *                                  FETCH_BY_STATE        - set to a 'state' value(s) to limit results
     *                                  FETCH_BY_TEST_STATUS  - set to a 'testStatus' values(s) to limit results
     * @param   Connection  $p4         the perforce connection to use
     * @return  \P4\Model\Fielded\Iterator   the list of zero or more matching review objects
     */
    public static function fetchAll(array $options, $p4)
    {
        // normalize options
        $options += array(
            static::FETCH_BY_AUTHOR       => null,
            static::FETCH_BY_CHANGE       => null,
            static::FETCH_BY_PARTICIPANTS => null,
            static::FETCH_BY_HAS_REVIEWER => null,
            static::FETCH_BY_PROJECT      => null,
            static::FETCH_BY_GROUP        => null,
            static::FETCH_BY_STATE        => null,
            static::FETCH_BY_TEST_STATUS  => null
        );

        // build the search expression
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            array(
                'author'       => $options[static::FETCH_BY_AUTHOR],
                'changes'      => $options[static::FETCH_BY_CHANGE],
                'participants' => $options[static::FETCH_BY_PARTICIPANTS],
                'hasReviewer'  => $options[static::FETCH_BY_HAS_REVIEWER],
                'projects'     => $options[static::FETCH_BY_PROJECT],
                'groups'       => $options[static::FETCH_BY_GROUP],
                'state'        => $options[static::FETCH_BY_STATE],
                'testStatus'   => $options[static::FETCH_BY_TEST_STATUS]
            )
        );

        return parent::fetchAll($options, $p4);
    }

    /**
     * Return new review instance populated from the given change.
     *
     * @param   Change|string   $change     change to populate review record from
     * @param   Connection      $p4         the perforce connection to use
     * @return  Reviews         instance of this model
     */
    public static function createFromChange($change, $p4 = null)
    {
        if (!$change instanceof Change) {
            $change = Change::fetch($change, $p4);
        }

        // refuse to create reviews for un-promoted remote edge shelves
        if ($change->isRemoteEdgeShelf()) {
            throw new \InvalidArgumentException(
                "Cannot create review. The change is not promoted and appears to be on a remote edge server."
            );
        }

        // populate data from the change
        $model = new static($p4);
        $model->set('author',      $change->getUser());
        $model->set('description', $change->getDescription());
        $model->addParticipant($change->getUser());

        // add the change as either a pending or committed value
        if ($change->isSubmitted()) {
            $model->setPending(false);
            $model->addCommit($change);
            $model->addChange($change->getOriginalId());
        } else {
            $model->setPending(true);
            $model->addChange($change);
        }

        return $model;
    }

    /**
     * Updates this review record using the passed change.
     *
     * Add the change as a participant in this review and, if its pending,
     * updates the swarm managed shelf with the changes shelved content.
     *
     * @param   Change|string   $change                 change to populate review record from
     * @param   bool            $unapproveModified      whether approved reviews can be unapproved if they contain
     *                                                  modified files
     * @return  Review          instance of this model
     * @throws  \Exception      rethrows any exceptions which occur during re-shelving
     */
    public function updateFromChange($change, $unapproveModified = true)
    {
        // normalize change to an object
        $p4 = $this->getConnection();
        if (!$change instanceof Change) {
            $change = Change::fetch($change, $p4);
        }

        // our managed shelf should always be pending but defend anyways
        $shelf = Change::fetch($this->getId(), $p4);
        if ($shelf->isSubmitted()) {
            throw new Exception(
                'Cannot update review; the shelved change we manage is unexpectedly committed.'
            );
        }

        // add the passed change's id to the review
        if ($change->isSubmitted()) {
            // if we've already added a committed version for this change, nothing to do
            foreach ($this->getVersions() as $version) {
                if ($version['change'] == $change->getId() && !$version['pending']) {
                    return $this;
                }
            }

            $this->addCommit($change);
            $this->addChange($change->getOriginalId());
        } else {
            $this->addChange($change);
        }

        // ensure the change user is now a participant
        $this->addParticipant($change->getUser());

        // clear commit status if:
        // - this review isn't mid-commit (intended to clear old errors)
        // - this review is in the process of committing the given change
        if (!$this->isCommitting() || $this->getCommitStatus('change') == $change->getOriginalId()) {
            $this->setCommitStatus(null);
        }

        // try to determine the stream by inspecting the user's client
        // if the client is already deleted we're left with a hard false
        // indicating we couldn't tell one way or the other.
        $stream = false;
        try {
            $stream = Client::fetch($change->getClient())->getStream();
        } catch (\InvalidArgumentException $e) {
        } catch (NotFoundException $e) {
            // failed to get stream from client (client might be on an edge)
            // we don't consider this fatal and will try another approach
            unset($e);

            // try to determine the stream by looking at the path to the first file
            $stream = $this->guessStreamFromChange($change);
        }

        // we'll need a client for this next bit, we're going to update our shelved files
        $p4->getService('clients')->grab();
        try {
            // try and hard reset the client to ensure a clean environment
            $p4->getService('clients')->reset(true, $stream);

            // update metadata on the canonical shelf:
            //  - swap its client over to the one we grabbed
            //  - match type (public/restricted) of updating change
            //  - add any jobs from the updating change
            $shelf->setClient($p4->getClient())
                  ->setType($change->getType())
                  ->setJobs(array_unique(array_merge($change->getJobs(), $shelf->getJobs())))
                  ->setUser($p4->getUser())
                  ->save(true);

            // if the current contents of the canonical shelf are pending,
            // but not archived (as is the case for pre-versioning reviews),
            // attempt to archive it before we clobber it with this update.
            $head = end($this->getVersions());
            if ($head
                && $head['pending']
                && $head['change'] == $shelf->getId()
                && !isset($head['archiveChange'])
            ) {
                try {
                    $this->retroactiveArchive($shelf);
                } catch (\Exception $e) {
                    // well at least we tried!
                }
            }

            // evaluate whether the new version differs, we get back a flag indicating the amount of change:
            // 0 - no changes, 1 - modified name, type or digest, 2 - modified only insignificant fields
            $changesDiffer = $this->changesDiffer($shelf, $change);

            // revert state back to 'Needs Review' if auto state reversion is enabled, the review
            // was approved and the new version is different
            if ($this->getState() === static::STATE_APPROVED && $unapproveModified && $changesDiffer === 1) {
                $this->setState(static::STATE_NEEDS_REVIEW);
            }

            // if the contributing change is a commit:
            //  - empty out our shelf
            //  - add a version entry for the commit
            //  - clear our pending flag (review is committed now)
            if ($change->isSubmitted()) {
                // forcibly delete files in our shelf (in case another client has pending resolves)
                // silence the expected exceptions that occur if no shelved files were present
                // (e.g. user commits to a committed review) or files can't be deleted due to
                // pending resolves in another client (still an issue if server version <2014.2)
                try {
                    $p4->run('shelve', array('-d', '-f', '-c', $shelf->getId()));
                } catch (CommandException $e) {
                    if (preg_match('/needs resolve\sShelve aborted/', $e->getMessage())) {
                        Logger::log(Logger::ERR, $e);
                    } elseif (strpos($e->getMessage(), 'No shelved files in changelist to delete.') === false) {
                        throw $e;
                    }
                    unset($e);
                }

                // write a new version entry for this commit
                // only include the stream if we could determine its value
                $this->addVersion(
                    array(
                        'change'     => $change->getId(),
                        'user'       => $change->getUser(),
                        'time'       => $change->getTime(),
                        'pending'    => false,
                        'difference' => $changesDiffer
                    )
                    + ($stream !== false ? array('stream' => $stream) : array())
                );

                // at this point we have no shelved files, clear our isPending status
                $this->setPending(false);
            }

            // if the contributing change is pending and files have been updated:
            //  - unshelve it and check if that opened any files
            //  - bypass exclusive locks if supported, always check and throw if need be
            //  - update the canonical shelf with opened files
            //  - create a new archive/version for posterity
            //  - set the pending flag (review is not committed now)
            if ($change->isPending() && $changesDiffer) {
                $flags  = $this->canBypassLocks() ? array('--bypass-exclusive-lock') : array();
                $flags  = array_merge($flags, array('-s', $change->getId(), '-c', $shelf->getId()));
                $result = $p4->run('unshelve', $flags);
                $opened = array_filter($result->getData(), 'is_array') && !$result->hasWarnings();
                $this->exclusiveOpenCheck($result);
                if ($opened) {
                    // shelve opened files to the canonical shelf
                    $p4->run('shelve', array('-r', '-c', $shelf->getId()));

                    // we know we've shelved some files so update our 'pending' status
                    $this->setPending(true);

                    // make a new archive/version for this update
                    $this->archiveShelf(
                        $change,
                        array('difference' => $changesDiffer)
                        + ($stream !== false ? array('stream' => $stream) : array())
                    );

                    // we're done with the workspace files, be friendly and remove them
                    $p4->getService('clients')->clearFiles();
                }
            }
        } catch (\Exception $e) {
        }
        $p4->getService('clients')->release();

        // if badnesses occurred re-throw now that we have released our client lock
        if (isset($e)) {
            throw $e;
        }

        return $this;
    }

    /**
     * Commits this review's pending work to perforce.
     *
     * You'll need to call 'update from change' after running this to have
     * the new change added to the review record.
     *
     * @param   array           $options        optional - currently supported options are:
     *                                            COMMIT_CREDIT_AUTHOR - credit change to review author
     *                                            COMMIT_DESCRIPTION   - change description
     *                                            COMMIT_JOBS          - list of jobs to attach to the committing change
     *                                            COMMIT_FIX_STATUS    - status to set on jobs upon commit
     * @param   Connection|null $p4             optional - connection to use for the submit or null for default
     *                                          it is recommend this be done as the user committing.
     * @return  Change          the submitted change object; useful for passing to update from change
     * @throws  Exception       if there are no pending files to commit
     * @throws  \Exception      rethrows any errors that occur during commit
     */
    public function commit(array $options = array(), Connection $p4 = null)
    {
        // normalize connection to use, we may have received null
        $p4 = $p4 ?: $this->getConnection();

        // normalize options
        $options += array(
            static::COMMIT_CREDIT_AUTHOR => null,
            static::COMMIT_DESCRIPTION   => null,
            static::COMMIT_JOBS          => null,
            static::COMMIT_FIX_STATUS    => null
        );

        // ensure commit status is set
        $this->setCommitStatus(array('start' => time()))->save();

        // we'll need a client for this next bit
        $p4->getService('clients')->grab();
        try {
            // try and hard reset the client to ensure a clean environment
            // if the change is against a stream; make sure we're on it
            $p4->getService('clients')->reset(true, $this->getHeadStream());

            // get the authoritative shelf, we need to examine if its restricted when creating the submit
            $shelf = Change::fetch($this->getId(), $p4);

            // create a new 'commit' change, we never commit the managed change
            // as we may later need to re-open this review.
            $commit = new Change($p4);
            $commit->setDescription($options[static::COMMIT_DESCRIPTION] ?: $this->get('description'))
                   ->setJobs($options[static::COMMIT_JOBS])
                   ->setFixStatus($options[static::COMMIT_FIX_STATUS])
                   ->setType($shelf->getType())
                   ->save();

            // update status with our change id, state and committer
            $this->setCommitStatus('change',    $commit->getId())
                 ->setCommitStatus('status',    'Unshelving')
                 ->setCommitStatus('committer', $p4->getUser())
                 ->save();

            // unshelve our managed change and check if that opened any files.
            // bypass exclusive locks if supported, always check and throw if need be.
            $flags  = $this->canBypassLocks() ? array('--bypass-exclusive-lock') : array();
            $flags  = array_merge($flags, array('-s', $this->getId(), '-c', $commit->getId()));
            $result = $p4->run('unshelve', $flags);
            $opened = $result->hasData() && !$result->hasWarnings();
            $this->exclusiveOpenCheck($result);

            // if we didn't unshelve any files blow up.
            if (!$opened) {
                throw new Exception(
                    "Review doesn't contain any files to commit."
                );
            }

            // we need to get the change id in as a commit early to
            // avoid having issues with double reporting activity.
            // also a good opportunity to update the state.
            $this->addCommit($commit->getId())
                 ->setCommitStatus('status', 'Committing')
                 ->save();

            // we must have unshelved some work, lets commit it.
            $commit->submit();

            $this->setCommitStatus('end', time())
                 ->setCommitStatus('status', 'Committed')
                 ->save();
        } catch (\Exception $e) {
            // if we got far enough to create the commit, remove it from the
            // list of 'commits' for this review as we didn't make it in.
            if (isset($commit) && $commit->getId()) {
                $this->setCommits(array_diff($this->getCommits(), array($commit->getId())));
                $this->setChanges(array_diff($this->getChanges(), array($commit->getId())));
            }

            // clear out the commit status but convey that we failed
            // we only use the first line of the exception as they get a bit too
            // detailed later on when not mergable.
            $this->setCommitStatus(array('error' => strtok($e->getMessage(), "\n")))
                 ->save();

            // as something went wrong we might be leaving files behind; cleanup
            $p4->getService('clients')->clearFiles();

            // delete the commit change we created; it's no longer needed
            // suppress exceptions without overwriting the one that got us here
            try {
                isset($commit) && $commit->delete();
            } catch (\Exception $ignore) {
            }
        }

        $p4->getService('clients')->release();

        // if badnesses occurred re-throw now that we have released our client lock
        if (isset($e)) {
            throw $e;
        }

        // if the credit author flag is set, re-own the change so the review creator gets credit
        if ($options[static::COMMIT_CREDIT_AUTHOR] && $p4->getUser() != $this->get('author')) {
            $p4Admin = $this->getConnection();
            $p4Admin->getService('clients')->grab();
            try {
                $commit->setConnection($p4Admin)
                       ->setUser($this->get('author'))
                       ->save(true);
            } catch (\Exception $e) {
                Logger::log(Logger::ERR, 'Failed to re-own change ' . $commit->getId() . ' to ' . $this->get('author'));
            }

            // ensure client gets released and we stop using the admin connection even if an exception occurred
            $p4Admin->getService('clients')->release();
            $commit->setConnection($p4);
        }

        return $commit;
    }

    /**
     * Returns the type of review we're dealing with.
     *
     * @return  string  the 'type' of this review, one of default or git
     */
    public function getType()
    {
        return $this->getRawValue('type') ?: 'default';
    }

    /**
     * Get the commit status for this code review
     *
     * @param   string|null     $field  a specific key to retreive or null for all commit status
     *                                  if a field is specified which doesn't exist null is returned.
     * @return  string  Current state of this code review
     */
    public function getCommitStatus($field = null)
    {
        $status = (array) $this->getRawValue('commitStatus');

        // validate commit status
        // detect race-condition where commit-status is not empty, but the commit has been processed
        // if the commit is in changes and versions, we have processed it and status should be empty
        if (isset($status['change']) && in_array($status['change'], $this->getChanges())) {
            // extract commits from versions so we can look for the commit in question
            $commits = array();
            foreach ((array) $this->getRawValue('versions') as $version) {
                $version += array('change' => null, 'pending' => null);
                if (!$version['pending'] && $version['change'] >= $status['change']) {
                    $commits[] = '@=' . $version['change'];
                }
            }

            // if the commit was not renumbered the number could match exactly
            // if we don't get an exact match, we could still match the original id
            if ($commits && in_array('@=' . $status['change'], $commits)) {
                $status = array();
            } elseif ($commits) {
                try {
                    foreach ($this->getConnection()->run('changes', $commits)->getData() as $change) {
                        if (isset($change['oldChange']) && $status['change'] == $change['oldChange']) {
                            $status = array();
                        }
                    }
                } catch (\Exception $e) {
                    // not worth breaking things to possibly fix a race condition
                }
            }
        }

        if (!$field) {
            return $status;
        }

        return isset($status[$field]) ? $status[$field] : null;
    }

    /**
     * Set the commit status for this code review.
     *
     * @param   string|array    $fieldOrValues  a specific field name or an array of all new values
     * @param   mixed           $value          if a field was specified in param 1, the new value to use
     * @return  Review          to maintain a fluent interface
     */
    public function setCommitStatus($fieldOrValues, $value = null)
    {
        // if param 1 isn't a string it our new commit status
        if (!is_string($fieldOrValues)) {
            return $this->setRawValue('commitStatus', (array) $fieldOrValues);
        }

        // param 1 was a string, lets treat it as specific key to update
        $status = $this->getCommitStatus();
        $status[$fieldOrValues] = $value;
        return $this->setRawValue('commitStatus', $status);
    }

    /**
     * This method will determine if a commit is presently in progress based on the
     * data held in commit status.
     *
     * @return  bool    true if commit is actively in progress, false otherwise
     */
    public function isCommitting()
    {
        return $this->getCommitStatus() && !$this->getCommitStatus('error');
    }

    /**
     * Get the current state for this code review e.g. needsReview
     *
     * @return  string  Current state of this code review
     */
    public function getState()
    {
        return $this->getRawValue('state');
    }

    /**
     * Set the current state for this code review e.g. needsReview
     *
     * @param   string  $state  Current state of this code review
     * @return  Review          to maintain a fluent interface
     */
    public function setState($state)
    {
        // if we got approved:commit, simply store approved, the second
        // half is a queue to our caller that they aught to commit us.
        if ($state == 'approved:commit') {
            $state = 'approved';
        }

        return $this->setRawValue('state', $state);
    }

    /**
     * Get the participant data. Note the values are stored under the 'participants' field
     * but that accessor only exposes the IDs, this accessor exposes... _everything_.
     * The author is automatically included.
     *
     * User ids will be keys and each will have an array of properties associated to it
     * (such as vote, required, etc.).
     * If a specific 'field' is specified the user ids will be keys and each will have
     * just the specified property associated to it. Users lacking the specified field
     * will not be returned.
     *
     * @param   null|string     $field  optional - limit returned data to only 'field'; users lacking
     *                                  the specified field will not be included in the result.
     * @return  array   participant ids as keys each associated with properties array.
     */
    public function getParticipantsData($field = null)
    {
        // handle upgrade to v3 (2014.2)
        //  - numerically indexed user ids become arrays keyed on user id
        //  - votes move into participant array
        if ((int) $this->get('upgrade') < 3) {
            $participants = array();
            foreach ((array) $this->getRawValue('participants') as $key => $value) {
                if (is_string($value)) {
                    $key   = $value;
                    $value = array();
                }

                $participants[$key] = $value;
            }

            // move votes into participant metadata
            if ($this->issetRawValue('votes')) {
                // note we only honor votes for 'reviewers' if you are not a reviewer
                // your vote would have been ignored by getVotes and should be ignored here
                $author = $this->get('author');
                foreach ((array) $this->getRawValue('votes') as $user => $vote) {
                    if (isset($participants[$user]) && $user !== $author) {
                        $participants[$user] = array('vote' => $vote);
                    }
                }
                $this->unsetRawValue('votes');
            }

            $this->setRawValue('participants', $participants);
        }

        // handle upgrade to v4 (2014.3)
        // - single vote values become structured arrays with version info, e.g. [value => 1, version => 3]
        if ((int) $this->get('upgrade') < 4) {
            $participants = $this->getRawValue('participants');
            foreach ($participants as $user => $data) {
                if (isset($data['vote'])) {
                    $participants[$user]['vote'] = $this->normalizeVote($user, $data['vote'], true);
                    if (!$participants[$user]['vote']) {
                        unset($participants[$user]['vote']);
                    }
                }
            }

            $this->setRawValue('participants', $participants);
        }

        $participants = $this->normalizeParticipants($this->getRawValue('participants'));

        // if a specific field was specified, only include participants
        // that have that value and only include the one requested field
        if ($field) {
            foreach ($participants as $id => $data) {
                if (!isset($data[$field])) {
                    unset($participants[$id]);
                } else {
                    $participants[$id] = $data[$field];
                }
            }
        }

        return $participants;
    }

    /**
     * If only values is specified, updates all participant data.
     * In that usage values should appear similar to:
     *  $values => array('gnicol' => array(), 'slord' => array('required' => true))
     *
     * If both a values and field are specified, updates the specific property on the
     * participants array. Any participants not specified in the updated values array
     * will have the property removed if its already present. They will not be removed
     * as a participant though. We will then ensure a participate entry is present for
     * all specified users and that the value reflects what was passed.
     * In that usage values should appear similar to:
     *  $values => array('slord' => true), $field => 'required'
     *
     * @param   array|null      $values     the updated id/value(s) array
     * @param   null|string     $field      optional - a specific field we are updating (e.g. vote)
     * @return  Review  to maintain a fluent interface
     */
    public function setParticipantsData(array $values = null, $field = null)
    {
        // if no field was specified; we're updating everything just normalize, set, return
        if ($field === null) {
            return $this->setRawValue('participants', $this->normalizeParticipants($values, true));
        }

        // looks like we're just doing one specific field; make the update
        // first remove the specified field from all participants that are not listed
        $values       = (array) $values;
        $participants = $this->getParticipantsData();
        foreach (array_diff_key($participants, $values) as $id => $value) {
            unset($participants[$id][$field]);
        }

        // ensure a participant entry exists for all specified users and update value
        foreach ($values as $id => $value) {
            $participants += array($id => array());
            $participants[$id][$field] = $value;
        }

        return $this->setRawValue('participants', $this->normalizeParticipants($participants, true));
    }

    /**
     * Update value(s) for a specific participant.
     *
     * If no field is specified, this clobbers the existing data for the given
     * participant with the new value.
     * If a field is specified, only the specific field is updated; any other
     * fields present on the participant are unchanged.
     *
     * @param   string  $user   the user we are setting data on
     * @param   mixed   $value  an array of all values (if no field was specified) otherwise the new value for $field
     * @param   mixed   $field  optional - if specified the specific field to update
     * @return  Review  to maintain a fluent interface
     */
    public function setParticipantData($user, $value, $field = null)
    {
        $participants  = $this->getParticipantsData();
        $participants += array($user => array());

        // if a specific field was specified; maintain all other properties
        if ($field) {
            $value = array($field => $value) + $participants[$user];
        }

        $participants[$user] = (array) $value;

        return $this->setParticipantsData($participants);
    }

    /**
     * Get list of participants associated with this review.
     * The current author is automatically included.
     *
     * @return  array   list of participants associated with this record
     */
    public function getParticipants()
    {
        return array_keys($this->getParticipantsData());
    }

    /**
     * Set participants associated with this review record.
     * If we have existing entries for any of the specified participants we will persist
     * their properties (e.g. votes) not throw them away.
     *
     * @param   string|array    $participants   list of participants
     * @return  Review          to maintain a fluent interface
     */
    public function setParticipants($participants)
    {
        $participants = array_filter((array) $participants);
        $participants = array_fill_keys($participants, array()) + array($this->get('author') => array());
        $participants = array_intersect_key($this->getParticipantsData(), $participants) + $participants;

        return $this->setRawValue('participants', $this->normalizeParticipants($participants, true));
    }

    /**
     * Get the description of this review.
     *
     * @return  string|null     the review's description
     */
    public function getDescription()
    {
        return $this->getRawValue('description');
    }

    /**
     * Set the description for this review.
     *
     * @param   string|null $description    the new description for this review
     * @return  Review          to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue('description', $description);
    }

    /**
     * Get list of reviewers (all participants excluding the author).
     *
     * @return  array   list of reviewers associated with this record
     */
    public function getReviewers()
    {
        return array_values(array_diff($this->getParticipants(), array($this->get('author'))));
    }

    /**
     * Add one or more participants to this review record.
     *
     * @param   string|array    $participant    participant(s) to add
     * @return  Review          to maintain a fluent interface
     */
    public function addParticipant($participant)
    {
        return $this->setParticipants(
            array_merge($this->getParticipants(), (array) $participant)
        );
    }

    /**
     * Add one or more required reviewers to this review record.
     *
     * @param   string|array    $required    required reviewer(s) to add
     * @return  Review          to maintain a fluent interface
     */
    public function addRequired($required)
    {
        return $this->setParticipantsData(
            array_fill_keys(
                array_merge(
                    array_keys(array_filter($this->getParticipantsData('required'))),
                    (array) $required
                ),
                true
            ),
            'required'
        );
    }

    /**
     * Get list of votes (including stale votes)
     *
     * @return  array   list of votes left of this record
     */
    public function getVotes()
    {
        return $this->getParticipantsData('vote');
    }

    /**
     * Set votes on this review record
     *
     * @param   array   $votes   list of votes
     * @return  Review  to maintain a fluent interface
     */
    public function setVotes($votes)
    {
        return $this->setParticipantsData($votes, 'vote');
    }

    /**
     * This method is used to ensure arrays of changes always contain integers
     *
     * It will make an attempt to cast string integers to real integers,
     * it will detect Change objects and convert them to Change IDs,
     * and failures will be eliminated.
     *
     * @param   array   $changes    the array of Changes/IDs to be normalized
     * @return  array               the normalized array of Change IDs
     */
    protected function normalizeChanges($changes)
    {
        $changes = (array) $changes;

        foreach ($changes as $key => $change) {
            if ($change instanceof Change) {
                $change = $change->getId();
            }

            if (!ctype_digit((string) $change)) {
                unset($changes[$key]);
            } else {
                $changes[$key] = (int) $change;
            }
        }

        return array_values(array_unique($changes));
    }

    /**
     * Add a user's vote to this review record
     *
     * @param   string      $user       userid of the user to add
     * @param   int         $vote       vote (-1/0/1) to associate with the user
     * @param   int|null    $version    optional - version to add vote for
     *                                  defaults to current (head) version
     */
    public function addVote($user, $vote, $version = null)
    {
        $vote = array('value' => (int) $vote, 'version' => $version);
        return $this->setVotes(
            array_merge($this->getVotes(), array($user => $vote))
        );
    }

    /**
     * Returns a list of positive non-stale votes
     *
     * @return  array   list of votes
     */
    public function getUpVotes()
    {
        return array_filter(
            $this->getVotes(),
            function ($vote) {
                return $vote['value'] > 0 && !$vote['isStale'];
            }
        );
    }

    /**
     * Returns a list of negative non-stale votes
     *
     * @return  array   list of votes
     */
    public function getDownVotes()
    {
        return array_filter(
            $this->getVotes(),
            function ($vote) {
                return $vote['value'] < 0 && !$vote['isStale'];
            }
        );
    }

    /**
     * Get list of changes associated with this review.
     * This includes both pending and committed changes.
     *
     * @return  array   list of changes associated with this record
     */
    public function getChanges()
    {
        return $this->normalizeChanges($this->getRawValue('changes'));
    }

    /**
     * Set changes associated with this review record.
     *
     * @param   string|array    $changes    list of changes
     * @return  Review          to maintain a fluent interface
     */
    public function setChanges($changes)
    {
        return $this->setRawValue('changes', $this->normalizeChanges($changes));
    }

    /**
     * Add a change associated with this review record.
     *
     * @param   string  $change     the change to add
     * @return  Review  to maintain a fluent interface
     */
    public function addChange($change)
    {
        $changes   = $this->getChanges();
        $changes[] = $change;
        return $this->setChanges($changes);
    }

    /**
     * Get list of committed changes associated with this review.
     *
     * If a change contributes to this review and is later submitted
     * that won't automatically count. We only count changes which
     * were in a submitted state at the point they updated this review.
     *
     * @return  array   list of commits associated with this record
     */
    public function getCommits()
    {
        return $this->normalizeChanges($this->getRawValue('commits'));
    }

    /**
     * Set list of committed changes associated with this review.
     *
     * See @getCommits for details.
     *
     * @param   string|array    $changes    list of changes
     * @return  Review          to maintain a fluent interface
     */
    public function setCommits($changes)
    {
        $changes = $this->normalizeChanges($changes);

        // ensure all commits are also listed as being changes
        $this->setChanges(
            array_merge($this->getChanges(), $changes)
        );

        return $this->setRawValue('commits', $changes);
    }

    /**
     * Add a commit associated with this review record.
     *
     * @param   string  $change     the commit to add
     * @return  Review  to maintain a fluent interface
     */
    public function addCommit($change)
    {
        $changes   = $this->getCommits();
        $changes[] = $change;
        return $this->setCommits($changes);
    }

    /**
     * Get versions of this review (a version is created anytime files are updated).
     *
     * @return  array   a list of versions from oldest to newest
     *                  each version is an array containing change, user, time and pending
     */
    public function getVersions()
    {
        $versions = (array) $this->getRawValue('versions');

        // if there are no versions and this is an old record (level<2)
        // try fabricating versions from commits + current pending work
        // for pending work, we don't know who actually did it, so we
        // assume it was the review author.
        if (!$versions && $this->get('upgrade') < 2) {
            $versions = array();
            $changes  = array();
            if ($this->getCommits() || $this->isPending()) {
                $changes = $this->getCommits();
                sort($changes, SORT_NUMERIC);
                if ($this->isPending()) {
                    $changes[] = $this->getId();
                }
                $changes = Change::fetchAll(
                    array(Change::FETCH_BY_IDS => $changes),
                    $this->getConnection()
                );
            }

            foreach ($changes as $change) {
                $versions[] = array(
                    'change'  => $change->getId(),
                    'user'    => $change->isSubmitted() ? $change->getUser() : $this->get('author'),
                    'time'    => $change->getTime(),
                    'pending' => $change->isPending()
                );
            }

            // hang on to the fabricated versions so we don't query changes again
            $this->setRawValue('versions', $versions);
        }

        // ensure head rev points to the canonical shelf, but older revs do not.
        $versions = $this->normalizeVersions($versions);

        return $versions;
    }

    /**
     * Set the list of versions. Each element must specify change, user, time and pending.
     *
     * @param   array|null  $versions   the list of versions
     * @return  Review      provides fluent interface
     * @throws  \InvalidArgumentException   if any version doesn't contain change, user, time or pending.
     */
    public function setVersions(array $versions = null)
    {
        $versions = (array) $versions;
        foreach ($versions as $key => $version) {
            if (!isset($version['change'], $version['user'], $version['time'], $version['pending'])) {
                throw new \InvalidArgumentException(
                    "Cannot set versions. Each version must specify a change, user, time and pending."
                );
            }

            // normalize pending to an int for consistency with the review's pending flag.
            $version['pending'] = (int) $version['pending'];
        }

        // ensure head rev points to the canonical shelf, but older revs do not.
        $versions = $this->normalizeVersions($versions);

        return $this->setRawValue('versions', $versions);
    }

    /**
     * Add a version to the list of versions.
     *
     * @param   array   $version    the version details (change, user, time, pending)
     * @return  Review  provides fluent interface
     * @throws  \InvalidArgumentException   if the version doesn't contain change, user, time or pending.
     */
    public function addVersion(array $version)
    {
        $versions   = $this->getVersions();
        $versions[] = $version;

        return $this->setVersions($versions);
    }

    /**
     * Get highest version number.
     *
     * @return  int     max version number
     */
    public function getHeadVersion()
    {
        return count($this->getVersions());
    }

    /**
     * Convenience method to get the revision number for a given change id.
     *
     * @param   int|string|Change   $change     the change to get the rev number of.
     * @return  int                 the rev number of the change or false if no such change version
     */
    public function getVersionOfChange($change)
    {
        $change        = $change instanceof Change ? $change->getId() : $change;
        $versionNumber = false;
        foreach ($this->getVersions() as $key => $version) {
            if ($change == $version['change']
                || (isset($version['archiveChange']) && $change == $version['archiveChange'])
            ) {
                $versionNumber = $key + 1;
            }
        }

        return $versionNumber;
    }

    /**
     * Convenience method to get the change number for a given version.
     *
     * @param   int     $version    the version to get the change number of.
     * @param   bool    $archive    optional - pass true to get the archive change if available
     *                              by default returns the review id for pending head versions
     * @return  int                 the change number of the given version
     * @throws  Exception           if no such version
     */
    public function getChangeOfVersion($version, $archive = false)
    {
        $versions = $this->getVersions();
        if (isset($versions[$version - 1]['change'])) {
            $version = $versions[$version - 1];
            return $archive && isset($version['archiveChange']) ? $version['archiveChange'] : $version['change'];
        }

        throw new Exception("Cannot get change of version $version. No such version.");
    }

    /**
     * Convenience method to get the change number of the latest version.
     *
     * @param   bool    $archive    optional - pass true to get the archive change if available
     *                              by default returns the review id for pending head versions
     * @return  int|null    the change id of the latest version or null if no associated changes
     */
    public function getHeadChange($archive = false)
    {
        $head = end($this->getVersions());
        if (is_array($head) && isset($head['change'])) {
            return $archive && isset($head['archiveChange']) ? $head['archiveChange'] : $head['change'];
        }

        // if no versions, could be a new review that hasn't processed its change
        if ($this->getChanges()) {
            return max($this->getChanges());
        }

        return null;
    }

    /**
     * Convenience method to check if a given version exists.
     *
     * @param   int     $version    the version to check for (one-based)
     * @return  bool    true if the version exists, false otherwise
     */
    public function hasVersion($version)
    {
        $versions = $this->getVersions();
        return $version && isset($versions[$version - 1]);
    }

    /**
     * Get changes associated with this review record which were in a pending
     * state when they were associated with the review.
     *
     * This is a convenience method it calculates the result by diffing
     * the full change list and the committed list.
     *
     * Note, this is a historical representation; just because there are
     * pending changes associated does't mean the review 'isPending'.
     *
     * @return  array   list of changes associated with this record
     */
    public function getPending()
    {
        return array_values(
            array_diff($this->getChanges(), $this->getCommits())
        );
    }

    /**
     * Set this review to pending to indicate it has un-committed files.
     * Ensures the raw value is consistently stored as a 1 or 0.
     *
     * Note: this is not directly related to getPending().
     *
     * @param   bool    $pending    true if pending work is present false otherwise.
     * @return  Review  provides fluent interface
     */
    public function setPending($pending)
    {
        return $this->setRawValue('pending', $pending ? 1 : 0);
    }

    /**
     * This method lets you know if the review has any pending work in the
     * swarm managed change.
     *
     * Note, getPending returns a list of changes that were pending at the
     * time they were associated. It is quite possible getPending would return
     * items but 'isPending' would say no pending work presently exists.
     *
     * @return  bool    true if pending work is present false otherwise.
     */
    public function isPending()
    {
        return (bool) $this->getRawValue('pending');
    }

    /**
     * If the review has at least one committed change associated with it and
     * has no swarm managed pending work we consider it to be committed.
     *
     * @return  bool    true if review is committed false otherwise.
     */
    public function isCommitted()
    {
        return $this->getCommits() && !$this->isPending();
    }

    /**
     * Get the projects this review record is associated with.
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
     * Set the projects (and their associated branches) that are impacted by this review.
     * @see ProjectListFilter for details on input format.
     *
     * @param   array|string    $projects   the projects to associate with this review.
     * @return  Review          provides fluent interface
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
     * @return  Review          provides fluent interface
     */
    public function addProjects($projects)
    {
        $filter = new ProjectListFilter;
        return $this->setRawValue('projects', $filter->merge($this->getRawValue('projects'), $projects));
    }

    /**
     * Get groups this review record is associated with.
     *
     * @return  array   the groups set on this record.
     */
    public function getGroups()
    {
        $groups = (array) $this->getRawValue('groups');
        return array_values(array_unique(array_filter($groups, 'strlen')));
    }

    /**
     * Set the groups that are impacted by this review.
     *
     * @param   array|string    $groups     the groups to associate with this review.
     * @return  Review          provides fluent interface
     */
    public function setGroups($groups)
    {
        $groups = array_values(array_unique(array_filter($groups, 'strlen')));
        return $this->setRawValue('groups', $groups);
    }

    /**
     * Add one or more groups.
     *
     * @param   string|array    $groups   one or more groups
     * @return  Review          provides fluent interface
     */
    public function addGroups($groups)
    {
        return $this->setGroups(array_merge($this->getGroups(), (array) $groups));
    }

    /**
     * Get API token associated with this review and the latest version.
     * Note: A token is automatically created on save if one isn't already present.
     *
     * The token is intended to provide authorization when performing
     * unauthenticated updates to reviews (e.g. setting test status).
     * It also ensures that updates pertain to the latest version.
     *
     * @return  array   the token for this review with a version suffix
     */
    public function getToken()
    {
        return $this->getRawValue('token') . '.v' . $this->getHeadVersion();
    }

    /**
     * Set API token associated with this review. This method would not
     * normally be used; On save a token will automatically be created if
     * one isn't already set on the review.
     *
     * @param   string|null     $token  the token for this review
     * @return  Review          provides fluent interface
     * @throws  \InvalidArgumentException   if token is not a valid type
     */
    public function setToken($token)
    {
        if (!is_null($token) && !is_string($token)) {
            throw new \InvalidArgumentException(
                'Tokens must be a string or null'
            );
        }

        return $this->setRawValue('token', $token);
    }

    /**
     * Get the test details for this code review.
     *
     * @param   bool    $normalize  optional - flag to denote whether we normalize details
     *                              to include version and duration keys, false by default
     * @return  array               test details for this code review
     */
    public function getTestDetails($normalize = false)
    {
        $raw = (array) $this->getRawValue('testDetails');
        return $normalize
            ? $raw + array('version' => null, 'startTimes' => array(), 'endTimes' => array(), 'averageLapse' => null)
            : $raw;
    }

    /**
     * Set the test details for this code review.
     *
     * @param   array|null   $details    test details to set
     */
    public function setTestDetails($details = null)
    {
        return $this->setRawValue('testDetails', (array) $details);
    }

    /**
     * Get the deploy details for this code review.
     *
     * @return  array   test details for this code review
     */
    public function getDeployDetails()
    {
        return (array) $this->getRawValue('deployDetails');
    }

    /**
     * Set the deploy details for this code review.
     *
     * @param   array|null  $details    test details to set
     * @return  Review      to maintain a fluent interface
     */
    public function setDeployDetails($details = null)
    {
        return $this->setRawValue('deployDetails', (array) $details);
    }

    /**
     * Extends the basic save behavior to also:
     * - update hasReviewer value based on presence of 'reviewers'
     * - set create timestamp to current time if no value was provided
     * - create an api token if we don't already have one
     * - set update timestamp to current time
     *
     * @return  Review      to maintain a fluent interface
     */
    public function save()
    {
        // if upgrade level is higher than anticipated, throw hard!
        // if we were to proceed we could do some damage.
        if ((int) $this->get('upgrade') > static::UPGRADE_LEVEL) {
            throw new Exception('Cannot save. Upgrade level is too high.');
        }

        // add author to the list of participants
        $this->addParticipant($this->get('author'));

        // set hasReviewer flag
        $this->set('hasReviewer', $this->getReviewers() ? 1 : 0);

        // if no create time is already set, use now as a default
        $this->set('created', $this->get('created') ?: time());

        // create a token if we don't already have any
        $this->set('token', $this->getRawValue('token') ?: strtoupper(new Uuid));

        // always set update time to now
        $this->set('updated', time());

        return parent::save();
    }

    /**
     * Get the current upgrade level of this record.
     *
     * @return  int|null    the upgrade level when this record was created or last saved
     */
    public function getUpgrade()
    {
        // if this record did not come from a perforce key (ie. storage)
        // assume it was just made and default to the current upgrade level.
        if (!$this->isFromKey && $this->getRawValue('upgrade') === null) {
            return static::UPGRADE_LEVEL;
        }

        return $this->getRawValue('upgrade');
    }

    /**
     * Upgrade this record on save.
     *
     * @param   KeyRecord|null  $stored     an instance of the old record from storage or null if adding
     */
    protected function upgrade(KeyRecord $stored = null)
    {
        // if record is new, default to latest upgrade level
        if (!$stored) {
            $this->set('upgrade', $this->getRawValue('upgrade') ?: static::UPGRADE_LEVEL);
            return;
        }

        // if record is already at the latest upgrade level, nothing to do
        if ((int) $stored->get('upgrade') >= static::UPGRADE_LEVEL) {
            return;
        }

        // looks like we're upgrading - clear 'original' values so all fields get written
        // @todo move this down to abstract key when/if it gets smart enough to detect upgrades
        $this->original = null;

        // upgrade from 0/unset to 1:
        //  - the 'reviewer' field has been removed
        //  - the 'assigned' field has been renamed to 'hasReviewers' and is now a bool of count(reviewers)
        //  - words in the description field are now indexed in lowercase (for case-insensitive matches)
        //    with leading/trailing punctuation removed and using a slightly different split pattern.
        if ((int) $stored->get('upgrade') === 0) {
            unset($this->values['reviewer']);
            unset($this->values['assigned']);

            // need to de-index old 'assigned' field (can only have two possible values 0/1)
            $this->getConnection()->run(
                'index',
                array('-a', 1305, '-d', $this->id),
                '30 31'
            );
            $stored->set('hasReviewer', null);

            // need to de-index description the old way
            $words = array_unique(array_filter(preg_split('/[\s,]+/', $stored->get('description')), 'strlen'));
            if ($words) {
                $this->getConnection()->run(
                    'index',
                    array('-a', 1306, '-d', $this->id),
                    implode(' ', array_map('strtoupper', array_map('bin2hex', $words))) ?: 'EMPTY'
                );

                // clear old value to force re-indexing of non-empty descriptions.
                $stored->set('description', null);
            }
            $this->set('upgrade', 1);
        }

        // upgrade to 2
        //  - versions field has been introduced, get/set it to tickle upgrade code
        if ((int) $stored->get('upgrade') < 2) {
            $this->setVersions($this->getVersions());
            $this->set('upgrade', 2);
        }

        // upgrade to 3
        //  - votes merged into participants field, get/set it to tickle upgrade
        if ((int) $stored->get('upgrade') < 3) {
            $this->setParticipantsData($this->getParticipantsData());
            $this->set('upgrade', 3);
        }

        // upgrade to 4
        //  - votes expanded to array with 'value' and 'version' keys, get/set it to tickle upgrade
        if ((int) $stored->get('upgrade') < 4) {
            $this->setVotes($this->getVotes());
            $this->set('upgrade', 4);
        }
    }

    /**
     * Get topic for this review (used for comments).
     *
     * @return  string  topic for this review
     * @todo    add a getTopics which includes the associated change topics
     */
    public function getTopic()
    {
        return 'reviews/' . $this->getId();
    }

    /**
     * Try to fetch the associated author user as a user spec object.
     *
     * @return  User    the associated author user object
     * @throws  NotFoundException   if user does not exist
     */
    public function getAuthorObject()
    {
        return $this->getUserObject('author');
    }

    /**
     * Check if the associated author user is valid (exists).
     *
     * @return  bool    true if the author user exists, false otherwise.
     */
    public function isValidAuthor()
    {
        return $this->isValidUser('author');
    }

    /**
     * Get a human-friendly label for the current state.
     *
     * @return string
     */
    public function getStateLabel()
    {
        $state = $this->get('state');
        return ucfirst(preg_replace('/([A-Z])/', ' \\1', $state));
    }

    /**
     * Get a list of valid transitions for this review.
     *
     * @return  array   a list with target states as keys and transition labels as values
     */
    public function getTransitions()
    {
        $translator  = $this->getConnection()->getService('translator');
        $transitions = array(
            static::STATE_NEEDS_REVIEW         => $translator->t('Needs Review'),
            static::STATE_NEEDS_REVISION       => $translator->t('Needs Revision'),
            static::STATE_APPROVED             => $translator->t('Approve'),
            static::STATE_APPROVED . ':commit' => $translator->t('Approve and Commit'),
            static::STATE_REJECTED             => $translator->t('Reject'),
            static::STATE_ARCHIVED             => $translator->t('Archive')
        );

        // exclude current state
        unset($transitions[$this->get('state')]);

        // exclude approve and commit if we lack pending work or are already committing
        if (!$this->isPending() || $this->isCommitting()) {
            unset($transitions[static::STATE_APPROVED . ':commit']);
        }

        // if we are pending but already approved tweak the approve
        // and commit wording to just say 'Commit'
        if ($this->isPending() && $this->get('state') == static::STATE_APPROVED) {
            $transitions[static::STATE_APPROVED . ':commit'] = 'Commit';
        }

        return $transitions;
    }

    /**
     * Deletes the current review and attempts to remove indexes.
     * Extends parent to also delete the swarm managed shelf.
     *
     * @return  Review      to maintain a fluent interface
     * @throws  Exception   if no id is set
     * @throws  \Exception  rethrows any exceptions caused during delete
     * @todo    remove archive changes as well as canonical change
     */
    public function delete()
    {
        if (!$this->getId()) {
            throw new Exception(
                'Cannot delete review, no ID has been set.'
            );
        }

        // attempt to get the associated shelved change we manage
        // if no such change exists, just let parent delete this record
        $p4 = $this->getConnection();
        try {
            $shelf = Change::fetch($this->getId(), $p4);
        } catch (NotFoundException $e) {
            return parent::delete();
        }

        if ($shelf->isSubmitted()) {
            throw new Exception(
                'Cannot delete review; the shelved change we manage is unexpectedly committed.'
            );
        }

        // we'll need a valid client for this next bit.
        $p4->getService('clients')->grab();
        try {
            // try and hard reset the client to ensure a clean environment
            $p4->getService('clients')->reset(true, $this->getHeadStream());

            // if the shelf associated with this review isn't already on
            // the right client, likely won't be, swap it over and save.
            if ($shelf->getClient() != $p4->getClient() || $shelf->getUser() != $p4->getUser()) {
                $shelf->setClient($p4->getClient())->setUser($p4->getUser())->save(true);
            }

            // attempt to delete any shelved files off the swarm managed change
            // silence the expected exception that occurs when no shelved files were present
            try {
                $p4->run('shelve', array('-d', '-f', '-c', $this->getId()));
            } catch (CommandException $e) {
                if (strpos($e->getMessage(), 'No shelved files in changelist to delete.') === false) {
                    throw $e;
                }
                unset($e);
            }

            // now that the shelved files are gone try and delete the actual change
            $p4->run("change", array("-d", "-f", $this->getId()));
        } catch (\Exception $e) {
        }
        $p4->getService('clients')->release();

        if (isset($e)) {
            throw $e;
        }

        // let parent wrap up by deleting the key record and indexes
        return parent::delete();
    }

    /**
     * Attempts to figure out what stream (if any) the head version of this review
     * is against. Useful for committing the work as you'll need to be on said stream.
     *
     * @return null|string  the streams path as a string, if we can identify one, otherwise null
     */
    protected function getHeadStream()
    {
        // try to determine the stream we aught to use from the version history
        $version = end($this->getVersions());
        if (array_key_exists('stream', $version)) {
            return $version['stream'];
        }

        // if its not recorded and the head version is a pending change
        // we can try to guess the stream from the shelved file paths.
        if (isset($version['change'], $version['pending']) && $version['pending']) {
            return $this->guessStreamFromChange($version['change']);
        }

        // looks like we don't have a clue; lets assume not a stream
        return null;
    }

    /**
     * Checks the first file in a change to see if it points to a streams depot.
     * Note, this check may not work reliably on streams with writable imports.
     *
     * @param   int|string|Change   $change     the change to look at for our guess
     * @return  null|string         the streams path as a string, if we can identify one, otherwise null
     */
    protected function guessStreamFromChange($change)
    {
        $p4     = $this->getConnection();
        $change = $change instanceof Change ? $change : Change::fetch($change, $p4);
        $id     = $change->getId();
        $flags  = $change->isPending() ? array('-Rs') : array();
        $flags  = array_merge($flags, array('-e', $id, '-m1', '-T', 'depotFile', '//...@=' . $id));
        $result = $p4->run('fstat', $flags);
        $file   = $result->getData(0, 'depotFile');

        // if the change is empty, we can't do the check
        if ($file === false) {
            return null;
        }

        // grab the depot off the first file and check if it points to a stream depot
        // if so, return the //<depot> followed by path components equal to stream depth (this
        // field is present only on new servers, on older ones we take just the first one)
        $pathComponents = array_filter(explode('/', $file));
        $depot          = Depot::fetch(current($pathComponents), $p4);
        if ($depot->get('Type') == 'stream') {
            $depth = $depot->hasField('StreamDepth') ? $depot->getStreamDepth() : 1;
            return count($pathComponents) > $depth
                ? '//' . implode('/', array_slice($pathComponents, 0, $depth + 1))
                : null;
        }

        return null;
    }

    /**
     * Synchronizes the current review's description as well as the descriptions of any associated changes.
     *
     * @param   string           $reviewDescription  the description to use for the review (review keywords stripped)
     * @param   string           $changeDescription  the description to use for the change (review keywords intact)
     * @param   Connection|null  $connection         the perforce connection to use - should be p4 admin, since the
     *                                               current user may not own all the associated changes
     * @return  bool             true if the review description was modified, false otherwise
     */
    public function syncDescription($reviewDescription, $changeDescription, $connection = null)
    {
        $wasModified = false;

        // update the review with the new review description, if needed
        if ($this->getDescription() != $reviewDescription) {
            $this->setDescription($reviewDescription)->save();

            // since we changed the description, we've modified this review
            $wasModified = true;
        }

        // update descriptions for all changes associated with the review
        try {
            $connection = $connection ?: $this->getConnection();
            $connection->getService('clients')->grab();
            foreach ($this->getChanges() as $changeId) {
                $change = Change::fetch($changeId, $connection);

                // note: we only want to save the change if the description was changed, since this will trigger
                // an infinite number of changesave events otherwise
                if ($change->getDescription() != $changeDescription) {
                    $change->setDescription($changeDescription)
                           ->save(true);
                }
            }
        } catch (\Exception $e) {
            Logger::log(Logger::ERR, $e);
        }

        $connection->getService('clients')->release();
        return $wasModified;
    }

    /**
     * Try to fetch the associated user (for given field) as a user spec object.
     *
     * @param   string  $userField  name of the field to get user object for
     * @return  User    the associated user object
     * @throws  NotFoundException   if user does not exist
     */
    protected function getUserObject($userField)
    {
        if (!isset($this->userObjects[$userField])) {
            $this->userObjects[$userField] = User::fetch(
                $this->get($userField),
                $this->getConnection()
            );
        }

        return $this->userObjects[$userField];
    }

    /**
     * Check if the associated user (for given field) is valid (exists).
     *
     * @param   string  $userField  name of the field to check user for
     * @return  bool    true if the author user exists, false otherwise.
     */
    protected function isValidUser($userField)
    {
        try {
            $this->getUserObject($userField);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * Override parent to prepare 'project' field values for indexing.
     *
     * @param   int                 $code   the index code/number of the field
     * @param   string              $name   the field/name of the index
     * @param   string|array|null   $value  one or more values to index
     * @param   string|array|null   $remove one or more old values that need to be de-indexed
     * @return  Review              provides fluent interface
     */
    protected function index($code, $name, $value, $remove)
    {
        // convert 'projects' field values into the form suitable for indexing
        // we index projects by project-id, but also by project-id:branch-id.
        if ($name === 'projects') {
            $value  = array_merge(array_keys((array) $value),  static::flattenForIndex((array) $value));
            $remove = array_merge(array_keys((array) $remove), static::flattenForIndex((array) $remove));
        }

        return parent::index($code, $name, $value, $remove);
    }

    /**
     * Called when an auto-generated ID is required for an entry.
     *
     * Extends parent to create a new changelist and use its change id
     * as the identifier for the review record.
     *
     * @return  string      a new auto-generated id. the id will be 'encoded'.
     * @throws  \Exception  re-throws any errors which occur during change save operation
     */
    protected function makeId()
    {
        $p4     = $this->getConnection();
        $shelf  = new Change($p4);
        $shelf->setDescription($this->get('description'));

        // we grab the client tightly around save to avoid
        // locking it for any longer than we have to.
        $p4->getService('clients')->grab();
        try {
            $shelf->save();
        } catch (\Exception $e) {
        }
        $p4->getService('clients')->release();

        if (isset($e)) {
            throw $e;
        }

        return $this->encodeId($shelf->getId());
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

    /**
     * Produces a 'p4 search' expression for the given field/value pairs.
     *
     * Extends parent to allow including pending status in the state filter.
     * The syntax is <state>:(isPending|notPending) e.g.:
     * approved:notPending
     *
     * @param   array   $conditions     field/value pairs to search for
     * @return  string  a query expression suitable for use with p4 search
     */
    protected static function makeSearchExpression($conditions)
    {
        // normalize conditions and pull out the 'states' for us to deal with
        $conditions += array(static::FETCH_BY_STATE => '');
        $states      = $conditions[static::FETCH_BY_STATE];

        // start by letting parent handle all other fields
        unset($conditions[static::FETCH_BY_STATE]);
        $expression = parent::makeSearchExpression($conditions);

        // go over all state(s) and utilize parent to build expression for the state and
        // optional isPending/notPending field. We do them one at a time to allow us to
        // bracket the output when the expression has both state and pending.
        $expressions = array();
        foreach ((array) $states as $state) {
            $conditions = array();
            $parts      = explode(':', $state);

            // if state appears to contain an isPending or notPending component split it
            // into separate state and pending conditions, otherwise simply keep it as is.
            if (count($parts) == 2 && ($parts[1] == 'isPending' || $parts[1] == 'notPending')) {
                $conditions[static::FETCH_BY_STATE] = $parts[0];
                $conditions['pending']              = $parts[1] == 'isPending' ? 1 : 0;
            } else {
                $conditions[static::FETCH_BY_STATE] = $state;
            }

            // use parent to make the state's expression then add it to the pile and
            // bracket it if we asked for both the state and pending filter
            $state = parent::makeSearchExpression($conditions);
            $expressions[] = count($conditions) > 1 ? '(' . $state . ')' : $state;
        }

        // now that we've collected up all the state expressions, implode and bracket
        // the whole thing if more than one state's involved
        $states      = implode(' | ', $expressions);
        $expression .= ' ' . (count($expressions) > 1 ? '(' . $states . ')' : $states);

        return trim($expression);
    }

    /**
     * Turn the passed key into a record.
     * Extends parent to detect review type and create the appropriate review class.
     *
     * @param   Key             $key        the key to 'record'ize
     * @param   string|callable $className  optional - class name to use, static by default
     * @return  Review  the record based on the passed key's data
     */
    protected static function keyToModel($key, $className = null)
    {
        return parent::keyToModel(
            $key,
            $className ?: function ($data) {
                // if the data includes a type of git; make a git model
                // otherwise create a standard review.
                return isset($data['type']) && $data['type'] === 'git'
                    ? '\Reviews\Model\GitReview'
                    : '\Reviews\Model\Review';
            }
        );
    }

    /**
     * Copy files and description to a new shelved change and add a version entry.
     *
     * We use shelved changes for versioning so that users can un-shelve old versions
     * and so that the rest of our diff/etc. code works with them seamlessly.
     *
     * This method is only intended to be called from updateFromChange().
     *
     * @param   Change  $shelf              the shelved change to archive files from
     * @param   array   $versionDetails     extra details to include in version entry, e.g. difference => true
     * @return  Review  provides fluent interface
     */
    protected function archiveShelf(Change $shelf, $versionDetails)
    {
        // make a new change matching the shelf's type and description.
        $p4     = $this->getConnection();
        $change = new Change($p4);
        $change->setType($shelf->getType())
               ->setDescription($shelf->getDescription())
               ->save();

        // to avoid any ambiguity when the shelve-commit trigger fires we add the
        // new archive change/version to the review record before we shelve
        $version = $versionDetails + array(
            'change'     => $change->getId(),
            'user'       => $shelf->getUser(),
            'time'       => time(),
            'pending'    => true
        );
        $this->addVersion($version)
             ->addChange($change->getId())
             ->save();

        // now we can move the files into our archive change and shelve them.
        $p4->run('reopen', array('-c', $change->getId(), '//...'));
        $p4->run('shelve', array('-c', $change->getId()));

        return $this;
    }

    /**
     * Rescue files from a pre-versioning review (upgrade scenario).
     *
     * Copy files and description to a new shelved change and update the latest
     * version in our versions metadata to point to the new change.
     *
     * This method is only intended to be called from updateFromChange().
     *
     * @param   Change  $shelf  the canonical shelved change to archive files from
     * @return  Review  provides fluent interface
     * @todo    centralize this more robust unshelve logic and use it elsewhere
     */
    protected function retroactiveArchive(Change $shelf)
    {
        // determine if we have any files to archive
        // we expect some files may fail to unshelve (this happens on <13.1
        // servers with added files that are now submitted) we capture these
        // files and sync/edit/print them manually to save the file contents
        $p4      = $this->getConnection();
        $result  = $p4->run('unshelve', array('-s', $shelf->getId()));
        $opened  = 0;
        $failed  = array();
        $pattern = "/^Can't unshelve (.*) to open for [a-z\/]+: file already exists.$/";
        foreach ($result->getData() as $data) {
            if (is_array($data)) {
                $opened++;
            } elseif (preg_match($pattern, $data, $matches)) {
                $failed[] = $matches[1];
            }
        }

        // if there were no files to unshelve, exit early.
        if (!$opened && !$failed) {
            return $this;
        }

        // emulate unshelve for out-dated adds on <13.1 servers
        if ($failed) {
            $p4->run('sync', array_merge(array('-k'), $failed));
            $p4->run('edit', array_merge(array('-k'), $failed));
            foreach ($failed as $file) {
                $local = $p4->run('where', $file)->getData(0, 'path');
                $p4->run('print', array('-o', $local, $file . '@=' . $shelf->getId()));
            }
        }

        // now that we know we have files to rescue - make a new change for them.
        $change = new Change($p4);
        $change->setType($shelf->getType())
               ->setDescription($shelf->getDescription())
               ->save();

        // to avoid any ambiguity when the shelve-commit trigger fires we add the
        // new archive change/version to the review record before we shelve
        $versions = $this->getVersions();
        $versions[count($versions) - 1]['archiveChange'] = $change->getId();
        $this->setVersions($versions)
             ->addChange($change->getId())
             ->save();

        // now we can move the files into our archive change and shelve them.
        $p4->run('reopen', array('-c', $change->getId(), '//...'));
        $p4->run('shelve', array('-c', $change->getId()));

        // shelving leaves files open in the workspace, we need to clean those up
        // otherwise they will interfere with updating the canonical shelf later
        $p4->getService('clients')->clearFiles();

        return $this;
    }

    /**
     * Pending head revisions are stored twice, once in the canonical shelf and again in an archive shelf.
     * This method ensures the head version points to the canonical shelf, but older versions do not.
     *
     * @param   array   $versions   the list of versions to normalize
     * @return  array   the normalized versions with head/non-head change issues sorted
     */
    protected function normalizeVersions(array $versions)
    {
        $last = end(array_keys($versions));
        foreach ($versions as $key => $version) {
            // if we see a pending head rev that does not point to the canonical shelf,
            // update it to point there and capture the archive change for later use.
            if ($version['pending'] && $version['change'] != $this->getId() && $key == $last) {
                $versions[$key]['archiveChange'] = $version['change'];
                $versions[$key]['change']        = $this->getId();
            }

            // if we find a non-head rev that points to the canonical shelf, update it
            // to reference the archive change or drop it if it has no archive change
            // if it has no archive change, it is most likely cruft from the upgrade code
            if ($version['change'] == $this->getId() && $key != end(array_keys($versions))) {
                if (isset($version['archiveChange'])) {
                    $versions[$key]['change'] = $version['archiveChange'];
                    unset($versions[$key]['archiveChange']);
                } else {
                    unset($versions[$key]);
                }
            }
        }

        return array_values($versions);
    }

    /**
     * Determine if files in the given changes (pending or submitted) are different in any meaningful way.
     * We compare following properties:
     *  - file names
     *  - file contents (digests)
     *  - file types
     *  - actions
     *  - working (head) revs
     *  - resolved/unresolved states
     * and return an integer based on the results:
     *  0 if changes don't differ in any of compared properties
     *  1 if any file names, contents or types differ
     *  2 if changes differ in any other compared properties.
     *
     * @param   Change|int  $a  pending or submitted change to compare
     * @param   Change|int  $b  pending or submitted change to compare
     * @return  int         0 if changes don't differ
     *                      1 if changes differ in file names, types or digests
     *                      2 if changes differ in any other compared fields
     */
    protected function changesDiffer($a, $b)
    {
        $p4  = $this->getConnection();
        $a   = $a instanceof Change ? $a : Change::fetch($a, $p4);
        $b   = $b instanceof Change ? $b : Change::fetch($b, $p4);
        $aId = $a->getId();
        $bId = $b->getId();

        $flags  = array(
            '-Ol',  // include digests
            '-T',   // only the fields we want:
            'depotFile,headAction,headType,headRev,resolved,unresolved,digest'
        );

        // add '-Rs' flag for pending changes
        $flagsA = array_merge($a->isPending() ? array('-Rs') : array(), $flags);
        $flagsB = array_merge($b->isPending() ? array('-Rs') : array(), $flags);

        $a = $p4->run(
            'fstat',
            array_merge(array('-e', $a->getId()), $flagsA, array('//...@=' . $a->getId()))
        );
        $b = $p4->run(
            'fstat',
            array_merge(array('-e', $b->getId()), $flagsB, array('//...@=' . $b->getId()))
        );

        // remove trailing change descriptions - we don't care if they differ
        $a = $a->getData(-1, 'desc') !== false ? array_slice($a->getData(), 0, -1) : $a->getData();
        $b = $b->getData(-1, 'desc') !== false ? array_slice($b->getData(), 0, -1) : $b->getData();

        if ($a == $b) {
            return 0;
        }

        // the fstat reported digests for ktext files are not what we want.
        // they are based on the text with keywords expanded which is apt to harmlessly flux.
        // if it looks worthwhile, we want to recalculate md5s without expansion.
        if ($this->shouldFixDigests($a, $b)) {
            $a = $this->fixKeywordExpandedDigests($a, $aId);
            $b = $this->fixKeywordExpandedDigests($b, $bId);
        }

        // our ktext related md5 updates may have cleared the difference; if so we're done!
        if ($a == $b) {
            return 0;
        }

        // screen down to only the 'major' difference fields
        $whitelist = array('depotFile' => null, 'headType' => null, 'digest' => null);
        foreach ($a as $block => $data) {
            $a[$block] = array_intersect_key($data, $whitelist);
        }
        foreach ($b as $block => $data) {
            $b[$block] = array_intersect_key($data, $whitelist);
        }

        // if the data are same now, it means that differences must have been within
        // action, revs or resolved/unresolved; otherwise changes must differ in other fields
        return $a == $b ? 2 : 1;
    }

    /**
     * This is a helper method for changesDiffer. We determine if touching up keyword expanded
     * digests is worthwhile.
     *
     * @param   array   $a  fstat output with list of files to potentially update for old change
     * @param   array   $b  fstat output with list of files to potentially update for new change
     * @return  bool    true if calling fixKeywordExpandedDigests is likely worthwhile, false otherwise
     */
    protected function shouldFixDigests($a, $b)
    {
        // differing counts means changesDiffer will always report 1; no need to fix digests
        if (count($a) != count($b)) {
            return false;
        }

        // index all 'b' blocks by depotFile so we can correlate them later
        $bByFile = array();
        foreach ($b as $key => $block) {
            if (isset($block['depotFile'])) {
                $bByFile[$block['depotFile']] = $block;
            }
        }

        $hasKtext  = false;
        $normalize = array('depotFile' => null, 'digest' => null, 'headType' => null);
        foreach ($a as $blockA) {
            // if the 'b' set doesn't include this file, no need to fix digests
            $blockA += $normalize;
            $file    = $blockA['depotFile'];
            if (!isset($bByFile[$file])) {
                return false;
            }
            $blockB = $bByFile[$file] + $normalize;

            // if type has changed on any file, no need to fix digests
            if ($blockA['headType'] != $blockB['headType']) {
                return false;
            }

            // if a single non-ktext file has a changed digest, no need to fixup
            $isKtext = preg_match('/kx?text|.+\+.*k/i', $blockA['headType']);
            if (!$isKtext && $blockA['digest'] != $blockB['digest']) {
                return false;
            }

            // track if we've hit any ktext files
            $hasKtext = $hasKtext || $isKtext;
        }

        // if we made it this far, fixing ktext digests is likely worthwhile if we've seen any
        return $hasKtext;
    }

    /**
     * This is a helper method for changesDiffer. We get passed in the fstat output for one
     * of the changes being examined and locate any ktext files located in it. We then print
     * all of the ktext files and recalculate the md5 values with the keywords not expanded.
     *
     * This will allow the changes differ method to tell if the ktext files fundamentally
     * differ (as opposed to simply differ in the expanded keywords).
     *
     * @param   array   $blocks     fstat output with list of files to potentially update
     * @param   int     $changeId   change id to use for revspec when printing files
     * @return  array   the provided blocks array with ktext digests updated
     */
    protected function fixKeywordExpandedDigests($blocks, $changeId)
    {
        // we cannot do squat on pre 2012.2 servers as they don't support printing with
        // keywords unexpanded. if we're on an old server, simply return.
        $p4 = $this->getConnection();
        if (!$p4->isServerMinVersion('2012.2')) {
            return $blocks;
        }

        // first collect the key and depotPath for all ktext entries and a list of filespecs with revspec
        $ktexts    = array();
        $filespecs = array();
        foreach ($blocks as $block => $data) {
            // note ktext filetypes include things like: ktext, text+ko, text+mko, kxtext, etc.
            if (isset($data['headType'], $data['depotFile']) && preg_match('/kx?text|.+\+.*k/i', $data['headType'])) {
                $file          = $data['depotFile'];
                $ktexts[$file] = $block;
                $filespecs[]   = $file . '@=' . $changeId;
            }
        }

        // if we didn't detect any ktext files we need to update, we're done!
        if (!$filespecs) {
            return $blocks;
        }

        // now setup an output handler to process the print output for all ktext files (with keywords unexpanded)
        // and do a streaming calculation of the md5 for all ktext files
        $file    = null;
        $hash    = null;
        $handler = new Limit;
        $handler->setOutputCallback(
            function ($data, $type) use (&$blocks, &$file, &$hash, $ktexts) {
                // if its an array with depotFile; we're swapping files
                if (is_array($data) && isset($data['depotFile'])) {
                    // if we were already on a file, finalize its hash update
                    if ($file !== null) {
                        $blocks[$ktexts[$file]]['digest'] = hash_final($hash);
                    }

                    // record the new file we're on and (re)init the streaming hash
                    $file = $data['depotFile'];
                    $hash = hash_init('md5');
                    return Limit::HANDLER_HANDLED;
                }

                // if we have an unexpected type, skip it
                if ($type !== 'text' && $type !== 'binary') {
                    return Limit::HANDLER_HANDLED;
                }

                // update the hash with our new block of content
                hash_update($hash, $data);

                return Limit::HANDLER_HANDLED;
            }
        );

        // print via our handler, note we pass -k to avoid expanding keywords
        // thanks to our output handler this will update the digest values in the $blocks array
        $p4->runHandler($handler, 'print', array_merge(array('-k'), $filespecs));

        // we're likely to have a final file to wrap up the hash update on, do that
        if ($file) {
            $blocks[$ktexts[$file]]['digest'] = hash_final($hash);
        }

        return $blocks;
    }

    /**
     * General normalization of participants data.
     *
     * @param   array|null  $participants   the participants array to normalize
     * @param   bool        $forStorage     optional - flag to denote whether we normalize for storage
     *                                      passed to normalizeVote(), false by default
     * @return  array       normalized participants data
     */
    protected function normalizeParticipants($participants, $forStorage = false)
    {
        // - ensure value is an array
        // - ensure each entry is an array
        // - ensure the author is always present
        // - ensure we're sorted by user id
        // - ensure properties are sorted by key
        // - drop empty properties, at present we only store votes/required and
        //   its a waste of space (and less normalized) to store empty versions
        $participants  = array_filter((array) $participants, 'is_array');
        $participants += array($this->get('author') => array());
        uksort($participants, 'strnatcasecmp');

        foreach ($participants as $id => $participant) {
            $participant        += array('vote' => array());
            $participant['vote'] = $this->normalizeVote($id, $participant['vote'], $forStorage);
            $participants[$id]   = array_filter($participant);

            uksort($participants[$id], 'strnatcasecmp');
        }

        return $participants;
    }

    /**
     * If we were passed vote with valid 'value', we will ensure 'version' and 'isStale' is also present
     * ('isStale' is always recalculated).
     * If a non-array is passed, we will move the passed value under the 'value' key.
     * If no version is present, we will set the version to head.
     *
     * @param   string          $user           user of the vote
     * @param   array|string    $vote           vote to normalize
     * @param   bool            $forStorage     flag to denote whether we normalize for storage or not
     *                                          false by default; if true, then 'isStale' property will
     *                                          not be included
     * @return  array|false     normalized vote as array with 'value', 'version' and optionaly
     *                          'isStale' keys or false if 'value' was invalidor user is the author
     */
    protected function normalizeVote($user, $vote, $forStorage)
    {
        // for non-array, shift the input under the 'value' key
        $vote = is_array($vote) ? $vote : array('value' => $vote);

        // if the user is the author or the vote is missing/invalid bail
        if ($user === $this->get('author') || !isset($vote['value']) || !in_array($vote['value'], array(1, -1))) {
            return false;
        }

        if (!isset($vote['version']) || !ctype_digit((string) $vote['version'])) {
            $vote['version'] = $this->getHeadVersion();
        }
        $vote['version'] = (int) $vote['version'];

        if ($forStorage) {
            unset($vote['isStale']);
        } else {
            $vote['isStale'] = $this->isStaleVote($vote);
        }

        return $vote;
    }

    /**
     * If the vote is out-dated and a newer version of the review has file changes, the vote is stale.
     * Otherwise you have voted on the same files as the latest version, so the vote is not stale.
     *
     * @param   array   $vote   vote to check
     * @return  boolean         true if vote is stale, false otherwise
     */
    protected function isStaleVote(array $vote)
    {
        // loop over the versions, oldest to newest
        $votedOn = isset($vote['version']) ? (int) $vote['version'] : 0;
        foreach ($this->getVersions() as $key => $version) {
            // skip old versions and the version voted on
            // note key starts at zero, votedOn starts at 1
            if ($key < $votedOn) {
                continue;
            }

            // if 'difference' isn't present or its invalid, assume its different and return stale
            if (!isset($version['difference'])
                || !ctype_digit((string) $version['difference'])
                || !in_array($version['difference'], array(0, 1, 2))
            ) {
                return true;
            }

            // return stale if significant change occured, otherwise keep scanning
            // 0 - no changes, 1 - modified name, type or digest, 2 - modified only insignificant fields
            if ($version['difference'] == 1) {
                return true;
            }
        }

        // the vote is not stale
        return false;
    }

    /**
     * Check for files that cannot be opened because they are already exclusively open.
     * We need an explicit check for this because it is not reported as an error or a warning.
     *
     * @param   CommandResult  $result  the command output to examine
     * @throws  Exception      if any of the files are already open exclusively elsewhere
     */
    protected function exclusiveOpenCheck(CommandResult $result)
    {
        foreach ($result->getData() as $block) {
            if (is_string($block) && strpos($block, 'exclusive file already opened')) {
                throw new Exception(
                    'Cannot unshelve review (' . $this->getId() . '). ' .
                    'One or more files are exclusively open. ' .
                    'Ensure you have Perforce Server version 2014.2/1073410+ ' .
                    'with the filetype.bypasslock configurable enabled.'
                );
            }
        }
    }

    /**
     * Check if the server we are talking to supports bypassing +l
     *
     * @return  bool  true if the server is newer than 2014.2/1073410
     */
    protected function canBypassLocks()
    {
        $p4       = $this->getConnection();
        $identity = $p4->getServerIdentity();

        return $p4->isServerMinVersion('2014.2') && $identity['build'] >= 1073410;
    }
}
