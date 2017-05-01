<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Projects\View\Helper;

use Projects\Model\Project;
use Zend\View\Helper\AbstractHelper;

class ProjectToolbar extends AbstractHelper
{
    /**
     * Returns the markup for a project toolbar.
     *
     * @param   Project|string      $project    the project to render toolbar for
     * @return  string              markup for the project toolbar
     */
    public function __invoke($project)
    {
        $view        = $this->getView();
        $services    = $view->getHelperPluginManager()->getServiceLocator();
        $permissions = $services->get('permissions');
        $event       = $services->get('Application')->getMvcEvent();
        $route       = $event->getRouteMatch()->getMatchedRouteName();
        $mode        = $event->getRouteMatch()->getParam('mode');

        // get project model if project is passed via id
        if (!$project instanceof Project) {
            $p4Admin = $services->get('p4_admin');
            $project = Project::fetch($project, $p4Admin);
        }

        // declare project links
        $links = array(
            array(
                'label'  => 'Overview',
                'url'    => $view->url('project', array('project' => $project->getId())),
                'active' => $route === 'project',
                'class'  => 'overview-link'
            ),
            array(
                'label'  => 'Reviews',
                'url'    => $view->url('project-reviews', array('project' => $project->getId())),
                'active' => $route === 'project-reviews' || $route === 'review',
                'class'  => 'review-link'
            )
        );

        // add links to view projects files and history if projects has (any) branches
        if (count($project->getBranches())) {
            $links[] = array(
                'label'  => 'Files',
                'url'    => $view->url('project-browse', array('project' => $project->getId(), 'mode' => 'files')),
                'active' => $route === 'project-browse' && $mode === 'files',
                'class'  => 'browse-link'
            );
            $links[] = array(
                'label'  => 'Commits',
                'url'    => $view->url('project-browse', array('project' => $project->getId(), 'mode' => 'changes')),
                'active' => $route === 'project-browse' && $mode === 'changes',
                'class'  => 'history-link'
            );
        }

        // add a jobs link if project has a job filter set.
        if (trim($project->get('jobview'))) {
            $links[] = array(
                'label'  => 'Jobs',
                'url'    => $view->url('project-jobs', array('project' => $project->getId())),
                'active' => $route === 'project-jobs',
                'class'  => 'jobs'
            );
        }

        // add project settings link if user has permission
        $canEdit = $project->hasOwners()
            ? $permissions->isOne(array('admin', 'owner'  => $project))
            : $permissions->isOne(array('admin', 'member' => $project));
        if ($canEdit) {
            $links[] = array(
                'label'  => 'Settings',
                'url'    => $view->url('edit-project', array('project' => $project->getId())),
                'active' => $route === 'edit-project',
                'class'  => 'settings'
            );
        }

        // render list of links
        $list = '';
        foreach ($links as $link) {
            $list .= '<li class="' . ($link['active'] ? 'active' : '') . '">'
                  .  '<a href="' . $link['url'] . '" class="' . $link['class'] . '">'
                  . $view->te($link['label'])
                  . '</a>'
                  .  '</li>';
        }

        // render project toolbar
        $name = $view->escapeHtml($project->getName());
        $url  = $view->url('project',  array('project' => $project->getId()));
        return '<div class="profile-navbar project-navbar navbar" data-project="' . $project->getId() . '">'
             . ' <div class="navbar-inner">'
             . '  <a class="brand" href="' . $url . '">' . $name . '</a>'
             . '  <ul class="nav">' . $list . '</ul>'
             . ' </div>'
             . '</div>';
    }
}
