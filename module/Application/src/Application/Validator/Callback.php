<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Validator;

use Zend\Validator;

/**
 * Extends the basic callback validator to add support for
 * returning an error message in the callback function.
 */
class Callback extends Validator\Callback
{
    /**
     * Returns true if the callback returns true. If the callback returns
     * false (or a string), this method returns false. If a string is
     * returned by the callback, that string will be used as the message.
     *
     * @param  mixed $value
     * @param  mixed $context Additional context to provide to the callback
     * @return boolean
     * @throws Exception\InvalidArgumentException
     */
    public function isValid($value, $context = null)
    {
        // wrap original callback in another to support string returns
        $validator  = $this;
        $original   = $this->getCallback();
        $messageKey = static::INVALID_VALUE;
        $callback   = function () use ($original, $validator, $messageKey) {
            $args   = func_get_args();
            $result = call_user_func_array($original, $args);
            if (is_string($result)) {
                $validator->setMessage($result, $messageKey);
                return false;
            }
            return $result;
        };

        // install our wrapping callback.
        $this->setCallback($callback);

        // let parent do its thing
        $result = parent::isValid($value, $context);

        // restore original callback
        $this->setCallback($original);

        return $result;
    }

    /**
     * Extends parent to add printf syntax support
     *
     * Constructs and returns a validation failure message with the given message key and value.
     *
     * Returns null if and only if $messageKey does not correspond to an existing template.
     *
     * If a translator is available and a translation exists for $messageKey,
     * the translation will be used.s
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
     * Adds support for extra replacement variables in callback messages
     *
     * Called auto-magically by setOptions
     *
     * @param array|null    $messageVariables   Array of possible replacement message values
     */
    protected function setMessageVariables($messageVariables)
    {
        foreach ($messageVariables as $key => $value) {
            $this->abstractOptions['messageVariables'][$key] = $key;
            $this->{$key} = is_array($value) ? implode(', ', $value) : $value;
        }
    }
}
