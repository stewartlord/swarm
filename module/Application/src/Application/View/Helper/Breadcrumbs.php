<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\View\Helper\AbstractHelper;

class Breadcrumbs extends AbstractHelper
{
    /**
     * Builds breadcrumbs for the given path and route.
     *
     * @param  string       $path       perforce path to breakup.
     * @param  string       $route      name of route to build urls with.
     * @param  array|null   $params     optional - additional params for the router
     * @return string       breadcrumb markup.
     */
    public function __invoke($path, $route, array $params = null)
    {
        $params = (array) $params;
        $view   = $this->getView();
        $crumbs = array_slice($this->splitPath($path), 0, -1);
        $html   = '<ul class="breadcrumb" '
                . 'data-path="' . rtrim($view->url('file', array('path' => $path)), '/') . '" '
                . 'data-url="' . rtrim($view->url($route, array('path' => $path) + (array) $params), '/') . '">';

        if ($path) {
            $html .= '<li><span class="divider">'
                  .  ' <a href="' . $view->url($route, $params) . '"><strong>//</strong></a>'
                  .  '</span></li>';
        } else {
            $html .= '<li class="active"><span class="divider">//</span></li>';
        }

        foreach ($crumbs as $crumb) {
            $html .= '<li>'
                  .  ' <a href="' . $view->url($route, array('path' => $crumb) + $params) . '">'
                  .    $view->decodeFilespec(basename($crumb)) . '</a>'
                  .  ' <span class="divider">/</span>'
                  .  '</li>';
        }

        if ($path) {
            $html .= '<li class="active">'
                  .   $view->decodeFilespec(basename($path))
                  .  '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Split path such that 'foo/bar/baz' produces an array of paths:
     *  'foo'
     *  'foo/bar'
     *  'foo/bar/baz'
     *
     * @param  string $path the path to split
     * @return array  list of paths in given path as described above
     */
    protected function splitPath($path)
    {
        $paths = array();
        $parts = explode('/', $path);
        for ($i = 1; $i <= count($parts); $i++) {
            $paths[] = implode('/', array_slice($parts, 0, $i));
        }

        return $paths;
    }
}
