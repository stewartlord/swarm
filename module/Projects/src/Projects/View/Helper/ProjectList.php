<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Projects\View\Helper;

use Projects\Filter\ProjectList as ProjectListFilter;
use Zend\View\Helper\AbstractHelper;

class ProjectList extends AbstractHelper
{
    const NO_LINK   = 'noLink';
    const BASE_URL  = 'baseUrl';
    const STYLE     = 'style';

    /**
     * Returns the markup for a project/branch list
     *
     * @param   array|string|null   $projects   the projects/branches to list
     * @param   string|null         $active     the active project if applicable
     * @param   array|null          $options      NO_LINK - disable linking to the project
     *                                           BASE_URL - set to a string to prepend links with a baseUrl
     *                                              STYLE - set to a string with custom styles for the link
     * @return  string              the project list html
     */
    public function __invoke($projects = null, $active = null, $options = null)
    {
        $options    = (array) $options + array(static::NO_LINK => false, static::BASE_URL => '', static::STYLE => '');
        $filter     = new ProjectListFilter;
        $projects   = $filter->filter($projects);
        $view       = $this->getView();
        $justBranch = false;
        $style      = $options[static::STYLE]
            ? ' style="' . $view->escapeHtmlAttr($options[static::STYLE]) . '"'
            : '';
        $baseUrl    = $view->escapeFullUrl($options[static::BASE_URL]);

        // we don't need to output the project id if we have an active project
        // with at least one branch and there are no other projects.
        if (strlen($active) && isset($projects[$active]) && count($projects[$active]) > 0 && count($projects) == 1) {
            $justBranch = true;
        }

        // generate a list of project-branch names. we will later implode with ', ' to join them
        $names = array();
        foreach ($projects as $project => $branches) {
            // if no branches for this project, just render 'project-id'
            if (!$branches) {
                if ($options[static::NO_LINK]) {
                    $names[] = $view->escapeHtml($project);
                } else {
                    $names[] = '<a href="'
                        . $baseUrl . $view->url('project', array('project' => $project))
                        . '"' . $style .'>'
                        . $view->escapeHtml($project)
                        . '</a>';
                }
                continue;
            }

            // if we have branches render each of them out. prefixed with project-id: if needed.
            foreach ($branches as $branch) {
                if ($options[static::NO_LINK]) {
                    $names[] = (!$justBranch ? $view->escapeHtml($project) . ':' : '')
                        . $view->escapeHtml($branch);
                } else {
                    $names[] = '<a href="'
                        . $baseUrl . $view->url('project', array('project' => $project))
                        . '"' . $style .'>'
                        . (!$justBranch ? $view->escapeHtml($project) . ':' : '')
                        . $view->escapeHtml($branch)
                        . '</a>';
                }
            }
        }

        return implode(", \n", $names);
    }
}
