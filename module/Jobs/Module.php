<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Jobs;

use Activity\Model\Activity;
use Application\Filter\Linkify;
use Projects\Model\Project;
use Users\Model\User;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to handle jobs.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();

        // fetch job object for job events
        $events->attach(
            'task.job',
            function ($event) use ($services) {
                $p4Admin = $services->get('p4_admin');
                $id      = $event->getParam('id');

                try {
                    $job = \P4\Spec\Job::fetch($id, $p4Admin);
                    $event->setParam('job', $job);

                    // determine event author
                    // by default there is no modified-by field, but if we
                    // can find one in the jobspec, we will use it here.
                    $user = $job->hasModifiedByField()
                        ? $job->get($job->getModifiedByField())
                        : $job->getUser();

                    // determine action the user took
                    $action = 'modified';
                    if ($job->hasCreatedDateField() && $job->hasModifiedDateField()) {
                        $created  = $job->get($job->getCreatedDateField());
                        $modified = $job->get($job->getModifiedDateField());
                        $action   = $created === $modified ? 'created' : 'modified';
                    }

                    // prepare data model for activity streams
                    $activity = new Activity;
                    $activity->set(
                        array(
                            'type'          => 'job',
                            'link'          => array('job', array('job' => $job->getId())),
                            'user'          => $user,
                            'action'        => $action,
                            'target'        => $job->getId(),
                            'description'   => $job->getDescription(),
                            'topic'         => 'jobs/' . $job->getId(),
                            'time'          => $event->getParam('time'),
                            'projects'      => Project::getAffectedByJob($job, $p4Admin)
                        )
                    );

                    // ensure any @mention'ed users are included
                    $mentions = User::filter(Linkify::getCallouts($job->getDescription()), $p4Admin);
                    $activity->addFollowers($mentions);

                    $event->setParam('activity', $activity);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            100
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
