<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files\View\Helper;

use Zend\View\Helper\AbstractHelper;

class FileSize extends AbstractHelper
{
    protected $suffixes = array('', 'K', 'M', 'G', 'T', 'P');

    /**
     * Converts the given filesize from bytes to a human-friendly format.
     * E.g. 12KB, 100MB
     *
     * @param   string|int  $size   the file size in bytes
     * @return  string      the formatted file size
     */
    public function __invoke($size)
    {
        $result = $size;
        $index  = 0;
        while ($result >= 1024 && $index++ < count($this->suffixes)) {
            $result = $result / 1024;
        }

        // 2 decimal points for sizes > MB.
        $precision = $index > 1 ? 2 : 0;
        $result    = round($result, $precision);

        return $result . " " . $this->suffixes[$index] . "B";
    }
}
