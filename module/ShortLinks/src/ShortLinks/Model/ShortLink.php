<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace ShortLinks\Model;

use P4\Connection\ConnectionInterface as Connection;
use Record\Exception\NotFoundException;
use Record\Key\AbstractKey as KeyRecord;

/**
 * Provides persistent storage and indexing of short links.
 */
class ShortLink extends KeyRecord
{
    const KEY_PREFIX    = 'swarm-shortLink-';
    const KEY_COUNT     = 'swarm-shortLink:count';

    protected $fields   = array(
        'uri' => array(
            'index'     => 1401,
            'accessor'  => 'getUri',
            'mutator'   => 'setUri'
        )
    );

    /**
     * Retrieve a specific record by URI. Throws if an unknown URI is given.
     *
     * @param   string|int      $uri    the URI to look for
     * @param   Connection      $p4     the connection to use
     * @return  ShortLink       the corresponding short-link record
     * @throws  NotFoundException   on unknown URI
     */
    public static function fetchByUri($uri, Connection $p4)
    {
        $uri     = static::normalizeEncoding($uri);
        $search  = static::makeSearchExpression(array('uri' => $uri));
        $matches = static::fetchAll(array(static::FETCH_SEARCH => $search), $p4);

        if (!$matches->count()) {
            throw new NotFoundException("Cannot fetch entry. URI does not exist.");
        }

        return $matches->first();
    }

    /**
     * Retrieve a specific record by obfuscated id.
     *
     * Obfuscated ids are a little strange. Ultimately they are incrementing numbers,
     * but they are obfuscated for appearances. In order to make the external ids less
     * predictable and to make small numbers longer while making big numbers shorter,
     * we use a combination of md5 and base-36 conversion.
     *
     * The first three characters of the id are the first three characters of the URI's
     * md5 hash converted to base-36. This makes ids less predictable and makes small
     * numbers longer. The remaining characters are the number itself in base-36.
     * This makes big numbers shorter.
     *
     * @param   string      $id     the user facing md5 (in base-36) part + base-36 id
     * @param   Connection  $p4     the connection to use
     * @return  ShortLink   the corresponding short-link record
     * @throws  NotFoundException   on unknown id
     */
    public static function fetchByObfuscatedId($id, Connection $p4)
    {
        $obfuscatedId = $id;
        $storedId     = static::clarifyId($obfuscatedId);
        $record       = static::fetch($storedId, $p4);

        // verify the obfuscated id matches - this is needed to validate the
        // first few characters which are a hash of the URI on the record
        if ($obfuscatedId !== static::obfuscateId($storedId, $record->get('uri'))) {
            throw new NotFoundException("Cannot fetch entry. Id does not exist.");
        }

        return $record;
    }

    /**
     * We want to ensure that URIs are consistently encoded before we store them
     * and before we return them. This method checks for any characters that
     * should be escaped (but are not). If it finds any, it re-encodes the URI.
     *
     * The encoding rules are based on javascript's encodeURI method.
     *
     * @param   string  $uri    the URI to encode if necessary
     * @return  string  the URI with encoding enforced
     */
    protected static function normalizeEncoding($uri)
    {
        // if URI only consist of alphanumeric characters, allowed special characters
        // and valid escape sequences, it needs no further encoding.
        $special = preg_quote(';,/?:@&=+$-_.!~*\'()#', '/');
        if (!preg_match('/[^a-z0-9' . $special . '%]/i', $uri) && !preg_match('/%(?![0-9a-f]{2})/i', $uri)) {
            return $uri;
        }

        // we detected some characters that should really be escaped, but were not
        // decode the URL (in case it was partially encoded) and then re-encode it
        $escaper = new \Application\Escaper\Escaper;
        return $escaper->escapeFullUrl(rawurldecode($uri));
    }

    /**
     * Qualifies the given URI with the given origin (scheme://host[:port])
     * If the URI already has an origin, it will be returned as-is.
     *
     * @param   string  $uri        the URI to qualify
     * @param   string  $origin     scheme, host and optional port to use
     * @return  the qualified/absolute URI
     */
    public static function qualifyUri($uri, $origin)
    {
        // if the given uri already starts with http(s)://, no need to qualify it
        if (preg_match('#^https?://#i', $uri)) {
            return $uri;
        }

        return rtrim($origin, '/') . '/' . ltrim($uri, '/');
    }

    public static function obfuscateId($id, $uri)
    {
        if (!ctype_digit((string) $id) || !strlen($uri)) {
            throw new \InvalidArgumentException(
                "Cannot obfuscate id. Id must be purely numeric and URI must be a non-empty string."
            );
        }

        $md5   = substr(base_convert(md5($uri), 16, 36), 0, 3);
        $value = base_convert($id, 10, 36);

        return $md5 . $value;
    }

    public static function clarifyId($id)
    {
        // must be at least 4 chars
        if (strlen($id) < 4) {
            throw new \InvalidArgumentException("Cannot clarify id. Id is invalid (too short).");
        }

        // first 3 chars are the md5-part in base-36, the rest are the base-36 encoded id
        $id = substr($id, 3);
        $id = base_convert($id, 36, 10);

        return $id;
    }

    public function getObfuscatedId()
    {
        return static::obfuscateId($this->getId(), $this->get('uri'));
    }

    public function getUri()
    {
        return static::normalizeEncoding($this->getRawValue('uri'));
    }

    public function setUri($uri)
    {
        return parent::setRawValue('uri', static::normalizeEncoding($uri));
    }
}
