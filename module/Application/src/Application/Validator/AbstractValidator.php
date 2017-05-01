<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Validator;

use Zend\Validator\AbstractValidator as ZendAbstractValidator;

abstract class AbstractValidator extends ZendAbstractValidator
{
    /**
     * Extends parent to add printf syntax support
     *
     * Constructs and returns a validation failure message with the given message key and value.
     *
     * Returns null if and only if $messageKey does not correspond to an existing template.
     *
     * If a translator is available and a translation exists for $messageKey,
     * the translation will be used.
     *
     * @param  string              $messageKey
     * @param  string|array|object $value
     * @return string
     */
    protected function createMessage($messageKey, $value)
    {
        // temporarily disable message length (length limiting is now handled in postCreateMessage)
        $originalMessageLength = $this->getMessageLength();
        $this->setMessageLength(-1);
        $message = parent::createMessage($messageKey, $value);
        $this->setMessageLength($originalMessageLength);

        return AbstractValidator::postCreateMessage($message, $value, $this);
    }

    /**
     * Provides printf-formatted variable replacement in validator strings.
     *
     * @param string                $message    the localized string
     * @param mixed                 $value      the user-provided value
     * @param ZendAbstractValidator $validator  contains properties/messageVariables
     * @return null|string
     */
    public static function postCreateMessage($message, $value, ZendAbstractValidator $validator)
    {
        // handle case of no message template
        if ($message === null) {
            return null;
        }

        // copied from parent::createMessage
        if (is_object($value) &&
            !in_array('__toString', get_class_methods($value))
        ) {
            $value = get_class($value) . ' object';
        } elseif (is_array($value)) {
            $value = var_export($value, 1);
        } else {
            $value = (string) $value;
        }

        if ($validator->isValueObscured()) {
            $value = str_repeat('*', strlen($value));
        }

        // inspired by parent::createMessage, reworked to build replacements array for vsprintf
        // we can examine all these properties, protected or not, thanks to a magic __get() method
        $replacements = array((string) $value);
        foreach ($validator->abstractOptions['messageVariables'] as $ident => $property) {
            if (is_array($property)) {
                $value = $validator->{key($property)}[current($property)];
                if (is_array($value)) {
                    $value = '[' . implode(', ', $value) . ']';
                }
            } else {
                $value = $validator->$property;
            }
            $replacements[] = (string) $value;
        }

        $message = vsprintf($message, $replacements);

        // length limiting logic copied from parent
        $length  = self::getMessageLength();
        if (($length > -1) && (strlen($message) > $length)) {
            $message = substr($message, 0, ($length - 3)) . '...';
        }

        return $message;
    }
}
