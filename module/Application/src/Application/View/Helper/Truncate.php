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
use Zend\Stdlib\StringUtils;

class Truncate extends AbstractHelper
{
    protected $value    = null;
    protected $length   = 0;

    /**
     * Trim the given text to the specified length.
     * Ellipsis are added as needed, but the final value won't exceed length.
     *
     * @param   string  $value  the text to truncate
     * @return  string  the truncated text
     */
    public function __invoke($value, $length)
    {
        $this->value  = $value;
        $this->length = $length;
        return $this;
    }

    /**
     * Turn helper into string
     *
     * @return string
     */
    public function __toString()
    {
        $utility = StringUtils::getWrapper();

        if ($utility->strlen($this->value) <= $this->length) {
            return $this->value;
        }

        return $utility->substr($this->value, 0, ($this->length - 3)) . '...';
    }
}
