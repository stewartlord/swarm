<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\View\Helper;

use Projects\Model\Project;
use Zend\View\Helper\AbstractHelper;

class Reviews extends AbstractHelper
{
    /**
     * Returns the markup for the reviews queue page.
     *
     * @param   Project|string|null     $project    optional - limit reviews to a given project
     * @return  string                  the reviews queue page markup
     */
    public function __invoke($project = null)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');

        // get project model if given as string
        if (is_string($project)) {
            $project = Project::fetch($project, $p4Admin);
        }

        // at this point we should have a valid projet or null
        if ($project && !$project instanceof Project) {
            throw new \InvalidArgumentException(
                "Project must be a string id, project object or null."
            );
        }

        // construct options for branch/project filter
        // options are either name of projects if no project is given,
        // otherwise name of branches of the given project
        $options = array();
        if ($project) {
            $config    = $services->get('config');
            $mainlines = isset($config['projects']['mainlines']) ? (array) $config['projects']['mainlines'] : array();
            $branches  = $project->getBranches('name', $mainlines);
            $prefix    = $project->getId() . ':';
            foreach ($branches as $branch) {
                $options[$prefix . $branch['id']] = $branch['name'];
            }
        } else {
            $projects = Project::fetchAll(array(), $p4Admin);
            $options  = $projects->count()
                ? array_combine(
                    $projects->invoke('getId'),
                    $projects->invoke('getName')
                ) : array();
        }

        // prepare reviews markup
        $id         = $project ? $project->getId() : null;
        $class      = 'reviews' . ($project ? ' project-reviews' : '');
        $openedPane = $this->renderPane('opened', $id, $options);
        $closedPane = $this->renderPane('closed', $id, $options);

        $html = <<<EOT
            <div class="$class">
                <h1>{$view->te('Reviews')}</h1>

                <ul class="nav nav-tabs">
                    <li class="active">
                        <a href="#opened" data-toggle="tab">
                            {$view->te('Opened')} <span class="badge opened-counter">0</span>
                        </a>
                    </li>
                    <li>
                        <a href="#closed" data-toggle="tab">
                            {$view->te('Closed')} <span class="badge closed-counter">0</span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade active in" id="opened">
                        $openedPane
                    </div>
                    <div class="tab-pane fade" id="closed">
                        $closedPane
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                $(function(){
                    swarm.reviews.init();
                });
            </script>
EOT;

        return $html;
    }

    /**
     * Return markup for a given review pane (opened/closed).
     *
     * @param   string          $type       pane type - 'opened' or 'closed'
     * @param   string|null     $project    project id or null if not restricted to the project
     * @param   array           $projects   list of strings in the form of either 'project-id'
     *                                      or 'project-id:branch-id' for filtering by projects/branches
     * @return  string          markup for review pane
     */
    protected function renderPane($type, $project, array $projects)
    {
        $view = $this->getView();
        return $view->render(
            'reviews-pane.phtml',
            array(
                'type'     => $type,
                'project'  => $project,
                'projects' => $projects
            )
        );
    }
}
