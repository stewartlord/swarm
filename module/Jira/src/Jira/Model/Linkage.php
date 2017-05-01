<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Jira\Model;

use Record\Key\AbstractKey as KeyRecord;

/**
 * A record, keyed on commit/review id, of what JIRA issues and jobs were associated with a given change/review.
 * Also records the title/summary to assist in noticing when values change even if impacted issues didn't.
 */
class Linkage extends KeyRecord
{
    const   KEY_PREFIX   = 'swarm-jiraLinkage-';

    const   FETCH_BY_JOB = 'job';

    public $fields       = array(
        'jobs'  => array(
            'accessor'  => 'getJobs',
            'mutator'   => 'setJobs',
            'index'     => 1501
        ),
        'issues' => array(
            'accessor'  => 'getIssues',
            'mutator'   => 'setIssues',
        ),
        'title' => array(
            'accessor'  => 'getTitle',
            'mutator'   => 'setTitle',
        ),
        'summary' => array(
            'accessor'  => 'getSummary',
            'mutator'   => 'setSummary',
        )
    );

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by various fields.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  supported options are:
     *                                  FETCH_BY_JOB - set to a 'job-id' value(s) to limit results
     * @param   Connection  $p4         the perforce connection to use
     * @return  \P4\Model\Fielded\Iterator   the list of zero or more matching Linkage objects
     */
    public static function fetchAll(array $options, $p4)
    {
        // normalize options
        $options += array(
            static::FETCH_BY_JOB => null,
        );

        // build the search expression
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            array(
                'jobs' => $options[static::FETCH_BY_JOB]
            )
        );

        return parent::fetchAll($options, $p4);
    }

    public function getJobs()
    {
        return (array) $this->getRawValue('jobs');
    }

    public function setJobs($jobs)
    {
        $jobs = array_values(array_unique(array_filter((array) $jobs, 'strlen')));
        sort($jobs);
        return $this->setRawValue('jobs', $jobs);
    }

    public function getIssues()
    {
        return (array) $this->getRawValue('issues');
    }

    public function setIssues($issues)
    {
        $issues = array_values(array_unique(array_filter((array) $issues, 'strlen')));
        sort($issues);
        return $this->setRawValue('issues', $issues);
    }

    public function getTitle()
    {
        return $this->getRawValue('title');
    }

    public function setTitle($title)
    {
        return $this->setRawValue('title', $title);
    }

    public function getSummary()
    {
        return $this->getRawValue('summary');
    }

    public function setSummary($summary)
    {
        return $this->setRawValue('summary', $summary);
    }
}
