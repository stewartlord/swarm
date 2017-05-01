<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Activity\View\Helper;

use Zend\View\Helper\AbstractHelper;

class Activity extends AbstractHelper
{
    /**
     * Returns the markup for an activity stream and injects a rss feed link.
     *
     * @param   string|null         $stream             optional - limit activity to named stream.
     * @param   string|bool|null    $type               optional - just show the specified event type
     *                                                  setting this option disables user-specified type filters.
     * @param   string              $classes            optional - list of additional classes to set on the table
     * @return  string              the activity stream html
     */
    public function __invoke($stream = null, $type = null, $classes = '')
    {
        $view   = $this->getView();

        // inject rss feed.
        $url    = $view->url($stream ? 'activity-stream-rss' : 'activity-rss', array('stream' => $stream));
        $title  = $view->t('Swarm Activity') . ($stream ? ' - ' . end(explode('-', $stream)) : '');
        $view->headLink()->appendAlternate($url, 'rss', $title);

        // prepare markup for filter buttons.
        $filters = $type ? '' : <<<EOT
            <ul class="pull-left nav nav-pills padw2">
                <li><a href="#" class="type-review">{$view->te('Reviews')}</a></li>
                <li><a href="#" class="type-change">{$view->te('Commits')}</a></li>
                <li><a href="#" class="type-comment">{$view->te('Comments')}</a></li>
                <li><a href="#" class="type-job">{$view->te('Jobs')}</a></li>
            </ul>
EOT;

        // prepare markup for activity markup.
        $classes   .= $stream ? ' stream-' . $stream : ' stream-global';
        $classes    = $view->escapeHtmlAttr($classes);
        $stream     = $stream ? "'" . $view->escapeJs($stream) . "'" : "";
        $type       = $view->escapeHtmlAttr(json_encode($type));
        $html       = <<<EOT
          <table class="$classes table activity-stream" data-type-filter="$type">
            <thead>
              <tr>
                <th colspan="2">
                  <div class="activity-dropdown pull-left">
                    <h4 class="dropdown-toggle" data-toggle="dropdown"
                        role="button" aria-haspopup="true" tabindex="0">
                      <span class="activity-title default-title">{$view->te('Activity')}</span>
                      <span class="caret hide"></span>
                    </h4>
                    <ul class="dropdown-menu" role="menu" aria-label="{$view->te('Activity to Display')}">
                      <li data-scope="all" role="menuitem"><a href="#">{$view->te('All Activity')}</a></li>
                      <li data-scope="user" role="menuitem">
                        <a href="#">{$view->te('Followed Activity')}</a>
                      </li>
                    </ul>
                  </div>
                  <div class="pull-right">
                    $filters
                    <a href="$url" class="rss-link pad2" title="RSS"><i class="swarm-icon icon-rss"></i></a>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
            </tbody>
          </table>

          <script type="text/javascript">
            $(function(){
              swarm.activity.init($stream);
              swarm.activity.load($stream);
            });

            $(window).scroll(function() {
              var activityStream = $('.activity-stream');
              // early exit for an explicit hide
              if (activityStream[0].style.display === 'none') {
                return;
              }

              var activityBottom = activityStream.offset().top + activityStream.outerHeight();
              if (($(window).scrollTop() >= activityBottom - $(window).height()) && activityStream.is(':visible')) {
                swarm.activity.load($stream);
              }
            });
          </script>
EOT;

        return $html;
    }
}
