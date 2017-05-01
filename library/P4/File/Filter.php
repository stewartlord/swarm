<?php
/**
 * Constructs fstat filter expressions specifically for filtering
 * files via fetchAll().
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo    Figure out how to search for empty string as field value
 */

namespace P4\File;

class Filter
{
    const CONNECTIVE_AND        = '&';
    const CONNECTIVE_AND_NOT    = '&^';
    const CONNECTIVE_OR         = '|';
    const CONNECTIVE_OR_NOT     = '|^';

    const COMPARE_EQUAL         = '=';
    const COMPARE_NOT_EQUAL     = '!=';
    const COMPARE_CONTAINS      = '~';
    const COMPARE_NOT_CONTAINS  = '!~';
    const COMPARE_REGEX         = '~=';
    const COMPARE_NOT_REGEX     = '!~=';
    const COMPARE_GT            = '>';
    const COMPARE_LT            = '<';
    const COMPARE_GTE           = '>=';
    const COMPARE_LTE           = '<=';

    const GROUP_OPEN            = '(';
    const GROUP_CLOSE           = ')';

    const LOGICAL_NOT           = '^';

    protected $conditions       = array();

    /**
     * The constructor accepts an optional existing string filter as the intial condition.
     *
     * @param  string  $stringFilter  An existing string filter.
     */
    public function __construct($stringFilter = null)
    {
        if (is_string($stringFilter)) {
            $this->conditions[] = array(
                'field'             => $stringFilter,
                'value'             => '',
                'comparison'        => '',
                'connective'        => static::CONNECTIVE_AND,
                'caseInsensitive'   => null
            );
        }
    }

    /**
     * Creates and returns a new Filter class. Useful for nesting conditions or working
     * around PHP's lack of new chaining.
     *
     * @param   string  $stringFilter  An existing string filter.
     * @return  Filter
     */
    public static function create($stringFilter = null)
    {
        return new static($stringFilter);
    }

    /**
     * Add a fstat field condition to the filter.
     *
     * @param   string              $field      Fstat field to filter on
     * @param   null|string|array   $value      Value we are comparing to as string, null or array
     *                                          of strings. If an array is given, condition will
     *                                          pass if any of the values satisfy the comparison.
     * @param   string              $comparison optional - comparison operator to use, defaults to Equal
     * @param   string              $connective optional - logical connective operator
     * @param   null|boolean        $caseInsensitive  optional - case-insensitive matching preference, default to null.
     * @return  Filter              To maintain a fluent interface.
     */
    public function add(
        $field,
        $value,
        $comparison = self::COMPARE_EQUAL,
        $connective = self::CONNECTIVE_AND,
        $caseInsensitive = null
    ) {
        return $this->addFstat($field, $value, $comparison, $connective, $caseInsensitive);
    }

    /**
     * Add a fstat field condition to the filter.
     * Implemented as a protected as extenders will likely shift meaning of 'add' function
     * but we need a reliable, locatable, low level copy.
     *
     * @param   string              $field      Fstat field to filter on
     * @param   null|string|array   $value      Value we are comparing to as string, null or array
     *                                          of strings. If an array is given, condition will
     *                                          pass if any of the values satisfy the comparison.
     * @param   string              $comparison optional - comparison operator to use, defaults to Equal
     * @param   string              $connective optional - logical connective operator
     * @param   null|boolean        $caseInsensitive  optional - case-insensitive matching preference, default to null.
     * @return  Filter              To maintain a fluent interface.
     */
    protected function addFstat(
        $field,
        $value,
        $comparison = self::COMPARE_EQUAL,
        $connective = self::CONNECTIVE_AND,
        $caseInsensitive = null
    ) {
        if (!is_string($field) || !strlen($field)) {
            throw new \InvalidArgumentException(
                "Cannot add condition. Field must be a non-empty string."
            );
        }

        if ((is_array($value) && !count($value))) {
            throw new \InvalidArgumentException(
                "Cannot add condition. Value must be null, a string or an array of strings."
            );
        }

        if (!is_array($value) && !is_string($value) && $value !== null) {
            throw new \InvalidArgumentException(
                "Cannot add condition. Value must be null, a string or an array of strings."
            );
        }

        if (!in_array($comparison, static::getComparisonOperators())) {
            throw new \InvalidArgumentException(
                "Cannot add condition. Invalid comparison operator specified."
            );
        }

        if (!isset($connective)) {
            $connective = static::CONNECTIVE_AND;
        }
        if (!in_array($connective, static::getConnectiveOperators())) {
            throw new \InvalidArgumentException(
                "Cannot add condition. Invalid connective specified."
            );
        }

        // if value is an array, create a sub filter and compare field
        // against each value using connective or's
        if (is_array($value)) {
            $values = $value;

            // one last check the values array has valid entries
            if (count(array_filter($values, 'is_string')) != count($values)) {
                throw new \InvalidArgumentException(
                    "Cannot add condition. Value array must contain only strings."
                );
            }

            // if the comparison is negated, we assume the caller wants
            // to match things NOT IN this set - therefore we invert the
            // comparison and move the negation to the connective.
            if (static::isNegatedOperator($comparison)) {
                $comparison = static::getInvertedOperator($comparison);
                $connective = static::getInvertedOperator($connective);
            }

            // create and glue on sub-filter
            $subFilter = new static;
            foreach ($values as $value) {
                $subFilter->_add($field, $value, $comparison, static::CONNECTIVE_OR);
            }
            $this->addSubFilter($subFilter, $connective);

            return $this;
        }

        $this->conditions[] = array(
            'field'             => $field,
            'value'             => $value,
            'comparison'        => $comparison,
            'connective'        => $connective,
            'caseInsensitive'   => $caseInsensitive
        );

        return $this;
    }

    /**
     * Add a group of conditions to this filter.
     *
     * @param   Filter  $filter         The sub-filter to add
     * @param   string  $connective     optional - logical connective operator
     * @return  Filter  To maintain a fluent interface.
     */
    public function addSubFilter($filter, $connective = self::CONNECTIVE_AND)
    {
        if (!$filter instanceof Filter) {
            throw new \InvalidArgumentException(
                "Cannot add sub-filter. Invalid type passed."
            );
        }

        if (!in_array($connective, static::getConnectiveOperators())) {
            throw new \InvalidArgumentException(
                "Cannot add sub-filter. Invalid connective specified."
            );
        }

        $this->conditions[] = array(
            'filter'            => $filter,
            'connective'        => $connective,
            'caseInsensitive'   => null
        );

        return $this;
    }

    /**
     * Generate fstat filter expression.
     *
     * @return  string  The generated fstat filter expression.
     * @todo    Do not escape wildcards when performing a 'like' comparison, when job039375 fixed.
     */
    public function getExpression()
    {
        $expression = '';

        foreach ($this->conditions as $condition) {
            // turn array key/value pairs into named variables
            extract($condition);

            // skip empty sub-filters.
            $isSubFilter   = array_key_exists('filter', $condition);
            $subExpression = $isSubFilter ? $filter->getExpression() : null;
            if ($isSubFilter && empty($subExpression)) {
                continue;
            }

            // add in the connective if this isn't the first condition
            // if it is the first condition, and the connective is negated
            // start the expression with a logical not.
            if ($expression !== '') {
                $expression .= ' ' . $connective . ' ';
            } elseif (static::isNegatedOperator($connective)) {
                $expression = static::LOGICAL_NOT;
            }

            // if this condition is a sub-filter, wrap sub-expression in group operators
            if ($isSubFilter) {
                $expression .= static::GROUP_OPEN . $subExpression . static::GROUP_CLOSE;
                continue;
            }

            // escape the provided value so it is safe to use in a filter clause.
            $value = ($comparison === static::COMPARE_REGEX || $comparison === static::COMPARE_NOT_REGEX)
                ? $this->escapeForRegex($condition['value'])
                : $this->escapeForEquals($condition['value']);

            // produce a null value so we can match empty/null attributes,
            // but only when we have a comparison.
            if ($value === '' and $comparison !== '') {
                $comparison = static::COMPARE_REGEX;
                $value = $this->escapeForRegex('^$');
            }

            // Perforce doesn't support '!=' style operators, so we must
            // switch the operator over to positive and prepend a logical not.
            if ($comparison === static::COMPARE_NOT_EQUAL
                || $comparison === static::COMPARE_NOT_REGEX
                || $comparison === static::COMPARE_NOT_CONTAINS
            ) {
                $expression .= static::LOGICAL_NOT;
                if ($comparison === static::COMPARE_NOT_EQUAL) {
                    $comparison = static::COMPARE_EQUAL;
                } elseif ($comparison === static::COMPARE_NOT_REGEX) {
                    $comparison = static::COMPARE_REGEX;
                } elseif ($comparison === static::COMPARE_NOT_CONTAINS) {
                    $comparison = static::COMPARE_CONTAINS;
                }
            }

            // convert equals and contains operators to regex because it is
            // more accurate (not all characters can be escaped for the equals
            // operator and it doesn't support case insensitive comparisons).
            // to convert equals, we must bind to the start/end of the value
            // to ensure a literal match.
            if ($comparison === static::COMPARE_EQUAL) {
                $comparison = static::COMPARE_REGEX;
                $value      = $this->escapeForRegex('^')
                            . $value
                            . $this->escapeForRegex('$');
            } elseif ($comparison === static::COMPARE_CONTAINS) {
                $comparison = static::COMPARE_REGEX;
            }

            // when we are matching and ignoring case, we need to compose a
            // suitable regex. since we may have character classes in the
            // provided regex, we track bracketing and try to behave sensibly
            // while adding [Aa] atoms to the regex where appropriate.
            if ($caseInsensitive && $comparison === static::COMPARE_REGEX) {
                $newValue     = '';
                $bracketLevel = 0;
                $escape       = false;
                foreach (str_split($value) as $char) {
                    if ($char === '[' and !$escape) {
                        $bracketLevel++;
                    }
                    if ($char === ']' and !$escape) {
                        if ($bracketLevel-- < 0) {
                            $bracketLevel = 0;
                        }
                    }
                    if (preg_match('/[a-zA-Z]/', $char)) {
                        $startBracket = $bracketLevel > 0 ? '' : '[';
                        $endBracket = $bracketLevel > 0 ? '' : ']';
                        $char = $startBracket . strtoupper($char) . strtolower($char) . $endBracket;
                    }

                    // check for escape characters, set escape state accordingly.
                    $escape = $char === '\\'
                        ? ($escape ? false : true)
                        : false;
                    $newValue .= $char;
                }
                $value = $newValue;
            }

            // glue on the field/comparison/value to our running expression
            $expression .= $field . $comparison . $value;
        }

        return $expression;
    }

    /**
     * Escape the given value for use in a filter expression in order to return
     * literal matches.
     *
     * @param   string  $value  The value to escape for use in an equals filter.
     * @return  string  The escaped value.
     */
    public function escapeForEquals($value)
    {
        // Escape anything other than alpha/numeric in value string.
        // As we're using regex-based filtering throughout, we need to use multiple passes
        // of escaping for certain characters when using COMPARE_LIKE/COMPARE_NOT_LIKE
        $regexes = array(
            '/([^a-zA-Z0-9])/',        // escaping non-alphanumeric chars is fairly obvious
            '/([\n\r$^*()\\[\\]|?])/', // double-escaping common regex characters is required
            '/([\n\r$^()\\[\\]|])/'    // triple-escaping these characters is required
        );

        return preg_replace($regexes, '\\\$1', $value);
    }

    /**
     * Escape the given value for use in a filter expression in order to return
     * regex matches.
     *
     * @param   string  $value  The value to escape for use in a regex filter.
     * @return  string  The escaped value.
     */
    public function escapeForRegex($value)
    {
        return preg_replace('/([^a-zA-Z0-9*\[\]?.+])/', '\\\$1', $value);
    }

    /**
     * Automatically generate filter expression when cast to a string.
     *
     * @return  string  The generated fstat filter expression.
     */
    public function __toString()
    {
        return $this->getExpression();
    }

    /**
     * Get a list of all known connective operators.
     *
     * @return  array   All known connective values
     */
    public static function getConnectiveOperators()
    {
        return array(
            static::CONNECTIVE_AND,
            static::CONNECTIVE_AND_NOT,
            static::CONNECTIVE_OR,
            static::CONNECTIVE_OR_NOT
        );
    }

    /**
     * Get a list of all known comparison operators.
     *
     * @return  array   All known comparison values
     */
    public static function getComparisonOperators()
    {
        return array(
            static::COMPARE_EQUAL,
            static::COMPARE_NOT_EQUAL,
            static::COMPARE_CONTAINS,
            static::COMPARE_NOT_CONTAINS,
            static::COMPARE_REGEX,
            static::COMPARE_NOT_REGEX,
            static::COMPARE_GT,
            static::COMPARE_LT,
            static::COMPARE_GTE,
            static::COMPARE_LTE
        );
    }

    /**
     * Check if the given operator is negated.
     *
     * @param   string  $operator           a connective or comparison operator.
     * @throws  \InvalidArgumentException   if the given operator is invalid.
     */
    public static function isNegatedOperator($operator)
    {
        $operators = array_merge(
            static::getComparisonOperators(),
            static::getConnectiveOperators()
        );
        if (!in_array($operator, $operators)) {
            throw new \InvalidArgumentException(
                "Cannot determine if operator is negated. Invalid operator specified."
            );
        }

        return in_array(
            $operator,
            array(
                static::COMPARE_NOT_EQUAL,
                static::COMPARE_NOT_CONTAINS,
                static::COMPARE_NOT_REGEX,
                static::CONNECTIVE_AND_NOT,
                static::CONNECTIVE_OR_NOT
            )
        );
    }

    /**
     * Invert the given operator.
     *
     * @param   string  $operator           a connective or comparison operator to invert.
     * @throws  \InvalidArgumentException   if the given operator cannot be inverted.
     */
    public static function getInvertedOperator($operator)
    {
        switch ($operator) {
            case static::COMPARE_EQUAL:
                return static::COMPARE_NOT_EQUAL;
            case static::COMPARE_NOT_EQUAL:
                return static::COMPARE_EQUAL;
            case static::COMPARE_CONTAINS:
                return static::COMPARE_NOT_CONTAINS;
            case static::COMPARE_NOT_CONTAINS:
                return static::COMPARE_CONTAINS;
            case static::COMPARE_REGEX:
                return static::COMPARE_NOT_REGEX;
            case static::COMPARE_GT:
                return static::COMPARE_LT;
            case static::COMPARE_LT:
                return static::COMPARE_GT;
            case static::COMPARE_GTE:
                return static::COMPARE_LTE;
            case static::COMPARE_LTE:
                return static::COMPARE_GTE;
            case static::CONNECTIVE_AND:
                return static::CONNECTIVE_AND_NOT;
            case static::CONNECTIVE_AND_NOT:
                return static::CONNECTIVE_AND;
            case static::CONNECTIVE_OR:
                return static::CONNECTIVE_OR_NOT;
            case static::CONNECTIVE_OR_NOT:
                return static::CONNECTIVE_OR;
            default:
                throw new \InvalidArgumentException(
                    "Cannot invert operator. Invalid operator given."
                );
        }
    }
}
