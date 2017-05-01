<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Demo\Controller;

use Comments\Model\Comment;
use P4\Counter\Counter;
use P4\Spec\Change;
use P4\Spec\Group;
use P4\Spec\User;
use Projects\Model\Project;
use Reviews\Model\Review;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class IndexController extends AbstractActionController
{
    public function generateAction()
    {
        $request  = $this->getRequest();
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');

        // only generate data if user is an admin
        $services->get('permissions')->enforce('admin');

        // if change counter is >1000, assume this is a real server and bail.
        if (!$request->getQuery('force') && Counter::exists('change') && Counter::fetch('change')->get() > 1000) {
            throw new \Exception(
                "Refusing to generate data. This server looks real (>1000 changes). Use 'force' param to override."
            );
        }

        // making demo data is hard work!
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        $counts = array(
            'users'    => array('created' => 0, 'deleted' => 0),
            'groups'   => array('created' => 0, 'deleted' => 0),
            'projects' => array('created' => 0, 'deleted' => 0),
            'reviews'  => array('created' => 0, 'deleted' => 0),
            'comments' => array('created' => 0, 'deleted' => 0)
        );

        // cache is likely to be stale in demo environments, blitz it.
        $cache = $p4Admin->getService('cache');
        $cache->invalidateAll();

        // optionally delete existing data
        if ($request->getQuery('reset')) {
            // delete all users, except for the current user
            $users = User::fetchAll(array(), $p4Admin);
            $users->filter('User', $p4Admin->getUser(), array($users::FILTER_INVERSE));
            $users->invoke('delete');
            $counts['users']['deleted'] = $users->count();

            $groups = Group::fetchAll(array(), $p4Admin);
            $groups->invoke('delete');
            $counts['groups']['deleted'] = $groups->count();

            $projects = Project::fetchAll(array(), $p4Admin);
            $projects->invoke('delete');
            $counts['projects']['deleted'] = $projects->count();

            $reviews = Review::fetchAll(array(), $p4Admin);
            $reviews->invoke('delete');
            $counts['reviews']['deleted'] = $reviews->count();

            $comments = Comment::fetchAll(array(), $p4Admin);
            $comments->invoke('delete');
            $counts['comments']['deleted'] = $comments->count();
        }

        // make requested number of users (default is 0)
        $users = $this->getUserNames((int) $request->getQuery('users', 0));
        foreach ($users as $name) {
            $user = new User($p4Admin);
            $user->set(array('User' => $name, 'FullName' => $name, 'Email' => $name . '@localhost'));
            $user->save();
        }
        $counts['users']['created'] = count($users);

        // make requested number of groups (default is 0)
        // each group gets 1-3% of the user population
        $groups = (int) $request->getQuery('groups', 0);
        $users  = User::fetchAll(array(), $p4Admin)->invoke('getId');
        if ($groups) {
            for ($i = 0; $i < $groups; $i++) {
                $group   = new Group($p4Admin);
                $members = max(1, rand(count($users) * .01, count($users) * .03));
                $members = (array) array_rand(array_flip($users), $members);
                $group->set(
                    array(
                        'Group'  => 'group' . $i,
                        'Owners' => array($p4Admin->getUser()),
                        'Users'  => $members
                    )
                );
                $group->save(false, true);
            }
        }
        $counts['groups']['created'] = $groups;

        // make requested number of projects (default is 5)
        $projects = $this->getProjectData((int) $request->getQuery('projects', 5));
        foreach ($projects as $project) {
            $model = new Project($p4Admin);
            $model->set($project);
            $model->setMembers((array) array_rand(array_flip($users), rand(1, round(count($users)/4))));
            $model->save();
        }
        $counts['projects']['created'] = count($projects);

        // make max of the requested number of reviews based on recent changes (default is 100)
        $reviews     = array();
        $reviewCount = (int) $request->getQuery('reviews', 100);
        if ($reviewCount) {
            $states  = array('needsReview', 'needsRevision', 'approved', 'rejected', 'archived');
            $changes = Change::fetchAll(
                array(
                    Change::FETCH_MAXIMUM   => $reviewCount,
                    Change::FETCH_BY_STATUS => 'submitted'
                ),
                $p4Admin
            );
            foreach ($changes as $change) {
                $review = Review::createFromChange($change->getId(), $p4Admin);
                $review->set('state',    $states[array_rand($states)]);
                $review->set('projects', Project::getAffectedByChange($change, $p4Admin));
                $review->set('participants', (array) array_rand(array_flip($users), rand(1, min(count($users), 10))));
                $review->save();
                $review->updateFromChange($change, true);
                $review->save();
                $reviews[] = $review->getId();
            }
        }
        $counts['reviews']['created'] = count($reviews);

        // make requested number of comments (default is average of 5 per review)
        $comments = $this->getCommentData($request->getQuery('comments', 5 * count($reviews)));
        foreach ($comments as $comment) {
            $model = new Comment($p4Admin);
            $model->set('topic', 'reviews/' . $reviews[array_rand($reviews)]);
            $model->set('user', $users[array_rand($users)]);
            $model->set('body', $comment);
            $model->save();
        }
        $counts['comments']['created'] = count($comments);

        // cache likely stale again
        $cache->invalidateAll();

        return new JsonModel($counts);
    }

    protected function getProjectData($count)
    {
        $projects = array(
            array(
                'id' => 'jam',
                'name' => 'Jam',
                'branches' => array(
                    array(
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => array('//depot/Jam/MAIN/...')
                    ),
                    array(
                        'id' => '2.1',
                        'name' => 'Release 2.1',
                        'paths' => array('//depot/Jam/REL2.1/...')
                    ),
                    array(
                        'id' => '2.2',
                        'name' => 'Release 2.2',
                        'paths' => array('//depot/Jam/REL2.2/...')
                    )
                )
            ),
            array(
                'id' => 'jamgraph',
                'name' => 'Jamgraph',
                'branches' => array(
                    array(
                        'id' => 'dev',
                        'name' => 'Development',
                        'paths' => array('//depot/Jamgraph/DEV/...')
                    ),
                    array(
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => array('//depot/Jamgraph/MAIN/...')
                    ),
                    array(
                        'id' => '1.0',
                        'name' => 'Release 1.0',
                        'paths' => array('//depot/Jamgraph/REL1.0/...')
                    )
                )
            ),
            array(
                'id' => 'misc',
                'name' => 'Miscellaneous',
                'branches' => array(
                    array(
                        'id' => 'manuals',
                        'name' => 'Manuals',
                        'paths' => array('//depot/Misc/manuals/...')
                    ),
                    array(
                        'id' => 'marketing',
                        'name' => 'Marketing',
                        'paths' => array('//depot/Misc/marketing/...')
                    )
                )
            ),
            array(
                'id' => 'talkhouse',
                'name' => 'Talkhouse',
                'branches' => array(
                    array(
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => array('//depot/Talkhouse/main-dev/...')
                    ),
                    array(
                        'id' => '1.0',
                        'name' => 'Release 1.0',
                        'paths' => array('//depot/Talkhouse/rel1.0/...')
                    ),
                    array(
                        'id' => '1.5',
                        'name' => 'Release 1.5',
                        'paths' => array('//depot/Talkhouse/rel1.5/...')
                    )
                )
            ),
            array(
                'id' => 'www',
                'name' => 'WWW',
                'branches' => array(
                    array(
                        'id' => 'dev',
                        'name' => 'Development',
                        'paths' => array('//depot/www/DEV/...')
                    ),
                    array(
                        'id' => 'live',
                        'name' => 'Live',
                        'paths' => array('//depot/www/live/...')
                    ),
                    array(
                        'id' => 'review',
                        'name' => 'Review',
                        'paths' => array('//depot/www/review/...')
                    )
                )
            )
        );

        // duplicate projects up to requested count
        $have = count($projects);
        for ($i = $have; $i < $count; $i++) {
            $project          = $projects[rand(0, $have - 1)];
            $project['id']   .= $i;
            $project['name'] .= $i;
            $projects[]       = $project;
        }

        // remove unwanted projects
        $projects = array_slice($projects, 0, $count);

        return $projects;
    }

    protected function getCommentData($count)
    {
        if (!$count) {
            return array();
        }

        $comments = array();

        // if this is a test, don't make external requests
        if ($this->getRequest()->isTest) {
            return array_fill(0, $count, 'test comment');
        }

        // grab 50 paragraphs from hipster-ipsum
        $hipster  = file_get_contents('http://hipsterjesus.com/api/?paras=50&html=false');
        $hipster  = json_decode($hipster, true);
        $comments = explode("\n", $hipster['text']);

        // another 50 from bacon-ipsum
        $bacon    = file_get_contents('http://baconipsum.com/api/?type=meat-and-filler&paras=50');
        $bacon    = json_decode($bacon, true);
        $comments = array_merge($comments, $bacon);

        // make more comments if necessary
        while (count($comments) < $count) {
            $comments = array_merge($comments, $comments);
        }

        return array_slice($comments, 0, $count);
    }

    protected function getUserNames($count)
    {
        $names = array(
            'ethan',
            'owen',
            'liam',
            'ryan',
            'lucas',
            'daniel',
            'mason',
            'oliver',
            'logan',
            'james',
            'noah',
            'nathan',
            'alexander',
            'jayden',
            'benjamin',
            'samuel',
            'jacob',
            'matthew',
            'jack',
            'william',
            'olivia',
            'sophie',
            'emma',
            'abigail',
            'sophia',
            'charlotte',
            'emily',
            'lily',
            'ava',
            'brooklyn',
            'ella',
            'madison',
            'chloe',
            'isla',
            'isabella',
            'grace',
            'avery',
            'maya',
            'hannah',
            'amelia'
        );

        // flip so array_rand gives us values directly
        $names = array_flip($names);

        $result = array();
        for ($i = 0; $i < $count; $i++) {
            $result[] = array_rand($names) . $i;
        }

        return $result;
    }
}
