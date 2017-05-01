<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Router;

class Regex extends \Zend\Mvc\Router\Http\Regex
{
    /**
     * Extend parent to preserve slashes '/'.
     *
     * @see    Route::assemble()
     * @param  array $params
     * @param  array $options
     * @return mixed
     */
    public function assemble(array $params = array(), array $options = array())
    {
        return str_ireplace('%2f', '/', parent::assemble($params, $options));
    }
}
