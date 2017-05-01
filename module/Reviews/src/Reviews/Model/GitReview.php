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
use P4\Spec\Change;
use Record\Exception\Exception;
use Record\Key\AbstractKey as KeyRecord;
use Reviews\Filter\GitInfo;

class GitReview extends Review
{
    protected $versionsCache = array();

    /**
     * Instantiate the model and set the connection to use.
     * Extends parent to force type to 'git' and add/tweak some fields.
     *
     * @param   Connection  $connection     optional - a connection to use for this instance.
     */
    public function __construct(Connection $connection = null)
    {
        parent::__construct($connection);
        $this->setRawValue('type', 'git');

        // git versions are based on system state and are expensive to get so we:
        // - set read-only as they are based on system state outside our control
        // - set them as unstored as the data is derived dynamically
        $this->fields['versions']['readOnly'] = true;
        $this->fields['versions']['unstored'] = true;
    }

    /**
     * Get versions of this review.
     *
     * At present, this is the various 'commits' against the target branch associated to the review
     * as well as the git-fusion managed merge shelf. Note the commits present in the light weight
     * branch git-fusion manages are _not_ presently included (as their diffs would display strangely).
     *
     * @return  array   versions for this review
     * @throws  \RuntimeException   if no id has been set of the git-fusion shelf cannot be fetched
     */
    public function getVersions()
    {
        // verify we have an id, this won't really work without one
        if (!$this->getId()) {
            throw new \RuntimeException('Cannot retrieve versions, no id has been set.');
        }

        // determine all commits (in order) and the merge shelf for this review
        $changes   = $this->getCommits();
        sort($changes, SORT_NUMERIC);
        $changes[] = $this->getId();

        // if we don't already have a cache of the change details fetch them
        $cacheKey = md5(serialize($changes));
        if (!isset($this->versionsCache[$cacheKey])) {
            $changes = Change::fetchAll(
                array(Change::FETCH_BY_IDS => $changes),
                $this->getConnection()
            );

            // ensure the git-fusion shelf was fetched, we need it at the least
            if (!isset($changes[$this->getId()])) {
                throw new \RuntimeException('Cannot retrieve versions, unable to fetch the git-fusion shelf.');
            }

            // throw the result into cache for this and future calls
            $this->versionsCache[$cacheKey] = $changes;
        }

        // we know the cache is populated, pull out a reference and carry on
        $changes = $this->versionsCache[$cacheKey];

        // start by building out version entries for all commits
        $versions = array();
        foreach ($changes as $change) {
            if ($change->getId() != $this->getId()) {
                $versions[] = array(
                    'change'    => $change->getId(),
                    'user'      => $change->getUser(),
                    'time'      => $change->getTime(),
                    'pending'   => false
                );
            }
        }

        // determine what time the shelf was last updated
        $gitInfo   = new GitInfo($changes[$this->getId()]->getDescription());
        $committer = $gitInfo->get('Committer') ?: '';
        $shelfTime = current(array_slice(explode(' ', $committer), -2, 1));
        $shelfTime = max($shelfTime, $this->get('created'));

        // build out the 'shelf' entry
        $shelf = array(
            'change'    => $this->getId(),
            'user'      => $this->get('author'),
            'time'      => $shelfTime,
            'pending'   => $changes[$this->getId()]->isPending()
        );

        // determine the position to inject the merge shelf
        // if we are pending, we know the shelf comes last (most recent).
        // if we are _not_ pending we know we can't be last and we'll try to figure
        // out our position by looking at the shelf's update time v.s. the various
        // involved commits.
        if ($this->isPending()) {
            $versions[] = $shelf;
        } else {
            for ($i = 0; $i < count($versions) - 1; $i++) {
                if ($versions[$i]['time'] > $shelfTime) {
                    break;
                }
            }
            array_splice($versions, $i, 0, array($shelf));
        }

        return $versions;
    }

    /**
     * Versions are read-only so this method simply throws an exception if called.
     *
     * @param   array|null  $versions   the list of versions, no value is acceptable
     * @return  Review      provides fluent interface
     * @throws  \RuntimeException   for any call
     */
    public function setVersions(array $versions = null)
    {
        throw new \RuntimeException('Git review versions are read-only.');
    }

    /**
     * Extends parent to determine highest version number without the expensive calling
     * getVersions().
     *
     * @return  int     max version number
     */
    public function getHeadVersion()
    {
        $versions   = $this->getCommits();
        $versions[] = $this->getId();
        return count(array_unique($versions));
    }

    /**
     * Convenience method to get the change number of the latest version.
     * Extends parent to figure this out without calling getVersions.
     *
     * @param   bool        $archive    this parameter has no effect for git reviews
     * @return  int         the change id of the latest version
     * @throws  Exception   if no versions and no changes are set on this review
     */
    public function getHeadChange($archive = false)
    {
        if (!$this->getId() && !$this->getCommits()) {
            throw new Exception("Cannot get head change. This review has no commits and no id.");
        }

        // if we are pending the git-fusion managed change is the head change
        if ($this->isPending()) {
            return (int) $this->getId();
        }

        // we are committed, just return the most recent commit
        return max($this->getCommits());
    }

    /**
     * Return new review instance populated from the given change.
     * Extends parent to ensure only git-fusion review changes are accepted and to
     * turn the provided change into a review (instead of the base behaviour of
     * creating a new review change for the effort).
     *
     * @param   Change|string   $change     git-fusion review shelf to convert into a review
     * @param   Connection      $p4         the perforce connection to use
     * @return  GitReview       instance of this model
     * @throws  \InvalidArgumentException   if the passed change isn't a git-fusion review shelf
     */
    public static function createFromChange($change, $p4 = null)
    {
        // start by normalizing change to an object
        $change = !$change instanceof Change ? Change::fetch($change, $p4) : $change;

        // verify the change looks like its a git pre-commit review
        $gitInfo = new GitInfo($change->getDescription());
        if ($gitInfo->get('review-id') != $change->getId() || !$change->isPending()) {
            throw new \InvalidArgumentException("Cannot create review, change isn't a pre-commit git-fusion review.");
        }

        // create a review from the passed change and then touch it up:
        // - The git-fusion managed change is the 'authorative' change so set its id as the reviews id
        // - Update the description with our filtered version (that doesn't include the git-info key/value pairs)
        $review = parent::createFromChange($change, $p4);
        $review->setId($change->getId())
               ->set('description', $gitInfo->getDescription());

        // ensure all detectable parties are listed as participants
        $review->addParticipant($gitInfo->get('review-author'));
        $review->addParticipant($gitInfo->get('review-committer'));
        $review->addParticipant($gitInfo->get('review-pusher'));

        return $review;
    }

    /**
     * Updates this review record using the passed change.
     *
     * Parent has behaviours we don't want here:
     * - It tries to update the authorative change, we rely on git-fusion to do that
     * - It tries to create an archive change, we don't want those for git-fusion reviews
     * As such, we don't call parent at all.
     *
     * If the change in question is the review change, we simply update participants, clear
     * the 'commit status' if appropriate and ensure the review is in a 'pending' state.
     * If the passed change is a commit, we do the above updates, add the commit as a change
     * known to the review and leave the review in a 'not pending' state.
     * If a pending change other than the git-fusion managed change is passed, we don't know
     * what to do with it so we throw an exception. In practice this shouldn't happen.
     *
     * @param   Change|string   $change                 change to populate review record from
     * @param   bool            $unapproveModified      whether approved reviews can be unapproved if they contain
     *                                                  modified files
     * @return  Review          instance of this model
     * @throws  \InvalidArgumentException   for pending changes other than the authorative change
     * @todo    if we ever want to version git reviews (likely) we need to defend against race conditions
     *          e.g. avoid making new versions for the same consecutive content or commits we've already seen
     */
    public function updateFromChange($change, $unapproveModified = true)
    {
        // normalize change to object
        $change = !$change instanceof Change ? Change::fetch($change, $this->getConnection()) : $change;

        // if a pending change other than the authorative change is used, throw
        if ($change->isPending() && $change->getId() != $this->getId()) {
            throw new \InvalidArgumentException(
                'Cannot update review, only the original pending change or a commit may be passed.'
            );
        }

        // add commits to the review; we don't presently surface this but
        // seems useful to track it if we've got it
        if ($change->isSubmitted()) {
            $this->addCommit($change);
            $this->addChange($change->getOriginalId());
        }

        // ensure all detectable parties are listed as participants
        // the review-author/committer/pusher may not be present but blindly trying to add them is ok
        $gitInfo = new GitInfo($change->getDescription());
        $this->addParticipant($gitInfo->get('review-author'));
        $this->addParticipant($gitInfo->get('review-committer'));
        $this->addParticipant($gitInfo->get('review-pusher'));
        $this->addParticipant($change->getUser());

        // clear commit status if:
        // - this review isn't mid-commit (intended to clear old errors)
        // - this review is in the process of committing the given change
        if (!$this->isCommitting() || $this->getCommitStatus('change') == $change->getOriginalId()) {
            $this->setCommitStatus(null);
        }

        // update the review's pending status based on the change we just updated from
        $this->setPending($change->isPending());

        // revert state back to 'Needs Review' if auto state reversion is enabled, the review
        // was approved and the new version is different
        // we specifically check for 1 as that indicates a difference in a key field such as filenames, digests or type
        // if the new version is pending it must be the authorative change and we have nothing to compare
        // it to, so we assumed it changed
        if ($this->getState() === static::STATE_APPROVED
            && $unapproveModified
            && ($change->isPending() || $this->changesDiffer($this->getId(), $change) === 1)
        ) {
            $this->setState(static::STATE_NEEDS_REVIEW);
        }

        return $this;
    }

    /**
     * Deletes the current review and attempts to remove indexes.
     * Extends parent to avoid deleting the shelved change, its git-fusion's to manage.
     *
     * @return  GitReview   to maintain a fluent interface
     * @throws  Exception   if no id is set
     * @throws  \Exception  rethrows any exceptions caused during delete
     */
    public function delete()
    {
        KeyRecord::delete();
    }

    /**
     * Extend parent to not synchronize descriptions for git reviews.
     *
     * It needs more thinking to do it well, if at all (the description is already updated to match the last git
     * commit after the push, and also contains some extra key/value pairs data like sha). Doing it the same way
     * as parent would likely replace a sensible review description with that of the last commit pushed (which
     * doesn't seem super).
     *
     * @param   string           $reviewDescription  the description to use for the review (review keywords stripped)
     * @param   string           $changeDescription  the description to use for the change (review keywords intact)
     * @param   Connection|null  $connection         the perforce connection to use - should be p4 admin, since the
     *                                               current user may not own all the associated changes
     * @return  bool             true if the review description was modified, false otherwise
     */
    public function syncDescription($reviewDescription, $changeDescription, $connection = null)
    {
        return false;
    }

    /**
     * Called when an auto-generated ID is required for an entry.
     *
     * Extends parent to throw. Git reviews are anticipated to have an ID assigned.
     *
     * @throws  \RuntimeException   as review ids should be set explicitly.
     */
    protected function makeId()
    {
        throw new \RuntimeException('Cannot make id, git reviews must have an ID set on them.');
    }

    /**
     * Extend parent to say votes are never stale.
     * We don't track versions well enough to deal with stale votes yet.
     *
     * @param   array   $vote   vote to check
     * @return  boolean         false to indicate the vote is never stale
     */
    protected function isStaleVote(array $vote)
    {
        return false;
    }
}
