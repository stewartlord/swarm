<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Projects\View\Helper;

use Zend\View\Helper\AbstractHelper;

class ProjectsSidebar extends AbstractHelper
{
    /**
     * Returns the markup for a projects stream.
     *
     * @return  string  the projects stream html
     */
    public function __invoke()
    {
        $view = $this->getView();
        $html = <<<EOT
            <table class="table table-bordered tbody-bordered projects-sidebar">
                <thead>
                    <tr>
                        <th>
                            <div class="projects-dropdown pull-left">
                                <h4 role="button" tabIndex="0" aria-haspopup="true"
                                    class="dropdown-toggle" data-toggle="dropdown">
                                    <span class="projects-title">{$view->te("Projects")}</span>
                                    <span class="caret privileged"></span>
                                </h4>
                                <ul class="dropdown-menu" role="menu" aria-label="{$view->te("Projects to Display")}">
                                    <li data-scope="all" role="menuitem">
                                        <a href="#">{$view->te("All Projects")}</a>
                                    </li>
                                    <li data-scope="user" role="menuitem">
                                        <a href="#">{$view->te("My Projects")}</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="project-add pull-right project-add-restricted">
                                <a href="{$view->url('add-project')}"
                                   title="{$view->te("Add Project")}"
                                   aria-label="{$view->te("Add Project")}">
                                    <i class="icon-plus"></i>
                                </a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <script type="text/javascript">
                $(function(){
                    swarm.projects.init('.projects-sidebar');
                });
            </script>
EOT;

        return $html;
    }
}
