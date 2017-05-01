<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Application\Escaper\Escaper;
use Zend\I18n\Exception;
use Zend\I18n\View\Helper\Translate as ZendTranslate;

class Translate extends ZendTranslate
{
    public function __invoke(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        $replacements = array_map(
            array($this->getView()->plugin('escapeHtml')->getEscaper(), 'escapeHtml'),
            (array) $replacements
        );

        return $this->translator->translateReplace($message, $replacements, $context, $textDomain, $locale);
    }
}
