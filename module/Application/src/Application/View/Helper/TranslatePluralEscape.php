<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\I18n\Exception;
use Zend\I18n\View\Helper\TranslatePlural as ZendTranslatePlural;

class TranslatePluralEscape extends ZendTranslatePlural
{
    public function __invoke(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        return $this->translator->translatePluralReplaceEscape(
            $singular,
            $plural,
            $number,
            $replacements,
            $context,
            $textDomain,
            $locale
        );
    }
}
