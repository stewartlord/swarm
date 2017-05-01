<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Jira;

use Application\Filter\Linkify;
use Jira\Model\Linkage;
use P4\Spec\Change;
use P4\Spec\Definition;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Job;
use Record\Exception\NotFoundException;
use Reviews\Model\Review;
use Zend\Http\Client as HttpClient;
use Zend\Json\Json;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Module
{
    /**
     * The JIRA module performs a few tasks assuming it has configuration data available.
     *
     * On worker 1 startup (so every ~10 minutes) we cache a copy of all valid JIRA project
     * ids by querying the JIRA server's 'project' route. This data is used for the later
     * work described below.
     *
     * Whenever text is linkified we link any JIRA issues that appear.
     *
     * When reviews are added/updated we ensure all JIRA issues they reference either in
     * their description or via associated jobs have links back to the review and that the
     * links labels have the current review status.
     *
     * When changes are committed we ensure all JIRA issues they reference either in
     * their description or via associated jobs have links back to the change in Swarm.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $services = $event->getApplication()->getServiceManager();
        $events   = $services->get('queue')->getEventManager();
        $config   = $this->getJiraConfig($services);
        $projects = $this->getProjects();
        $module   = $this;

        // bail out if we lack a host, we won't be able to do anything
        if (!$config['host']) {
            return;
        }

        // add the linkify callback if we have projects defined
        if ($projects) {
            // prepare a regex based on the configured projects then register with the linkifier
            $host  = $config['host'];
            $regex = "/^@?(?P<issue>(?:" . implode('|', array_map('preg_quote', $projects)) . ")-[0-9]+)('s)?$/";

            Linkify::addCallback(
                function ($value, $escaper) use ($regex, $host) {
                    // if it looks like a jira issue for a known project linkify
                    if (preg_match($regex, $value, $matches)) {
                        return '<a href="'
                            . $escaper->escapeFullUrl($host . '/browse/' . $matches['issue']) . '">'
                            . $escaper->escapeHtml($value) . '</a>';
                    }

                    // not a hit; tell caller we didn't handle this one
                    return false;
                },
                'jira',
                min(array_map('strlen', $projects)) + 2
            );
        }

        // connect to worker 1 startup to refresh our cache of jira project ids
        $events->attach(
            'worker.startup',
            function ($event) use ($services, $module) {
                // only run for the first worker.
                if ($event->getParam('slot') !== 1) {
                    return;
                }

                // attempt to request the list of projects, if the request fails keep
                // whatever list we have though as something is better than nothing.
                $cacheDir = $module->getCacheDir();
                $result   = $module->doRequest('get', 'project', null, $services);
                if ($result !== false) {
                    $projects = array();
                    foreach ((array) $result as $project) {
                        if (isset($project['key'])) {
                            $projects[] = $project['key'];
                        }
                    }

                    file_put_contents($cacheDir . '/projects', Json::encode($projects));
                }
            },
            -300
        );

        // the remaining work requires either project ids or a defined job field to
        // have any shot at functioning; if that isn't the case bail early.
        if (!$projects && !$config['job_field']) {
            return;
        }

        // when a job task flies by it may represent the job being added to or removed
        // from a change or review. fetch associated changes and ensure they are linked
        $events->attach(
            array('task.job'),
            function ($event) use ($services, $module) {
                $p4Admin = $services->get('p4_admin');
                $job     = $event->getParam('job');

                // if we don't have a job; nothing to do
                if (!$job instanceof Job) {
                    return;
                }

                // figure out the changes that are, or were, impacted by this job
                $linkages = Linkage::fetchAll(array(Linkage::FETCH_BY_JOB => $job->getId()), $p4Admin);
                $ids      = array_merge($linkages->invoke('getId'), $job->getChanges());

                // fetch any items that represent submitted changes or represent reviews
                // note, we only deal with JIRA links for committed changes and reviews
                $changes = Change::fetchAll(
                    array(Change::FETCH_BY_IDS => $ids, Change::FETCH_BY_STATUS => Change::SUBMITTED_CHANGE),
                    $p4Admin
                );
                $reviews = Review::fetchAll(
                    array(Review::FETCH_BY_IDS => array_diff($ids, $changes->invoke('getId'))),
                    $p4Admin
                );

                // for each change/review we found, update the JIRA links
                foreach ($changes->merge($reviews) as $item) {
                    try {
                        $module->updateIssueLinks($item, $services);
                    } catch (\Exception $e) {
                        $services->get('logger')->err($e);
                    }
                }
            },
            -300
        );

        // when a change is submitted or updated, find any associated JIRA issues;
        // either via associated jobs or callouts in the description, and ensure
        // the JIRA issues link back to the change in Swarm.
        $events->attach(
            array('task.commit', 'task.change'),
            function ($event) use ($services, $module) {
                // task.change doesn't include the change object; fetch it if we need to
                $change = $event->getParam('change');
                if (!$change instanceof Change) {
                    try {
                        $change = Change::fetch($event->getParam('id'), $services->get('p4_admin'));
                        $event->setParam('change', $change);
                    } catch (SpecNotFoundException $e) {
                    } catch (\InvalidArgumentException $e) {
                    }
                }

                // if this isn't a submitted change; nothing to do
                if (!$change instanceof Change || !$change->isSubmitted()) {
                    return;
                }

                try {
                    $module->updateIssueLinks($change, $services);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            -300
        );

        // when a review is created or updated, find any associated JIRA issues;
        // either via associated jobs or callouts in the description, and ensure
        // the JIRA issues link back to the review in Swarm.
        $events->attach(
            'task.review',
            function ($event) use ($services, $module) {
                $review = $event->getParam('review');
                if (!$review instanceof Review) {
                    return;
                }

                try {
                    // update any associated issues
                    $module->updateIssueLinks($review, $services);
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            -300
        );
    }

    /**
     * This method figures out which JIRA issues are involved with the passed review or
     * change either via mentions in the description or associated jobs and updates them:
     * - JIRA issues that are no longer associated have their Swarm links deleted.
     * - JIRA issues that are new have Swarm links added.
     * - If the link title or summary has changed, any old JIRA issue links are updated.
     *
     * @param   Change|Review   $item           the change or review we're linking to
     * @param   ServiceLocator  $services       the service locator
     */
    public function updateIssueLinks($item, ServiceLocator $services)
    {
        $p4Admin      = $services->get('p4_admin');
        $qualifiedUrl = $services->get('viewhelpermanager')->get('qualifiedUrl');
        $truncate     = $services->get('ViewHelperManager')->get('truncate');
        $icon         = $qualifiedUrl() . '/favicon.ico';
        $summary      = (string) $truncate($item->getDescription(), 80);
        $linkedIssues = $this->getLinkedJobIssues($item->getId(), $services);
        $callouts     = $this->getJiraCallouts($item->getDescription(), $services);
        $issues       = array_merge($linkedIssues, $callouts);
        $issues       = array_values(array_unique(array_filter($issues, 'strlen')));
        sort($issues);

        // get the linkage details for this issue; creating a new record if needed
        try {
            $linkage = Linkage::fetch($item->getId(), $p4Admin);
        } catch (NotFoundException $e) {
            $linkage = new Linkage($p4Admin);
            $linkage->setId($item->getId());
        }

        // the title, URL and jira global id vary by type (review/change); figure that out
        if ($item instanceof Review) {
            $title  = 'Review ' . $item->getId() . ' - ' . $item->getStateLabel() . ', ';
            $title .= $item->isCommitted() ? 'Committed' : 'Not Committed';
            $url    = $qualifiedUrl('review', array('review' => $item->getId()));
            $jiraId = 'swarm-review-' . md5(serialize(array('review' => $item->getId())));

            // if this is a legacy record where the JIRA state is stored on the
            // review upgrade that data to being stored in the linkage record
            if ($item->get('jira')) {
                $old = $item->get('jira') + array('label', 'issues');
                $linkage->set('title',  $old['label'])
                        ->set('issues', $old['issues']);

                // strip the jira value off of the review so we don't do this again
                $item->unsetRawValue('jira')->save();
            }
        } elseif ($item instanceof Change) {
            $title  = 'Commit ' . $item->getId();
            $url    = $qualifiedUrl('change', array('change' => $item->getId()));
            $jiraId = 'swarm-change-' . md5(serialize(array('change' => $item->getId())));
        } else {
            throw new \InvalidArgumentException('Update Issue Links expects a Change or Review');
        }

        // pull out the 'old' issues/title/summary/jobs before we update the linkage
        $old = $linkage->get();

        // record the new values before we start mucking with JIRA. this should help
        // ensure we don't get into a loop where we update JIRA, it tickles DTG which
        // updates jobs; round and round we go.
        $linkage->set('title',   $title)
                ->set('jobs',    array_keys($linkedIssues))
                ->set('issues',  $issues)
                ->set('summary', $summary)
                ->save();

        // remove links from any issues which are no longer impacted
        $delete = array_diff($old['issues'], $issues);
        foreach ($delete as $issue) {
            $this->doRequest(
                'delete',
                'issue/' . $issue . '/remotelink',
                array('globalId' => $jiraId),
                $services
            );
        }

        // time to deal with new/added issues
        // if the title and summary are unchanged; only add new issues. otherwise we add new
        // issues and update existing issues to match the new title/summary.
        $updates = $issues;
        if ($title == $old['title'] && $summary == $old['summary']) {
            $updates = array_diff($issues, $old['issues']);
        }
        foreach ($updates as $issue) {
            $this->doRequest(
                'post',
                'issue/' . $issue . '/remotelink',
                array(
                    'globalId'  => $jiraId,
                    'object'    => array(
                        'url'       => $url,
                        'title'     => $title,
                        'summary'   => $summary,
                        'icon'      => array(
                            'url16x16'  => $icon,
                            'title'     => 'Swarm'
                        )
                    )
                ),
                $services
            );
        }
    }

    /**
     * Given a change or change id this method will find all associated perforce jobs
     * and return the list of JIRA issue ids that appear in the 'job_field'.
     *
     * @param   string|int|Change   $change     the change to examine
     * @param   ServiceLocator      $services   the service locator
     * @return  array               an array of JIRA issues keyed on associated job id
     */
    public function getLinkedJobIssues($change, ServiceLocator $services)
    {
        $p4Admin  = $services->get('p4_admin');
        $config   = $this->getJiraConfig($services);
        $jobField = $config['job_field'];
        $change   = $change instanceof Change ? $change->getId() : $change;

        // nothing to do if no job field or job field isn't defined in our spec
        if (!$jobField || !Definition::fetch('job', $p4Admin)->hasField($jobField)) {
            return array();
        }

        // determine the ids of affected jobs
        $jobs = $p4Admin->run('fixes', array('-c', $change))->getData();
        $ids  = array();
        foreach ($jobs as $job) {
            $ids[] = $job['Job'];
        }

        // fetch the jobs and collect the issues; keyed by job id
        $issues = array();
        $jobs   = Job::fetchAll(array(Job::FETCH_BY_IDS => $ids), $p4Admin);
        foreach ($jobs as $job) {
            $issues[$job->getId()] = $job->get($jobField);
        }

        // return the trimmed non-empty values
        return array_filter(array_map('trim', $issues), 'strlen');
    }

    /**
     * Given a string of text, this method will try and locate any JIRA issue
     * ids that are present either raw e.g. SW-123, at prefixed e.g. @SW-123
     * or listed in a full url e.g. http://<jirahost>/browse/SW-123.
     *
     * @param   string          $value      the text to examine for JIRA issue ids
     * @param   ServiceLocator  $services   the service locator
     * @return  array   an array of unique JIRA issue ids referenced in the passed text
     */
    public function getJiraCallouts($value, ServiceLocator $services)
    {
        $config         = $this->getJiraConfig($services);
        $url            = $config['host'] ? $config['host'] . '/browse/' : false;
        $trimPattern    = '/^[”’"\'(<{\[]*@?(.+?)[.”’"\'\,!?:;)>}\]]*$/';
        $projects       = array_map('preg_quote', $this->getProjects());
        $calloutPattern = "/^(?:" . implode('|', $projects) . ")-[0-9]+$/";
        $words          = preg_split('/(\s+)/', $value);
        $callouts       = array();
        foreach ($words as $word) {
            if (!strlen($word)) {
                continue;
            }

            // strip the leading/trailing punctuation from the actual word
            preg_match($trimPattern, $word, $matches);
            $word = $matches[1];

            // if it looks like a full JIRA url strip it down to just the potential issue id
            if ($url && stripos($word, $url) === 0) {
                $word = rtrim(substr($word, strlen($url)), '/');
            }

            // if the trimmed word isn't empty, matches our pattern and we haven't
            // seen before it counts towards callouts.
            if (strlen($word) && preg_match($calloutPattern, $word) && !in_array($word, $callouts)) {
                $callouts[] = $word;
            }
        }

        return $callouts;
    }

    /**
     * Convenience function to ease RESTful interaction with the JIRA service.
     *
     * @param   string          $method     one of get, post, delete
     * @param   string          $resource   the resource e.g. 'project' or 'issue/<id>/remoteLinks'
     * @param   mixed           $data       get/post data to include on the request or null/false for none
     * @param   ServiceLocator  $services   the service locator
     * @return  mixed           the response or false if request fails
     */
    public function doRequest($method, $resource, $data, ServiceLocator $services)
    {
        // we commonly do a number of requests and don't want one failure to bork them all,
        // if anything goes wrong just log it
        try {
            // setup the client and request details
            $config = $this->getJiraConfig($services);
            $url    = $config['host'] . '/rest/api/latest/' . $resource;
            $client = new HttpClient;
            $client->setUri($url)
                   ->setHeaders(array('Content-Type' => 'application/json'))
                   ->setMethod($method);

            // set the http client options; including any special overrides for our host
            $options = $services->get('config') + array('http_client_options' => array());
            $options = (array) $options['http_client_options'];
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $client->setOptions($options);

            if ($method == 'post') {
                $client->setRawBody(Json::encode($data));
            } else {
                $client->setParameterGet((array) $data);
            }

            if ($config['user']) {
                $client->setAuth($config['user'], $config['password']);
            }

            // attempt the request and log any errors
            $services->get('logger')->info('JIRA making ' . $method . ' request to resource: ' . $url, (array) $data);
            $response = $client->dispatch($client->getRequest());
            if (!$response->isSuccess()) {
                $services->get('logger')->err(
                    'JIRA failed to ' . $method . ' resource: ' . $url . ' (' .
                    $response->getStatusCode() . " - " . $response->getReasonPhrase() . ').',
                    array(
                        'request'   => $client->getLastRawRequest(),
                        'response'  => $client->getLastRawResponse()
                    )
                );
                return false;
            }

            // looks like it worked, return the result
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $services->get('logger')->err($e);
        }

        return false;
    }

    /**
     * Get the project ids that are defined in JIRA from cache.
     *
     * @return  array   array of project ids in JIRA, empty array if cache is missing/empty
     */
    public function getProjects()
    {
        $file = DATA_PATH . '/cache/jira/projects';
        if (!file_exists($file)) {
            return array();
        }

        return (array) json_decode(file_get_contents($file), true);
    }

    /**
     * Get the path to write cache entries to. Ensures directory is writable.
     *
     * @return  string  the cache directory to write to
     * @throws  \RuntimeException   if the directory cannot be created or made writable
     */
    public function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/jira';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }

        return $dir;
    }

    /**
     * Normalize and return the JIRA portion of the system configuration.
     *
     * @param   ServiceLocator  $services   service locator to get at the config details
     * @return  array           normalized JIRA config details
     */
    public function getJiraConfig(ServiceLocator $services)
    {
        $config  = $services->get('config');
        $config  = isset($config['jira']) ? $config['jira'] : array();
        $config += array('host' => null, 'user' => null, 'password' => null, 'job_field' => null);

        $config['host']    = rtrim($config['host'], '/');
        if ($config['host'] && strpos(strtolower($config['host']), 'http') !== 0) {
            $config['host'] = 'http://' . $config['host'];
        }
        return $config;
    }

    /**
     * The config defaults.
     *
     * @return  array   the default config for this module
     */
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
