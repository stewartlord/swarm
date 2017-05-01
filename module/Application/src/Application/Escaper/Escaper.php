<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Escaper;

use P4\Filter\Utf8 as Utf8Filter;

class Escaper extends \Zend\Escaper\Escaper
{
    protected $fullUrlCorrectionMap = array('%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':', '%40' => '@',
                                            '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$', '%23' => '#');

    protected $utf8Filter           = null;

    public function __construct($encoding = null)
    {
        $this->utf8Filter = new Utf8Filter;

        parent::__construct($encoding);
    }

    /**
     * Designed to escape a full url e.g. http://perforce.com/test.html
     * Escapement logic is based on javascript's encodeURI method.
     *
     * @param   string  $url    the url to be escaped
     * @return  string  the escaped url
     */
    public function escapeFullUrl($url)
    {
        return strtr($this->escapeUrl($url), $this->fullUrlCorrectionMap);
    }

    /**
     * Extends parent escapeHtml to strip bad byte sequences when ENT_SUBSTITUTE
     * isn't a supported option.
     *
     * @param   string  $string
     * @return  string  the escaped string
     */
    public function escapeHtml($string)
    {
        // if we are on php 5.3 and in utf-8 mode scrub the value we can
        // skip this on php 5.4 as ENT_SUBSTITUTE can handle bad sequences.
        if (!defined('ENT_SUBSTITUTE') && $this->getEncoding() == 'utf-8') {
            $string = $this->utf8Filter->filter($string);
        }

        return parent::escapeHtml($string);
    }

    /**
     * Extends parent to strip bad byte sequences present in utf-8 input.
     *
     * @param   string  $string     the string to convert to utf-8
     * @return  string  string in utf-8 format
     */
    protected function toUtf8($string)
    {
        // allow null; parent normally blows up for it
        if ($string === null) {
            return '';
        }

        // if we are expecting utf-8 input, remove bad sequences
        if ($this->getEncoding() == 'utf-8') {
            $string = $this->utf8Filter->filter($string);
        }

        return parent::toUtf8($string);
    }
}
