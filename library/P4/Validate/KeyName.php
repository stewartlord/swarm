<?php
/**
 * Validates string for suitability as a Perforce key name.
 *
 * By default disallows:
 *  - whitespace
 *  - purely numeric names
 *  - revision characters ('#', '@')
 *  - wildcards ('*', '...')
 *  - slashes ('/')
 *  - non-printable characters
 *  - leading minus ('-')
 *
 * By default allows, but can block:
 *  - percent character ('%')
 *  - positional specifiers ('%%x')
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

class KeyName extends AbstractValidate
{
    const INVALID_TYPE              = 'invalidType';
    const IS_EMPTY                  = 'isEmpty';
    const IS_NUMERIC                = 'isNumeric';
    const HAS_WHITESPACE            = 'hasSpaces';
    const REVISION_CHARACTERS       = 'revision';
    const WILDCARDS                 = 'wildcards';
    const LEADING_MINUS             = 'leadingMinus';
    const UNPRINTABLE_CHARACTERS    = 'unprintable';
    const SLASHES                   = 'slashes';
    const COMMAS                    = 'commas';
    const PERCENT                   = 'percent';
    const POSITIONAL_SPECIFIERS     = 'positional';
    const RELATIVE                  = 'relative';

    protected $allowLeadingDash     = false;    // DASHNUM
    protected $allowPurelyNumeric   = false;    // NNEGNUM
    protected $allowRevSpec         = false;    // REV
    protected $allowSlashes         = false;    // SLASH
    protected $allowRelative        = true;     // REL
    protected $allowWildcard        = false;    // WILD
    protected $allowPercent         = true;     // !NOTAPERCENT
    protected $allowPositional      = true;     // !NOPERCENT
    protected $allowCommas          = true;     // !NOCOMMA
    protected $messageTemplates     = array(
        self::INVALID_TYPE              => "Invalid type given.",
        self::IS_EMPTY                  => "Is an empty string.",
        self::IS_NUMERIC                => "Purely numeric values are not allowed.",
        self::HAS_WHITESPACE            => "Whitespace is not permitted.",
        self::REVISION_CHARACTERS       => "Revision characters ('#', '@') are not permitted.",
        self::WILDCARDS                 => "Wildcards ('*', '...') are not permitted.",
        self::LEADING_MINUS             => "First character cannot be minus ('-').",
        self::UNPRINTABLE_CHARACTERS    => "Unprintable characters are not permitted.",
        self::SLASHES                   => "Slashes ('/') are not permitted.",
        self::COMMAS                    => "Commas (',') are not permitted.",
        self::PERCENT                   => "Percent ('%') is not permitted.",
        self::POSITIONAL_SPECIFIERS     => "Positional specifiers ('%%x') are not permitted.",
        self::RELATIVE                  => "Relative paths are not permitted."
    );

    /**
     * Checks if the given string is a valid perforce spec name.
     *
     * @param   string|int  $value  spec name value to validate.
     * @return  boolean     true if value is a valid spec name, false otherwise.
     */
    public function isValid($value)
    {
        $this->set($value);

        // permit ints if allowPurelyNumeric is true.
        if ($this->allowPurelyNumeric && is_int($value)) {
            $value = (string) $value;
        }

        // test for valid type.
        if (!is_string($value)) {
            $this->error(static::INVALID_TYPE);
            return false;
        }

        // test for empty value.
        if ($value === '') {
            $this->error(static::IS_EMPTY);
            return false;
        }

        // test for leading minus ('-') character.
        if (!$this->allowLeadingDash && $value[0] === "-") {
            $this->error(static::LEADING_MINUS);
            return false;
        }

        // test for purely numeric name.
        if (!$this->allowPurelyNumeric && preg_match('/^[0-9]+$/', $value)) {
            $this->error(static::IS_NUMERIC);
            return false;
        }

        // test for unprintable characters.
        // 'isprint' defines printing characters an ASCII code greater than 0x1f, except 0x7f (DEL).
        // technically, that would mean 0x80+ is invalid but the server explicitly lets high-bitted values pass.
        if (preg_match('/[\x00-\x1F\x7f]/', $value)) {
            $this->error(static::UNPRINTABLE_CHARACTERS);
            return false;
        }

        // test for whitespace.
        if (preg_match('/\s/', $value)) {
            $this->error(static::HAS_WHITESPACE);
            return false;
        }

        // test for revision characters.
        if (!$this->allowRevSpec && preg_match('/@|#/', $value)) {
            $this->error(static::REVISION_CHARACTERS);
            return false;
        }

        // test for forward slash character.
        if (!$this->allowSlashes && strpos($value, '/') !== false) {
            $this->error(static::SLASHES);
            return false;
        }

        // If relative paths aren't allowed the following are blocked:
        //  two or more slashes after the first character
        //  containing '/./'
        //  containing '/../'
        //  ending in a slash (in 2+ character string)
        //  ending in '/.'
        //  ending in '/..'
        if (!$this->allowRelative && preg_match('#.+/$|.+//|/\./|/\.\./|.+/$|/\.$|/\.\.$#', $value)) {
            $this->error(static::RELATIVE);
            return false;
        }

        // test for wildcard characters.
        if (!$this->allowWildcard && preg_match('/\*|\.\.\./', $value)) {
            $this->error(static::WILDCARDS);
            return false;
        }

        // test for percent character
        if (!$this->allowPercent && strpos($value, '%') !== false) {
            $this->error(static::PERCENT);
            return false;
        }

        // test for positional specifiers.
        if (!$this->allowPositional && strpos($value, '%%') !== false) {
            $this->error(static::POSITIONAL_SPECIFIERS);
            return false;
        }

        // test for comma character
        if (!$this->allowCommas && strpos($value, ',') !== false) {
            $this->error(static::COMMAS);
            return false;
        }

        return true;
    }

    /**
     * Control if a leading - character is permitted.
     * Alias DASHNUM.
     *
     * @param  bool  $allowed  pass true to allow leading dash, false (default) to disallow.
     */
    public function allowLeadingDash($allowed)
    {
        $this->allowLeadingDash = (bool) $allowed;
    }

    /**
     * Control if purely numeric key names are permitted
     * (values consisting of only characters 0-9).
     * Alias NNEGNUM.
     *
     * @param  bool  $allowed  pass true to allow purely numeric names, false (default) to disallow.
     */
    public function allowPurelyNumeric($allowed)
    {
        $this->allowPurelyNumeric = (bool) $allowed;
    }

    /**
     * Control if revision specifiers are permitted (@ and # characters).
     * Alias REV.
     *
     * @param  bool  $allowed  pass true to allow rev specifiers, false (default) to disallow.
     */
    public function allowRevSpec($allowed)
    {
        $this->allowRevSpec = (bool) $allowed;
    }

    /**
     * Control if forward slashes '/' are permitted.
     * Alias SLASH.
     *
     * @param  bool  $allowed  pass true to allow forward slashes, false (default) to disallow.
     */
    public function allowSlashes($allowed)
    {
        $this->allowSlashes = (bool) $allowed;
    }

    /**
     * Control if forward relative paths are permitted (//, /., /./, /../, /.., trailing /)
     * Alias REL.
     *
     * @param  bool  $allowed  pass true (default) to allow relative, false to disallow.
     */
    public function allowRelative($allowed)
    {
        $this->allowRelative = (bool) $allowed;
    }

    /**
     * Control if wildcard sequences (* or ...) are permitted
     * Alias WILD.
     *
     * @param  bool  $allowed  pass true to allow positional specifiers, false (default) to disallow.
     */
    public function allowWildcard($allowed)
    {
        $this->allowWildcard = (bool) $allowed;
    }

    /**
     * Control if percent character '%' is permitted
     * Alias !NOTAPERCENT.
     *
     * @param  bool  $allowed  pass true (default) to allow percent '%', false to disallow.
     */
    public function allowPercent($allowed)
    {
        $this->allowPercent = (bool) $allowed;
    }

    /**
     * Control if positional sequence '%%' is permitted
     * Alias !NOPERCENT.
     *
     * @param  bool  $allowed  pass true (default) to allow percent '%%', false to disallow.
     */
    public function allowPositional($allowed)
    {
        $this->allowPositional = (bool) $allowed;
    }

    /**
     * Control if comma character ',' is permitted
     * Alias !NOCOMMA.
     *
     * @param  bool  $allowed  pass true (default) to allow commas ',', false to disallow.
     */
    public function allowCommas($allowed)
    {
        $this->allowCommas = (bool) $allowed;
    }
}
