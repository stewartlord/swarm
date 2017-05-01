<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews;

use P4\Spec\Change;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Reviews\Listener\Review as ReviewListener;
use Reviews\Listener\ShelveCommit as ShelveCommitListener;
use Reviews\Filter\GitInfo;
use Reviews\Model\GitReview;
use Reviews\Model\Review;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to handle review tasks.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();

        // attach listeners to:
        // - create/update review when change is shelved or committed
        // - process review when its created or updated
        $events->attach(new ShelveCommitListener($services));
        $events->attach(new ReviewListener($services));

        // Deal with git-fusion initiated reviews before traditional p4 reviews.
        //
        // If the shelf is already a git initiated review we'll update it.
        //
        // If the shelf has git-fusion keywords indicating this is a new review
        // translate the existing shelf into a new git-review and process it.
        $events->attach(
            'task.shelve',
            function ($event) use ($services) {
                $p4Admin = $services->get('p4_admin');
                $queue   = $services->get('queue');
                $config  = $services->get('config');
                $change  = $event->getParam('change');

                // if we didn't get a pending change to work with, bail
                if (!$change instanceof Change || !$change->isPending()) {
                    return;
                }

                // if the change is by a user that is ignored for the purpose of reviews, bail
                $ignored = isset($config['reviews']['ignored_users']) ? $config['reviews']['ignored_users'] : null;
                if ($p4Admin->stringMatches($change->getUser(), (array) $ignored)) {
                    return;
                }

                // if this change doesn't have a valid looking git-fusion style review-id
                // there's no need to further examine it here, return
                $gitInfo = new GitInfo($change->getDescription());
                if ($gitInfo->get('review-id') != $change->getId()) {
                    return;
                }

                try {
                    // using the change id, verify if a git review already exists
                    // note the review id and change id are the same for git-fusion reviews
                    $review = Review::fetch($change->getId(), $p4Admin);

                    // if we get a review but its the wrong type, we can't do anything with it
                    // this really shouldn't happen but good to confirm all is well
                    if ($review->getType() != 'git') {
                        return;
                    }
                } catch (RecordNotFoundException $e) {
                    // couldn't fetch an existing review, create one!
                    $review = GitReview::createFromChange($change, $p4Admin);
                    $review->save();

                    // ensure we pass along to the review event that this is an add
                    $isAdd = true;
                }

                // put the fetched/created review on the existing event.
                // the presence of a review on the event will cause the traditional
                // shelf-commit handler to skip processing this change.
                $event->setParam('review', $review);

                // push the new review into queue for further processing.
                $queue->addTask(
                    'review',
                    $review->getId(),
                    array(
                        'user'             => $change->getUser(),
                        'updateFromChange' => $change->getId(),
                        'isAdd'            => isset($isAdd) && $isAdd
                    )
                );
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
