<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Filter;

use Application\Escaper\Escaper;
use Zend\Filter\AbstractFilter;

class Linkify extends AbstractFilter
{
    protected $baseUrl;

    protected static $callbacks     = array();

    // blacklisted common terms (e.g. @todo) that are likely false-positives.
    protected static $blacklist     = array(
        '@see', '@todo', '@return', '@returns', '@param',
        '@throws', '@license', '@copyright', '@version'
    );

    /**
     * Require caller to explicitly specify base url as we can't guess reasonable
     * default value from this scope.
     *
     * @param   string  $baseUrl    base url to prepend to otherwise relative urls
     *                              created by this filter
     */
    public function __construct($baseUrl)
    {
        $this->setBaseUrl($baseUrl);
    }

    /**
     * Add a custom linkify callback. The passed 'callable' will be invoked
     * before the built in filters. It will receive the arguments:
     *  string  $trimmed    the trimmed word currently being process
     *  Escaper $escaper    the escaper that _must_ be used to sanitize result
     *  string  $last       the previous (trimmed) word or empty string
     *  string  $baseUrl    the pre-escaped base url (e.g. http://swarm, /path, etc)
     *
     * The escaper can return false to indicate it doesn't wish to linkify the
     * passed word. It can return a string to replace the word with a link e.g.:
     *  '<a href="/' . $escaper->escapeFullUrl($trimmed) . '">' . $escaper->escapeHtml($trimmed) . '</a>';
     * Note the string must be escaped!
     *
     * @param   callable    $callback   the callback to add
     * @param   string      $name       the index to add it under (replaces any existing entry)
     * @param   int|null    $min        the minimum input length to bother being called on or null/0 for all
     * @throws \InvalidArgumentException    if the passed callback or name are invalid
     */
    public static function addCallback($callback, $name, $min = 6)
    {
        if (!is_callable($callback) || !is_string($name) || !strlen($name) || !(is_int($min) || is_null($min))) {
            throw new \InvalidArgumentException(
                'Add callback expects a callable, a non-empty name and a min length to be passed.'
            );
        }

        static::$callbacks[$name] = array(
            'callback' => $callback,
            'min'      => $min
        );
    }

    /**
     * If a callback exists with the specified name it is removed. Otherwise no effect.
     */
    public static function removeCallback($name)
    {
        unset(static::$callbacks[$name]);
    }

    /**
     * Returns the specified callback callable or throws if the passed name is unknown/invalid.
     *
     * @param   string|null     $name       pass a string to get a specific callback or null for all
     * @return  callable|array  the requested callable or an array of all callbacks on null
     * @throws  \InvalidArgumentException   if a specific callback is requested but cannot be found
     */
    public static function getCallback($name = null)
    {
        if ($name === null) {
            $callables = array();
            foreach (static::$callbacks as $callable) {
                $callables[] = $callable['callback'];
            }

            return $callables;
        }

        if (!isset(static::$callbacks[$name])) {
            throw new \InvalidArgumentException(
                'Unknown callback name specified.'
            );
        }

        return static::$callbacks[$name]['callback'];
    }

    /**
     * Set the blacklisted terms array. Values should be in the format @foo.
     *
     * @param   array   $blacklist  an array of blacklisted terms to use
     * @throws \InvalidArgumentException
     */
    public static function setBlacklist($blacklist)
    {
        if (!is_array($blacklist) || in_array(false, array_map('is_string', $blacklist))) {
            throw new \InvalidArgumentException(
                'Blacklist must be an array of string values.'
            );
        }

        static::$blacklist = $blacklist;
    }

    public static function getBlacklist()
    {
        return static::$blacklist;
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path, etc) or null
     * @return  Linkfiy         to maintain a fluent interface
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * The base url that will be prepended to otherwise relative urls.
     *
     * @return  string|null     the base url to prepend (e.g. http://example.com, /path, etc) or null
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Returns a list of all unique @<value> and @*<value> callouts that could,
     * potentially, be user ids. It is up to the caller to validate the returned
     * values are users, items such as job ids, project ids, etc. could also be
     * included. Note by default both @ and @* entries are returned as a single
     * list, if you prefer to see just starred entries pass $onlyStarred = true.
     *
     * @param   string  $value          the text to scan for callouts
     * @param   bool    $onlyStarred    optional - only returned @*mention callouts
     * @return  array   an array of zero or more potential username callouts
     */
    public static function getCallouts($value, $onlyStarred = false)
    {
        $trimPattern    = '/^[”’"\'(<]*(.+?)[.”’"\'\,!?:;)>]*$/';
        $calloutPattern = '/^\@(?P<starred>\*?)(?P<value>[\w]+[\\\\\w\.\-]{0,253}[\w])$/i';
        $words          = preg_split('/(\s+)/', $value);
        $plain          = array();
        $starred        = array();
        foreach ($words as $word) {
            if (!strlen($word)) {
                continue;
            }

            // strip the leading/trailing punctuation from the actual word
            preg_match($trimPattern, $word, $matches);
            $word = $matches[1];

            // if the trimmed word isn't empty, matches our pattern and isn't black listed dig in more
            // if removing the leading @ leaves us with something that isn't purely numeric and we haven't
            // seen before it counts towards callouts.
            if (strlen($word)
                && preg_match($calloutPattern, $word, $matches)
                && !in_array($matches['value'], static::$blacklist)
                && !ctype_digit($matches['value'])
            ) {
                if ($matches['starred']) {
                    $starred[] = $matches['value'];
                } else {
                    $plain[]   = $matches['value'];
                }
            }
        }

        // return the unique entries we found, excluding plain entries if requested
        return $onlyStarred
            ? array_values(array_unique($starred))
            : array_values(array_unique(array_merge($plain, $starred)));
    }

    /**
     * Attempts to linkify the passed text for display in an html context.
     * Looks for:
     *  @1234               - change
     *  @job1234            - job
     *  @alphaNum           - user/project
     *  @*alphaNum          - required user
     *  @path/to/something  - for files/folders
     *  job123456           - job followed by 6 digits
     *  job 1234            - word job followed by number
     *  change 1            - word change followed by number
     *  review 1            - word review followed by number
     *  http[s]://whatever  - makes a clickable link
     *  ftp://whatever
     *  user@host.com
     *
     * @param  string   $value  un-escaped text to linkify.
     * @return string   escaped (for html context) and linkified result
     */
    public function filter($value)
    {
        // define the various regular expressions we will use
        $trimPattern  = '/^([”’"\'(<{\[]*)(.+?)([.”’"\'\,!?:;)>}\]]*)$/';
        $urlPattern   = '/^((?:http|https|ftp)\:\/\/(?:[\w-]+@)?(?:[\w.-]+)(?:\:[0-9]{1,6})?'
                      . '(?:\/[\w\.\-~!$&\'\(\)*+,;=:@?\/\#\:%]*[\w\-~!$\*+=@\/\#])?\/?)$/i';
        $emailPattern = '/^(([\w\-\.\+\'])+\@(?:[\w.-]+\.[a-z]{2,4}))$/i';
        $gotoPattern  = '/^(\@\*?([\w\/]+(?:[\\\\\w\/\.\-,!()\'%:#]*[\w\/]|[\w\/])?))$/i';
        $jobPattern   = '/^(job[0-9]{6})$/i';

        // determine the smallest callback min length to assist in filtering
        $callbackMin  = false;
        foreach (static::$callbacks as $callback) {
            if ($callbackMin === false || $callback['min'] < $callbackMin) {
                $callbackMin = $callback['min'];
            }
        }

        // scan over each word in the passed value. we queue up words till we hit
        // something that requires linkification to reduce the number of times we
        // have to call escapeHtml (speeds stuff up).
        $escaper = new Escaper;
        $words   = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $queue   = array();
        $escaped = '';
        $last    = '';
        $baseUrl = $this->baseUrl ? $escaper->escapeFullUrl(rtrim($this->baseUrl, '/')) : '';
        foreach ($words as $word) {
            // if its a whitespace hit, or empty just skip it
            $length = strlen($word);
            $first  = $length ? $word[0] : null;
            if ($length == 0 || $first == " " || $first == "\t" || $first == "\n") {
                $queue[] = $word;
                continue;
            }

            // determine if the input is a candidate for our builtin handlers
            $candidate = $length <= 256 &&
                (($first == "@" && $length >= 2) || ($first != "@" && $length >= 6)
                || ctype_digit($first));

            // or if its a candidate for a callback (assuming we have any)
            $callbackCandidate = $callbackMin !== false && $length >= $callbackMin && $length <= 256;
            $callbackHit       = false;

            // if it is a candidate or callback candidate do a bit more processing to trim it and update last word
            if ($candidate || $callbackCandidate) {
                // grab a copy of the last word for use this iteration and update last
                $lastWord = $last;

                // separate the leading punctuation, actual word and trailing punctuation to ease matching
                preg_match($trimPattern, $word, $matches);
                $pre      = $matches[1];
                $trimmed  = $matches[2];
                $post     = $matches[3];

                $last     = $trimmed;

                // update first/length to reflect our trimmed value
                $length   = strlen($trimmed);
                $first    = $length ? $trimmed[0] : null;
            }

            // if we determined callbacks were in play; give them a shot
            if ($callbackCandidate) {
                foreach (static::$callbacks as $callback) {
                    if ($length >= $callback['min']) {
                        $replace = $callback['callback']($trimmed, $escaper, $lastWord, $baseUrl);
                        if ($replace !== false) {
                            $trimmed     = $replace;
                            $callbackHit = true;
                            break;
                        }
                    }
                }
            }

            // if built-ins aren't a candidate and no callback hit, we're done
            if (!$candidate && !$callbackHit) {
                // just to catch excessively long words; otherwise we miss them
                $last    = $word;

                // add the word to the queue and carry on
                $queue[] = $word;
                continue;
            }

            // look for our various patterns, attempt to skip regex tests when possible
            if ($callbackHit) {
                // already handled; just skipping other checks
            } elseif ($length >= 10 && ($first == 'h' || $first == 'H' || $first == 'f' || $first == 'F')
                && preg_match($urlPattern, $trimmed, $matches)
            ) {
                $trimmed = '<a href="' . $escaper->escapeFullUrl(rawurldecode($matches[1])) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length >= 6 && preg_match($emailPattern, $trimmed, $matches)) {
                $trimmed = '<a href="mailto:' . $escaper->escapeFullUrl($matches[1]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length >= 2 && $first == '@' && preg_match($gotoPattern, $trimmed, $matches)
                && !in_array($trimmed, static::$blacklist)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $escaper->escapeFullUrl($matches[2]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length == 9 && ($first == 'j' || $first == 'J')
                && preg_match($jobPattern, $trimmed, $matches)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $escaper->escapeFullUrl($matches[1]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed
                && preg_match('/^change(list)?$/i', $lastWord)
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $trimmed . '">' . $trimmed . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed && strtolower($lastWord) == 'review'
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $trimmed . '">' . $trimmed . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed && strtolower($lastWord) == 'job'
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/jobs/' . $trimmed . '">' . $trimmed . '</a>';
            } else {
                $queue[] = $word;
                continue;
            }

            $queue[]  = $pre;
            $escaped .= $escaper->escapeHtml(implode('', $queue));
            $escaped .= $trimmed;
            $queue    = array($post);
        }

        return $escaped . $escaper->escapeHtml($escaped ? implode('', $queue) : $value);
    }
}
