<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\Listener;

use Activity\Model\Activity;
use Application\Filter\Linkify;
use Groups\Model\Group;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Projects\Model\Project;
use Reviews\Filter\GitInfo;
use Reviews\Model\Review as ReviewModel;
use Users\Model\User;
use Zend\Http\Client as HttpClient;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\Event;
use Zend\Http\Request;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Review extends AbstractListenerAggregate
{
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
     * Attach the listener to fetch and process review when its created or updated.
     *
     * @param  EventManagerInterface    $events
     * @param  int                      $priority   the priority at which to register this aggregate
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(
            'task.review',
            array($this, 'processReview'),
            100
        );
    }

    /**
     * Process the review, e.g. determine affected projects, prepare activity, etc.
     *
     * @param  MvcEvent $event
     * @return void
     */
    public function processReview(Event $event)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $keywords = $services->get('review_keywords');
        $config   = $services->get('config');
        $id       = $event->getParam('id');
        $data     = $event->getParam('data') + array(
            'user'                => null,
            'isAdd'               => false,
            'isStateChange'       => false,
            'isReviewersChange'   => false,
            'isVote'              => null,
            'isDescriptionChange' => false,
            'updateFromChange'    => null,
            'testStatus'          => null,
            'description'         => null,
            'previous'            => array(),
            'quiet'               => null
        );
        $quiet    = $event->getParam('quiet', $data['quiet']);
        $previous = $data['previous'] + array(
            'testStatus'          => null,
            'description'         => null,
            'participantsData'    => null
        );

        try {
            // fetch review record
            $review = ReviewModel::fetch($id, $p4Admin);
            $event->setParam('review', $review);

            // we'll need the version before @mentions were added later on to
            // pull apart details about who was explicitly added/removed/etc.
            $fetchedParticipantsData = $review->getParticipantsData();

            // update from change if the event data tells us to.
            if ($data['updateFromChange']) {
                // first update the review from change which will add participants
                // and copy-over or clear-out shelved files as needed.
                $oldVersions  = $review->getVersions();
                $updateChange = Change::fetch($data['updateFromChange'], $p4Admin);

                // when you update the review or committing against it, we only want to honor
                // new @mentions. this prevents resurrecting deleted reviewers. note this
                // approach is imperfect (if we're being updated from two shelves in different
                // workspaces for example it doesn't work right) but for the happy paths it
                // should notably reduce the dead from arising and who doesn't appreciate that.
                $oldMentions = array();
                if (!$data['isAdd']) {
                    try {
                        // note we allow for archive shelves when getting head change as they
                        // are more likely to have the 'original' description than the authorative
                        // review change as the latter syncs to web based description edits.
                        $headChange  = Change::fetch($review->getHeadChange(true), $p4Admin);
                        $oldMentions = Linkify::getCallouts($headChange->getDescription());
                    } catch (SpecNotFoundException $e) {
                    } catch (\InvalidArgumentException $e) {
                    }

                    // in the unlikely event that we got an invalid id,
                    // or change is missing, just log it and carry on
                    if (isset($e)) {
                        $services->get('logger')->err($e);
                        unset($e);
                    }
                }

                $review->updateFromChange(
                    $updateChange,
                    isset($config['reviews']['unapprove_modified'])
                    ? (bool) $config['reviews']['unapprove_modified']
                    : true
                );

                // ensure any new @mentions or @*mentions in the change description are added
                // we only count new @mentions to avoid resurrecting removed/un-required reviewers.
                $mentions = array_diff(Linkify::getCallouts($updateChange->getDescription()), $oldMentions);
                $review->addParticipant(User::filter($mentions, $p4Admin));
                $required = array_diff(
                    Linkify::getCallouts($updateChange->getDescription(), true),
                    $oldMentions
                );
                $review->addRequired(User::filter($required, $p4Admin));

                // if update did not produce a new version (ie. no diffs) quietly bail.
                // we are the boss of the review event and have determined this update is
                // not really an update (e.g. a race condition from both api and shelf events).
                // note git reviews are exempt - we don't currently track versions on them.
                if ($oldVersions == $review->getVersions() && $review->getType() !== 'git') {
                    // only need to save if there was a change in the @mentions
                    if ($mentions || $required) {
                        $review->save();
                    }
                    $event->setParam('quiet', true);
                    return;
                }

                // next ensure the review knows about any newly affected projects
                $currentProjects = Project::getAffectedByChange($updateChange, $p4Admin);
                $review->setProjects($currentProjects);

                // associate review with groups that the author is a member of
                // we use no cache as it is much faster for this particular query
                $groups = Group::fetchAll(
                    array(
                        Group::FETCH_BY_USER  => $review->get('author'),
                        Group::FETCH_INDIRECT => true,
                        Group::FETCH_NO_CACHE => true
                    ),
                    $p4Admin
                );
                $review->setGroups($groups->invoke('getId'));

                $review->save();
            }

            // if this is an add or the description has changed ensure new @mentions are participants.
            // we only count new @mentions to avoid resurrecting removed reviewers.
            $oldMentions = Linkify::getCallouts($previous['description']);
            $newMentions = Linkify::getCallouts($review->get('description'));
            $newRequired = Linkify::getCallouts($review->get('description'), true);
            $required    = User::filter(array_diff($newRequired, $oldMentions), $p4Admin);
            $mentions    = User::filter(array_diff($newMentions, $oldMentions), $p4Admin);
            $mentions    = array_diff($mentions, $review->getParticipants());
            if (($data['isAdd'] || $data['isDescriptionChange']) && $mentions) {
                $review->addParticipant($mentions)->addRequired($required)->save();
            }

            // fetch projects
            // we do this regardless of whether files were touched, but we want
            // to do it after we have had a chance to re-assess affected projects
            // (we need them to know which automated tests need to be triggered
            // and to notify all project members on newly created reviews)
            $projects = Project::fetchAll(
                array(Project::FETCH_BY_IDS => array_keys($review->getProjects())),
                $p4Admin
            );

            // automated test integration
            // if files were touched, kick off automated tests.
            if ($data['updateFromChange']) {
                // compose test pass/fail callback urls.
                $urlHelper = $services->get('ViewHelperManager')->get('qualifiedUrl');
                $testsPassUrl   = $urlHelper(
                    'review-tests',
                    array('review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'pass')
                );
                $testsFailUrl   = $urlHelper(
                    'review-tests',
                    array('review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'fail')
                );

                // compose deploy success/fail callback urls.
                $deploySuccessUrl   = $urlHelper(
                    'review-deploy',
                    array('review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'success')
                );
                $deployFailUrl   = $urlHelper(
                    'review-deploy',
                    array('review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'fail')
                );

                // extract the http client options; including any special overrides for our host
                $options = $services->get('config') + array('http_client_options' => array());
                $options = (array) $options['http_client_options'];

                $doRequest = function (
                    $services,
                    $url,
                    $description,
                    $postBody = '',
                    $postFormat = Project::FORMAT_URL
                ) use ($options) {
                    // attempt a request for the given url to trigger tests.
                    try {
                        $client = new HttpClient;
                        $client->setUri($url);

                        // if we have post data, ensure we make a POST request
                        if (!empty($postBody)) {
                            $client->setMethod(Request::METHOD_POST);

                            // parse body based on its format (URL or JSON)
                            if ($postFormat === Project::FORMAT_URL) {
                                parse_str($postBody, $postParams);
                                $client->setParameterPost($postParams);
                            } elseif ($postFormat === Project::FORMAT_JSON) {
                                $client->setEncType('application/json');
                                $client->setRawBody($postBody);
                            }
                        }

                        // calculate options, including host based overrides, and set them
                        if (isset($options['hosts'][$client->getUri()->getHost()])) {
                            $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
                        }
                        unset($options['hosts']);
                        $client->setOptions($options);

                        // attempt trigger remote build - log failure.
                        $response = $client->dispatch($client->getRequest());
                        if (!$response->isSuccess()) {
                            $services->get('logger')->err(
                                'Failed to trigger ' . $description . ': ' . $url . ' (' .
                                $response->getStatusCode() . " - " . $response->getReasonPhrase . ').'
                            );
                        }
                    } catch (\Exception $e) {
                        $services->get('logger')->err($e);
                    }
                    return isset($response) ? $response : false;
                };

                // reviews can impact multiple projects and each project can have its own test config
                // note we only include projects/branches the change being processed impacts.
                $testStartTimes = array();
                foreach ($currentProjects as $projectId => $branches) {
                    // get the full project object and the list of impacted branch names
                    $project = isset($projects[$projectId]) ? $projects[$projectId] : null;
                    $branchNames = array();
                    foreach ($branches as $branch) {
                        $branch = $project->getBranch($branch);
                        $branchNames[] = $branch['name'];
                    }

                    // if enabled and configured, run automated tests
                    if ($project && $project->getTests('enabled') && $project->getTests('url')) {
                        // customize test url for this project.
                        $search  = array(
                            '{change}',
                            '{status}',
                            '{review}',
                            '{project}',
                            '{projectName}',
                            '{branch}',
                            '{branchName}',
                            '{pass}',
                            '{fail}',
                            '{deploySuccess}',
                            '{deployFail}'
                        );
                        $replace = array_map(
                            'rawurlencode',
                            array(
                                $review->getHeadChange(true),
                                $review->isPending() ? 'shelved' : 'submitted',
                                $review->getId(),
                                $project->getId(),
                                $project->getName(),
                                implode(',', $branches),
                                implode(',', $branchNames),
                                $testsPassUrl,
                                $testsFailUrl,
                                $deploySuccessUrl,
                                $deployFailUrl
                            )
                        );
                        $url = str_replace($search, $replace, $project->getTests('url'));
                        $response = $doRequest(
                            $services,
                            $url,
                            'automated tests',
                            str_replace($search, $replace, trim($project->getTests('postBody'))),
                            $project->getTests('postFormat')
                        );

                        if ($response && $response->isSuccess()) {
                            $testStartTimes[] = time();
                        }
                    }

                    // if enabled and configured, run deploy
                    if ($project && $project->getDeploy('enabled') && $project->getDeploy('url')) {
                        // customize test url for this project.
                        $search  = array(
                            '{change}',
                            '{status}',
                            '{review}',
                            '{project}',
                            '{projectName}',
                            '{branch}',
                            '{branchName}',
                            '{success}',
                            '{fail}'
                        );
                        $replace = array_map(
                            'rawurlencode',
                            array(
                                $review->getHeadChange(true),
                                $review->isPending() ? 'shelved' : 'submitted',
                                $review->getId(),
                                $project->getId(),
                                $project->getName(),
                                implode(',', $branches),
                                implode(',', $branchNames),
                                $deploySuccessUrl,
                                $deployFailUrl
                            )
                        );
                        $url = str_replace($search, $replace, $project->getDeploy('url'));

                        $doRequest($services, $url, 'automated deploy');
                    }
                }

                // if tests were successfully launched, details are updated with start times
                // else if there are incomplete tests from a previous version, start times are cleared
                // for both cases, end times need to be cleared as well
                $details = $review->getTestDetails(true);
                if ($testStartTimes || count($details['startTimes']) > count($details['endTimes'])) {
                    $review->setTestDetails(
                        array('startTimes' => $testStartTimes, 'endTimes' => array()) + $details
                    )->save();
                }
            }

            // prepare review info for activity streams
            $activity = new Activity;
            $activity->set(
                array(
                    'type'          => 'review',
                    'link'          => array('review', array('review' => $id)),
                    'user'          => $data['user'],
                    'action'        => $data['isAdd'] ? 'requested' : 'updated',
                    'target'        => 'review ' . $id,
                    'description'   => $keywords->filter($data['description'] ?: $review->get('description')),
                    'topic'         => $review->getTopic(),
                    'time'          => $review->get('updated'),
                    'streams'       => array('review-' . $id),
                    'change'        => $review->getHeadChange()
                )
            );

            // we want to know about explicit 'primary action' reviewer join/leave/edit/required/optional
            // style changes so we can give specific notifications on these topics. note this excludes
            // @mention based additions and getting brought in due to editing/touching the review.
            $getRequired = function ($values) {
                $required = array();
                foreach ((array) $values as $user => $data) {
                    if (isset($data['required']) && $data['required']) {
                        $required[] = $user;
                    }
                }
                return $required;
            };
            $fetchedRequired   = $getRequired($fetchedParticipantsData);
            $previousRequired  = $getRequired((array) $previous['participantsData']);
            $fetchedReviewers  = array_keys($fetchedParticipantsData);
            $previousReviewers = array_keys((array) $previous['participantsData']);

            // figure out who was removed, added as required, added, made required, made optional
            $removedReviewers  = array_diff($previousReviewers, $fetchedReviewers);
            $addedRequired     = array_diff($fetchedRequired,   $previousRequired,  $previousReviewers);
            $addedReviewers    = array_diff($fetchedReviewers,  $previousReviewers, $addedRequired);
            $madeRequired      = array_diff($fetchedRequired,   $previousRequired,  $addedRequired);
            $madeOptional      = array_diff($previousRequired,  $fetchedRequired,   $removedReviewers);

            // if this isn't a reviewers change event or we didn't get previous data clear our bunk data
            if (!$data['isReviewersChange'] || !is_array($previous['participantsData'])) {
                $removedReviewers = $addedRequired = $addedReviewers = $madeRequired = $madeOptional = array();
            }

            // calculate the number of changes we detected, this helps in tuning the action
            $reviewerChanges   = !empty($removedReviewers) + !empty($addedReviewers) + !empty($addedRequired)
                               + !empty($madeRequired) + !empty($madeOptional);

            // if this is a reviewer change, provide a better actions. we have a number of cases:
            // - this isn't a reviewers change so there's nothing to do
            // - the only change is the author added themselves
            // - only change is the author removed themselves
            // - that crazy author simply made themselves required or added themselves as required
            // - author just made themselves optional
            // - or lastly, its an 'edit' meaning they modified another user or multiple users
            if (!$data['isReviewersChange']) {
                // this isn't the review update you are looking for *hand-wave*
            } elseif (count($addedReviewers) == 1 && $reviewerChanges == 1
                && $data['user'] && in_array($data['user'], $addedReviewers)
            ) {
                $activity->set('action', 'joined');
            } elseif (count($removedReviewers) == 1 && $reviewerChanges == 1
                && $data['user'] && in_array($data['user'], $removedReviewers)
            ) {
                $activity->set('action', 'left');
            } elseif (count(array_merge($madeRequired, $addedRequired)) == 1 && $reviewerChanges == 1
                && $data['user'] && in_array($data['user'], array_merge($madeRequired, $addedRequired))
            ) {
                $activity->set('action', 'made their vote required on');
            } elseif (count($madeOptional) == 1 && $reviewerChanges == 1
                && $data['user'] && in_array($data['user'], $madeOptional)
            ) {
                $activity->set('action', 'made their vote optional on');
            } else {
                $activity->set('action', 'edited reviewers on');
                $activity->set(
                    'details',
                    array(
                        'reviewers' => array_filter(
                            array(
                                'addedOptional' => $addedReviewers,
                                'addedRequired' => $addedRequired,
                                'madeRequired'  => $madeRequired,
                                'madeOptional'  => $madeOptional,
                                'removed'       => $removedReviewers
                            )
                        )
                    )
                );
            }

            // if this is a vote change, fine-tune the action.
            if ($data['isVote']) {
                $activity->set('action', 'voted ' . ($data['isVote'] > 0 ? 'up' : 'down'));
            } elseif ($data['isVote'] === 0) {
                $activity->set('action', 'cleared their vote on');
            }

            // if the state has changed, fine-tune the action.
            if ($data['isStateChange']) {
                switch ($review->get('state')) {
                    case 'needsReview':
                        $activity->set('action', 'requested further review of');
                        $activity->set('target', $id);
                        break;
                    case 'needsRevision':
                        $activity->set('action', 'requested revisions to');
                        break;
                    default:
                        $activity->set('action', $review->get('state'));
                }
            }

            // if the description has changed, fine-tune the action.
            if ($data['isDescriptionChange']) {
                $activity->set('action', 'updated description of');
            }

            // if test status was updated, revise action and overload preposition.
            if ($data['testStatus']) {
                $activity->set('action',      $data['user'] ? 'reported' : null);
                $activity->set('target',      $data['user'] ? 'review ' . $id : 'Review ' . $id);
                $activity->set('preposition', $data['testStatus'] === 'pass' ? "passed tests for" : "failed tests for");
            }

            // if the review files were updated, revise action
            if (!$data['isAdd'] && $data['updateFromChange']) {
                $activity->set('action',      'updated files in');

                // normally just strip keywords from the update change however, if its the
                // authorative change for a git review, strip git info instead though.
                $description = $keywords->filter($updateChange->getDescription());
                if ($review->getType() === 'git' && $updateChange->getId() == $review->getId()) {
                    $gitInfo = new GitInfo($updateChange->getDescription());
                    $description = $gitInfo->getDescription();
                }

                $activity->set('description', $description);
            }

            // if the review files were committed, silence activity and email notifications
            // the changes module handles commit notifications and we don't want duplicates
            if (!$data['isAdd'] && $data['updateFromChange'] && $updateChange->isSubmitted()) {
                $event->setParam('quiet', $quiet = true);
            }

            // flag the activity event as affecting all projects impacted by the review
            $activity->addProjects($review->getProjects());

            // we made it this far, safe to record the activity.
            $event->setParam('activity', $activity);

            // touch-up old activity to ensure that all events related to this review
            // have their 'change' field value up-to-date (exclude change/job type events)
            // so we can filter for restricted changes
            if ($data['updateFromChange']) {
                $headChange = $review->getHeadChange();
                $options    = array(Activity::FETCH_BY_STREAM => 'review-' . $id);
                foreach (Activity::fetchAll($options, $p4Admin) as $record) {
                    if (!in_array($record->get('type'), array('change', 'job'))
                        && $record->get('change') !== $headChange
                    ) {
                        $record->set('change', $headChange)->save();
                    }
                }
            }

            // determine who to notify via activity and email
            // - always notify review participants (author, creator, reviewers, etc.)
            $to = $review->getParticipants();
            $activity->addFollowers($to);

            // if it's a new review, notify all members of associated groups (if the group is configured for it)
            if ($data['isAdd'] && isset($groups)) {
                foreach ($groups as $group) {
                    if ($group->getConfig()->getEmailFlag('reviews')) {
                        $members = Group::fetchAllMembers($group->getId(), false, null, null, $p4Admin);
                        $to      = array_merge($to, $members);
                        $activity->addFollowers($members);
                    }
                }
            }

            // if it's a new review, notify all project members and moderators
            if ($data['isAdd']) {
                $impacted = $review->getProjects();
                foreach ($projects as $projectId => $project) {
                    $members    = $project->getAllMembers();
                    $branches   = isset($impacted[$projectId]) ? $impacted[$projectId] : null;
                    $moderators = $branches ? $project->getModerators($branches)       : array();

                    $activity->addFollowers($members);
                    $activity->addFollowers($moderators);

                    // email notification can be disabled per project
                    $emailMembers = $project->getEmailFlag('review_email_project_members');
                    if ($emailMembers || $emailMembers === null) {
                        $to = array_merge($to, $members, $moderators);
                    }
                }
            }

            // include any removed reviewers this one last time so they know it happened
            $activity->addFollowers($removedReviewers);
            $to = array_merge($to, $removedReviewers);

            // fine-tune the recipients list to remove user(s) who:
            //  - changed the review state
            //  - modified the list of reviewers or added/removed themselves
            //  - made themselves or others required/optional (captured by reviewers change flag)
            //  - voted or cleared their vote
            if ($data['isStateChange'] || $data['isReviewersChange'] || isset($data['isVote'])) {
                $to = array_diff($to, array($data['user']));
            }

            // configure a message for mail module to deliver
            $event->setParam(
                'mail',
                array(
                    'subject'       => 'Review @' . $review->getId() . ' - '
                                    .  $keywords->filter($review->get('description')),
                    'cropSubject'   => 80,
                    'toUsers'       => $to,
                    'fromUser'      => $data['user'],
                    'messageId'     => '<review-' . $review->getId()    . '-' . time() . '@swarm>',
                    'inReplyTo'     => '<topic-'  . $review->getTopic() . '@swarm>',
                    'htmlTemplate'  => __DIR__ . '/../../../view/mail/review-html.phtml',
                    'textTemplate'  => __DIR__ . '/../../../view/mail/review-text.phtml',
                )
            );

            // don't send email in following cases:
            //  - for passing tests, unless they were previously failing
            //  - for description changes
            $isTestPass = $data['testStatus'] === 'pass' && $previous['testStatus'] !== 'fail';
            if (($isTestPass || $data['isDescriptionChange']) && $quiet !== true) {
                $quiet = array_merge((array) $quiet, array('mail'));
                $event->setParam('quiet', $quiet);
            }
        } catch (\Exception $e) {
            $services->get('logger')->err($e);
        }
    }
}
