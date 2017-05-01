<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\Model;

use P4\Connection\ConnectionInterface as Connection;
use Record\Key\AbstractKey;

/**
 * Provides storage for metadata on individual files in reviews (e.g. read/unread state).
 *
 * Records are keyed by review id and the depotFile hash. This allows for easy lookup of
 * all files in a review or a specific file. For example:
 *
 * swarm-fileInfo-54321-b1946ac92492d2347c6235b4d2611184
 */
class FileInfo extends AbstractKey
{
    const KEY_PREFIX        = 'swarm-fileInfo-';
    const FETCH_BY_REVIEW   = 'review';

    protected $fields       = array(
        'review',
        'depotFile',
        'readBy'    => array(
            'accessor'  => 'getReadBy',
            'mutator'   => 'setReadBy'
        )
    );

    /**
     * Extends save to set a default id based on review and depotFile.
     *
     * @return  AbstractKey     to maintain a fluent interface
     */
    public function save()
    {
        if (!$this->getId()) {
            $this->setId(static::composeId($this->get('review'), $this->get('depotFile')));
        }

        return parent::save();
    }

    /**
     * Get the list of users that have marked this file as 'read' (and at what version/digest)
     *
     * Optionally, a specific version and digest may be specified to limit the returned results.
     *
     * @param   null|int    $version    optional, limit users to those who have read a specific version
     * @param   null|string $digest     optional, limit users to those who have read a specific digest
     * @return  array       list of users that have read this file with version/digest info.
     */
    public function getReadBy($version = null, $digest = null)
    {
        $readBy = $this->normalizeReadBy($this->getRawValue('readBy'));
        if ($version === null && $digest === null) {
            return $readBy;
        }

        $filtered = array();
        foreach ($this->getReadBy() as $user => $read) {
            if (!strcasecmp($read['digest'], $digest) || $read['version'] > $version) {
                $filtered[$user] = $read;
            }
        }

        return $filtered;
    }

    /**
     * Set the list of users that have marked this file as 'read' (and at what version/digest)
     *
     * @param   array       $readBy     list of users as keys with array values containing version and digest entries
     * @return  FileInfo    provides fluent interface
     */
    public function setReadBy($readBy)
    {
        if (!is_null($readBy) && count($readBy) != count($this->normalizeReadBy($readBy))) {
            throw new \InvalidArgumentException(
                "Read by value must be an array of string keys with array values containing version and digest"
            );
        }
        return $this->setRawValue('readBy', $this->normalizeReadBy($readBy));
    }

    /**
     * Helper to check if a given file/version has been read by a given user.
     *
     * If the digest matches, we consider that 'read'.
     * If the given version is older than the 'read' version we consider that read.
     * Otherwise, we consider it not read.
     *
     * @param   string  $user       the user to check for
     * @param   int     $version    the version to check for
     * @param   string  $digest     the file digest to check for
     * @return  bool    true if the user has read that version or digest, false otherwise
     */
    public function isReadBy($user, $version, $digest)
    {
        return array_key_exists($user, $this->getReadBy($version, $digest));
    }

    /**
     * Helper to easily mark the file as read by a given user at a particular digest/version
     *
     * @param   string      $user       the user to mark as read by
     * @param   int         $version    the version to mark as read
     * @param   string      $digest     the file digest to mark as read
     * @return  FileInfo    provides fluent interface
     */
    public function markReadBy($user, $version, $digest)
    {
        return $this->setReadBy(
            array($user => array('version' => $version, 'digest' => $digest)) + $this->getReadBy()
        );
    }

    /**
     * Helper to easily remove a user from the readBy field.
     *
     * @param   string      $user       the user to remove
     * @return  FileInfo    provides fluent interface
     */
    public function clearReadBy($user)
    {
        $readBy = $this->getReadBy();
        unset($readBy[$user]);

        return $this->setReadBy($readBy);
    }

    /**
     * Adds an option to fetch all file info records for a given review.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  (see AbstractKey for additional options):
     *                                    FETCH_BY_REVIEW - get file info records for given review
     * @param   Connection  $p4         the perforce connection to run on
     * @return  FieldedIterator         the list of zero or more matching record objects
     * @throws  \InvalidArgumentException   invalid combinations of options
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        if (isset($options[static::FETCH_BY_REVIEW])) {
            $review = $options[static::FETCH_BY_REVIEW];
            $review = $review instanceof Review ? $review->getId() : $review;
            if (!ctype_digit((string) $review)) {
                throw new \InvalidArgumentException(
                    "Cannot fetch file info records. Review id must be purely numeric."
                );
            }
            if (isset($options[static::FETCH_BY_IDS])) {
                throw new \InvalidArgumentException(
                    "Cannot fetch file info records. FETCH_BY_REVIEW cannot be combined with FETCH_BY_IDS."
                );
            }

            $options[static::FETCH_BY_IDS] = array($review . '-*');
        }

        return parent::fetchAll($options, $p4);
    }

    /**
     * Convenience method to fetch all file info records for a given review.
     *
     * @param   Review|string|int   $review     the review to fetch file info records for
     * @param   Connection          $p4         the perforce connection to run on
     * @return  FieldedIterator     the list of zero or more matching record objects
     */
    public static function fetchAllByReview($review, Connection $p4)
    {
        return static::fetchAll(array(static::FETCH_BY_REVIEW => $review), $p4);
    }

    /**
     * Generate id from review id and depot file path.
     *
     * @param   string|int  $review     a purely numeric id
     * @param   string      $depotFile  a depot file path
     * @return  string      the review id and depotFile hash separated by a hyphen
     * @throws  \InvalidArgumentException   if review id or depot file look invalid
     */
    public static function composeId($review, $depotFile)
    {
        // must have valid looking review and depotFile
        if (!ctype_digit((string) $review) || !strlen($depotFile)) {
            throw new \InvalidArgumentException(
                "Cannot compose id. Must specify a depot file and a purely numeric review id."
            );
        }

        return (string) $review . '-' . md5($depotFile);
    }

    /**
     * Normalize 'readBy' data to a consistent format
     *
     * Ensure it is an array where each element has a string key (for the username)
     * and an array value with elements 'version' and 'digest'. Invalid data is dropped.
     *
     * @param   array   $readBy     the list of usernames with digest/version info
     * @return  array   the input with any malformed data removed
     */
    protected function normalizeReadBy($readBy)
    {
        if (!is_array($readBy)) {
            return array();
        }

        foreach ($readBy as $key => $value) {
            $value += array('digest' => null);

            if (!is_string($key)
                || !is_array($value)
                || !array_key_exists('version', $value)
                || !ctype_digit((string) $value['version'])
                || (strlen($value['digest']) && !preg_match('/[a-f0-9]{32}/i', $value['digest']))
            ) {
                unset($readBy[$key]);
                continue;
            }

            // we allow purely numeric strings for version, but we want them to be ints
            $readBy[$key]['version'] = (int) $value['version'];

            // make digests consistently uppercase and ensure empty strings are null
            $readBy[$key]['digest'] = strlen($value['digest']) ? strtoupper($value['digest']) : null;
        }

        ksort($readBy);
        return $readBy;
    }
}
