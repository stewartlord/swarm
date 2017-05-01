<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Application\Filter\ShortenStackTrace as Filter;
use Zend\View\Helper\AbstractHelper;

class ShortenStackTrace extends AbstractHelper
{
    /**
     * Strips the base-path from a stack trace to shorten the individual lines.
     *
     * @param  string $trace the stack trace to shorten
     * @return string the shortened stack trace with stripped base paths
     */
    public function __invoke($trace)
    {
        $filter = new Filter;
        return $filter->filter($trace);
    }
}
