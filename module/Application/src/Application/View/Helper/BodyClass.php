<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\View\Helper\Placeholder\Container\AbstractStandalone;

class BodyClass extends AbstractStandalone
{
    /**
     * Registry key for placeholder
     * @var string
     */
    protected $regKey = 'Application_View_Helper_BodyClass';

    /**
     * Use space (' ') as the separator.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setSeparator(' ');
    }

    /**
     * Turn helper into string - extended to add escaping.
     *
     * @param  string|null $indent
     * @return string
     */
    public function toString($indent = null)
    {
        // if auto-escape is enabled, escape items prior to rendering
        if ($this->getAutoEscape()) {
            $escaper   = $this->getEscaper();
            $container = $this->getContainer();
            $original  = $container->getArrayCopy();
            $container->exchangeArray(array_map(array($escaper, 'escapeHtmlAttr'), $original));
        }

        $output = parent::toString($indent);

        // restore un-escaped copy
        if ($this->getAutoEscape()) {
            $container->exchangeArray($original);
        }

        return $output;
    }
}
