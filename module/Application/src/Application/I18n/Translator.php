<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\I18n;

use Zend\Escaper\Escaper;
use Zend\EventManager\Event;
use Zend\Mvc\I18n\Translator as MvcTranslator;

class Translator extends MvcTranslator
{
    const CONTEXT_CHAR = "\x04";

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * Called by the missing translation event listener
     *
     * Checks if a gettext message context was specified in the message id, then
     * strips it out and attempts to get a context-less translation. Returns null
     * if no context-less translation is found.
     *
     * @param   Event       $event      Incoming missing translation event
     * @return  string|null
     */
    public function handleMissingTranslation(Event $event)
    {
        $message  = $event->getParam('message');
        $position = strpos($message, "\x04");

        if ($position !== false) {
            return $this->translate(
                substr($message, $position + 1),
                $event->getParam('text_domain'),
                $event->getParam('locale')
            );
        }
    }

    /**
     * Translate a string and replace variables using printf formatting.
     *
     * @param  string    $message        message to be translated
     * @param  array     $replacements   Optional - array of printf-style replacement values
     * @param  string    $context        Optional - context prefix for the message
     * @param  string    $textDomain     Optional - text domain for the message (default: "default")
     * @param  string    $locale         Optional - specify the given locale (instead of the current default)
     * @return string    translated string
     */
    public function translateReplace(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        $message = strlen($context) ? $context . self::CONTEXT_CHAR . $message : $message;
        $message = $this->translate($message, $textDomain, $locale);
        return $replacements ? vsprintf($message, $replacements) : $message;
    }

    /**
     * Translate and escape a string and replace variables using printf formatting.
     *
     * @param  string    $message        message to be translated
     * @param  array     $replacements   Optional - array of printf-style replacement values
     * @param  string    $context        Optional - context prefix for the message
     * @param  string    $textDomain     Optional - text domain for the message (default: "default")
     * @param  string    $locale         Optional - specify the given locale (instead of the current default)
     * @return string    translated string
     */
    public function translateReplaceEscape(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        return $this->escape($this->translateReplace($message, $replacements, $context, $textDomain, $locale));
    }

    /**
     * Translate a plural string and replace variables using printf formatting.
     *
     * If no replacements are specified, attempts to use $number as a replacement.
     *
     * @param  string    $singular      Singular version of the string
     * @param  string    $plural        Plural version of the string
     * @param  int       $number        Flag for deciding between plural and singular
     * @param  array     $replacements  Optional - array of replacement strings
     *                                  If null or empty, an array will be assembled from $number
     * @param  string    $context       Optional - context prefix for the message
     * @param  string    $textDomain    Optional - text domain for the message (default: "default")
     * @param  string    $locale        Optional - specify the given locale (instead of the current default)
     * @return string    The translated replaced plural or singular string
     */
    public function translatePluralReplace(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        $replacements = $replacements    ?: array($number);
        $singular     = strlen($context) ? $context . self::CONTEXT_CHAR . $singular : $singular;
        $plural       = strlen($context) ? $context . self::CONTEXT_CHAR . $plural   : $plural;

        $message = $this->translatePlural($singular, $plural, $number, $textDomain, $locale);
        return vsprintf($message, $replacements);
    }

    /**
     * Translate and escape a plural string and replace variables using printf formatting.
     *
     * If no replacements are specified, attempts to use $number as a replacement.
     *
     * @param  string    $singular      Singular version of the string
     * @param  string    $plural        Plural version of the string
     * @param  int       $number        Flag for deciding between plural and singular
     * @param  array     $replacements  Optional - array of replacement strings
     *                                  If null or empty, an array will be assembled from $number
     * @param  string    $context       Optional - context prefix for the message
     * @param  string    $textDomain    Optional - text domain for the message (default: "default")
     * @param  string    $locale        Optional - specify the given locale (instead of the current default)
     * @return string    The translated escaped replaced plural or singular string
     */
    public function translatePluralReplaceEscape(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        return $this->escape(
            $this->translatePluralReplace($singular, $plural, $number, $replacements, $context, $textDomain, $locale)
        );
    }

    /**
     * Alias for translateReplace()
     */
    public function t(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        return $this->translateReplace(
            $message,
            $replacements,
            $context,
            $textDomain,
            $locale
        );
    }

    /**
     * Alias for translatePluralReplace()
     */
    public function tp(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        return $this->translatePluralReplace(
            $singular,
            $plural,
            $number,
            $replacements,
            $context,
            $textDomain,
            $locale
        );
    }

    /**
     * Extend parent to ensure that messages are in array form when pluralizing
     *
     * Refer to Zend bugs:
     * - http://framework.zend.com/issues/browse/ZF-12547
     * - http://framework.zend.com/issues/browse/ZF-8816
     *
     * @param  string                         $singular
     * @param  string                         $plural
     * @param  int                            $number
     * @param  string                         $textDomain
     * @param  string|null                    $locale
     * @return string
     */
    public function translatePlural(
        $singular,
        $plural,
        $number,
        $textDomain = 'default',
        $locale = null
    ) {
        $locale = $locale ?: $this->getLocale();
        if (!isset($this->messages[$textDomain][$locale])) {
            $this->loadMessages($textDomain, $locale);
        }

        // parent requires plural messages to be held in an array to work correctly
        // ensure that if we have a message, it is an array (we'll set it back after)
        $original = isset($this->messages[$textDomain][$locale][$singular])
            ? $this->messages[$textDomain][$locale][$singular]
            : null;
        if ($original && !is_array($original)) {
            $this->messages[$textDomain][$locale][$singular] = array($original);
        }

        $translation = parent::translatePlural($singular, $plural, $number, $textDomain, $locale);

        // restore original message in case someone calls ->translate($singular) directly
        $this->messages[$textDomain][$locale][$singular] = $original;

        return $translation;
    }

    /**
     * Set the escaper service
     *
     * @param  Escaper  $escaper    service for escaping HTML strings
     * @return          $this
     */
    public function setEscaper(Escaper $escaper)
    {
        $this->escaper = $escaper;

        return $this;
    }

    /**
     * Helper to escape output as HTML
     *
     * @param  string   $string     The string to escape
     * @return string               The escaped string
     * @throws \RuntimeException    If the escaper has not been set using ->setEscaper($escaper)
     */
    protected function escape($string)
    {
        if (empty($this->escaper)) {
            throw new \RuntimeException('Translator component requires an escaper; none provided.');
        }

        return $this->escaper->escapeHtml($string);
    }

    /**
     * Extend parent to avoid using intl extension's bizarrely fluctuating default locale
     *
     * @return string|null
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * Check whether a locale is supported by attempting to load messages from it.
     *
     * @param string    $locale        locale to check for
     * @param string    $textDomain    optional - default "default"
     * @return bool
     */
    public function isSupportedLocale($locale, $textDomain = 'default')
    {
        $this->loadMessages($textDomain, $locale);
        return isset($this->messages[$textDomain][$locale]);
    }

    /**
     * Check if any of the configured translations support this language.
     *
     * @param string        $language       language to check for (e.g. 'en')
     * @param string|null   $textDomain     optional - defaults to "default"
     * @return bool|string  the first matching locale, or false if no matching locales
     */
    public function isSupportedLanguage($language, $textDomain = 'default')
    {
        // check to see if any translation files directly match the provided language prefix
        $files = isset($this->files[$textDomain]) ? $this->files[$textDomain] : array();
        foreach ($files as $locale => $file) {
            if (strpos($locale, $language) === 0) {
                return $locale;
            }
        }

        // check to see if any translation file patterns match the provided language prefix
        $patterns = isset($this->patterns[$textDomain]) ? $this->patterns[$textDomain] : array();
        foreach ($patterns as $pattern) {
            $baseDir = rtrim($pattern['baseDir'], '/');
            $matches = glob($baseDir . '/' . sprintf($pattern['pattern'], $language . '*')) ?: array();

            foreach ($matches as $match) {
                return preg_replace(
                    '#^' . $baseDir . '/' . sprintf($pattern['pattern'], '(' . $language . '.*)') . '#',
                    '$1',
                    $match
                );
            }
        }

        return false;
    }
}
