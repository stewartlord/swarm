<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Activity;

use Activity\Model\Activity;
use Groups\Model\Group;
use P4\Key\Key;
use P4\Spec\Definition as SpecDefinition;
use P4\Spec\Job;
use Projects\Model\Project;
use Reviews\Model\Review;
use Users\Model\Config as UserConfig;
use Zend\Db\Sql\Sql;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue events to record activity data.
     *
     * @param   Event   $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $manager     = $services->get('queue');
        $events      = $manager->getEventManager();

        // connect to all tasks and write activity data
        // we do this late (low-priority) so all handlers have
        // a chance to influence the activity model.
        $events->attach(
            '*',
            function ($event) use ($services) {
                $model = $event->getParam('activity');
                if (!$model instanceof Activity) {
                    return;
                }

                // ignore 'quiet' events.
                $data  = (array) $event->getParam('data') + array('quiet' => null);
                $quiet = $event->getParam('quiet', $data['quiet']);
                if ($quiet === true || in_array('activity', (array) $quiet)) {
                    return;
                }

                // don't record activity by users we ignore.
                $config = $services->get('config');
                $ignore = isset($config['activity']['ignored_users'])
                    ? (array) $config['activity']['ignored_users']
                    : array();
                if (in_array($model->get('user'), $ignore)) {
                    return;
                }

                // all activity should appear in the activity streams
                // of the user that initiated the activity.
                $model->addStream('user-'     . $model->get('user'))
                      ->addStream('personal-' . $model->get('user'));

                // add anyone who follows the user that initiated this activity
                $p4Admin = $services->get('p4_admin');
                $model->addFollowers(
                    UserConfig::fetchFollowerIds($model->get('user'), 'user', $p4Admin)
                );

                // projects that are affected should also get the activity
                // and, by extension, project members should see it too.
                if ($model->getProjects()) {
                    $projects = Project::fetchAll(
                        array(Project::FETCH_BY_IDS => array_keys($model->getProjects())),
                        $p4Admin
                    );
                    foreach ($projects as $project) {
                        $model->addStream('project-' . $project->getId());
                        foreach ($project->getAllMembers() as $member) {
                            $model->addFollowers($member);
                        }
                    }
                }

                // ensure groups the user is member of get the activity
                // we use no cache as it is much faster for this particular query
                if ($model->get('user')) {
                    $groups = Group::fetchAll(
                        array(
                            Group::FETCH_BY_USER  => $model->get('user'),
                            Group::FETCH_INDIRECT => true,
                            Group::FETCH_NO_CACHE => true
                        ),
                        $p4Admin
                    );

                    foreach ($groups as $group) {
                        $model->addStream('group-' . $group->getId());
                    }
                }

                // activity related to a review should include review participants
                // and should appear in the activity stream for the review itself
                $review = $event->getParam('review');
                if ($review instanceof Review) {
                    $model->addFollowers($review->getParticipants());
                    $model->addStream('review-' . $review->getId());
                }

                // ensure all 'followers' have this event on their personal stream
                foreach ($model->getFollowers() as $follower) {
                    $model->addStream('personal-' . $follower);
                }

                try {
                    $model->setConnection($p4Admin)->save();
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            -100
        );

        // connect to worker startup to check if we need to prime activity
        // data (ie. this is a first run against an existing server).
        $events->attach(
            'worker.startup',
            function ($event) use ($services, $manager, $events) {
                // only run for the first worker.
                if ($event->getParam('slot') !== 1) {
                    return;
                }

                // if we already have an event counter, nothing to do.
                $p4Admin = $services->get('p4_admin');
                if (Key::exists(Activity::KEY_COUNT, $p4Admin)) {
                    return;
                }

                // initialize count to zero so we exit early next time.
                $key = new Key($p4Admin);
                $key->setId(Activity::KEY_COUNT)
                    ->set(0);

                // looks like we're going to do the initial import, tie up as many
                // worker slots as we can to minimize concurrency/out-of-order issues
                // (if other workers were already running, we won't get all the slots)
                // release these slots on shutdown - only really needed when testing
                $slots = array();
                while ($slot = $manager->getWorkerSlot()) {
                    $slots[] = $slot;
                }
                $events->attach(
                    'worker.shutdown',
                    function () use ($slots, $manager) {
                        foreach ($slots as $slot) {
                            $manager->releaseWorkerSlot($slot);
                        }
                    }
                );

                // grab the last 10k changes and get ready to queue them.
                $queue   = array();
                $changes = $p4Admin->run('changes', array('-m10000', '-s', 'submitted'));
                foreach ($changes->getData() as $change) {
                    $queue[] = array(
                        'type' => 'commit',
                        'id'   => $change['change'],
                        'time' => (int) $change['time']
                    );
                }

                // grab the last 10k jobs and get ready to queue them.
                // note, jobspec is mutable so we get the date via its code
                try {
                    // use modified date field if available, falling-back to the default date field.
                    // often this will be the same field, by default the date field is a modified date.
                    $job  = new Job($p4Admin);
                    $spec = SpecDefinition::fetch('job', $p4Admin);
                    $date = $job->hasModifiedDateField()
                        ? $job->getModifiedDateField()
                        : $spec->fieldCodeToName(104);

                    $jobs = $p4Admin->run('jobs', array('-m10000', '-r'));
                    foreach ($jobs->getData() as $job) {
                        if (isset($job[$date])) {
                            $queue[] = array(
                                'type' => 'job',
                                'id'   => $job['Job'],
                                'time' => strtotime($job[$date])
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }

                // sort items by time so they are processed in order
                // if other workers are already pulling tasks from the queue.
                usort(
                    $queue,
                    function ($a, $b) {
                        return $a['time'] - $b['time'];
                    }
                );

                // we don't want to duplicate activity
                // it's possible there are already tasks in the queue
                // (imagine the trigger was running, but the workers were not),
                // if there are >10k abort; else fetch them so we can skip them.
                if ($manager->getTaskCount() > 10000) {
                    return;
                }
                $skip = array();
                foreach ($manager->getTaskFiles() as $file) {
                    $task = $manager->parseTaskFile($file);
                    if ($task) {
                        $skip[$task['type'] . ',' . $task['id']] = true;
                    }
                }

                // again, we don't want to duplicate activity
                // if there is any activity at this point, abort.
                if (Key::fetch(Activity::KEY_COUNT, $p4Admin)->get()) {
                    return;
                }

                // add jobs and changes to the queue
                foreach ($queue as $task) {
                    if (!isset($skip[$task['type'] . ',' . $task['id']])) {
                        $manager->addTask($task['type'], $task['id'], null, $task['time']);
                    }
                }
            }
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
