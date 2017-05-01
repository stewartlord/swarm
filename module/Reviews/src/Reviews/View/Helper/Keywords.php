<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\View\Helper;

use Reviews\Filter\Keywords as Filter;
use Zend\View\Helper\AbstractHelper;

class Keywords extends AbstractHelper
{
    /**
     * If the caller passes an argument we'll strip all keywords and return the modified value.
     * If no arguments are passed the keyword filter is returned allowing access to other methods.
     *
     * @param   string|null     $value  if a value is passed, it will be stripped of keywords and returned
     * @return  string|Filter   if a value was passed, the stripped version otherwise a Keyword Filter object
     */
    public function __invoke($value = null)
    {
        $view     = $this->getView();
        $services = $view->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config');
        $config   = isset($config['reviews']['patterns']) ? $config['reviews']['patterns'] : array();
        $filter   = new Filter($config);

        // if an argument was passed; simply filter it
        if (func_num_args() > 0) {
            return $filter($value);
        }

        // if no arguments return the filter to allow caller access to other methods
        return $filter;
    }
}
