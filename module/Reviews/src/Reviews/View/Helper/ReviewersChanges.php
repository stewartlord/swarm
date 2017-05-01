<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Reviews\View\Helper;

use Zend\View\Helper\AbstractHelper;

class ReviewersChanges extends AbstractHelper
{
    protected $changes   = null;
    protected $baseUrl   = null;
    protected $plainText = false;

    /**
     * Entry point for helper. See __toString() for rendering behavior.
     *
     * @param   array   $changes    array of reviewer modifications to describe
     * @return  ReviewersChange     provides a fluent interface
     */
    public function __invoke($changes)
    {
        $this->changes = $changes;
        return $this;
    }

    /**
     * Given a list of changes to reviewers, describes what happened.
     * If the changes list is empty, returns an empty string.
     *
     * The basic style output (which will all combine into one flowing paragraph) is:
     *
     * Singular:
     * Made slord a required reviewer.
     * Made slord an optional reviewer.
     * Removed slord from the review.
     *
     * Plural:
     * Made slord and dmountney required reviewers.
     * Made slord and dmountney optional reviewers.
     * Removed slord and dmountney from the review.
     *
     * @return  string  the formatted list of reviewers changes, empty string if none
     */
    public function __toString()
    {
        $description = '';
        $changes     = (array) $this->changes + array(
            'addedOptional' => null,
            'addedRequired' => null,
            'madeRequired'  => null,
            'madeOptional'  => null,
            'removed'       => null
        );

        // normalize each entry to an array of unique values
        foreach ($changes as $type => $value) {
            $changes[$type] = array_unique((array) $value);
        }

        // a given user should only be reported once.
        // we favor reporting a user as 'required' over 'optional'
        // we favor reporting as 'added' over 'made' (ie. modified)
        // lastly, we report removed users that are not listed elsewhere
        $addedRequired = $changes['addedRequired'];
        $madeRequired  = array_diff($changes['madeRequired'], $addedRequired);
        $addedOptional = array_diff($changes['addedOptional'], $addedRequired, $madeRequired);
        $madeOptional  = array_diff($changes['madeOptional'], $addedRequired, $madeRequired, $addedOptional);
        $removed       = array_diff($changes['removed'], $addedRequired, $madeRequired, $addedOptional, $madeOptional);

        // we specifically want the service-level translator to avoid escaping replacements
        $translator = $this->getView()->plugin('t')->getTranslator();

        if ($addedRequired) {
            $description .= $translator->tp(
                "Added %s as a required reviewer.",
                "Added %s as required reviewers.",
                count($addedRequired),
                array($this->listUsers($addedRequired))
            ) . ' ';
        }

        if ($madeRequired) {
            $description .= $translator->tp(
                "Made %s a required reviewer.",
                "Made %s required reviewers.",
                count($madeRequired),
                array($this->listUsers($madeRequired))
            ) . ' ';
        }

        if ($addedOptional) {
            $description .= $translator->tp(
                "Added %s as an optional reviewer.",
                "Added %s as optional reviewers.",
                count($addedOptional),
                array($this->listUsers($addedOptional))
            ) . ' ';
        }

        if ($madeOptional) {
            $description .= $translator->tp(
                "Made %s an optional reviewer.",
                "Made %s optional reviewers.",
                count($madeOptional),
                array($this->listUsers($madeOptional))
            ) . ' ';
        }

        if ($removed) {
            $description .= $translator->t(
                "Removed %s from the review.",
                array($this->listUsers($removed))
            ) . ' ';
        }

        // restore default settings after each 'run'
        $this->changes   = null;
        $this->baseUrl   = null;
        $this->plainText = false;

        return trim($description);
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path, etc) or null
     * @return  ReviewersChange to maintain a fluent interface
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
     * Set the output mode to render description as plain-text or html.
     *
     * @param   bool    $plainText      true for plain-text output - defaults to false for html
     * @return  ReviewersChange to maintain a fluent interface
     */
    public function setPlainText($plainText)
    {
        $this->plainText = (bool) $plainText;
        return $this;
    }

    /**
     * The current plain-text setting.
     *
     * @return  bool    true for plain-text output, false for html
     */
    public function getPlainText()
    {
        return $this->plainText;
    }

    /**
     * List out the specified user(s). Turning them into links if they are valid
     * ids (unless we were asked for plain text output).
     *
     * If a single user id its just returned decorated.
     * If two are specified we list them as: user1 and user2
     * If more than two we list them as: user1, user2, user3 and user4
     *
     * @param   array   $users      the users to list out
     * @return  string  the list of users, escaped rendered html (unless in plain-text mode)
     */
    protected function listUsers($users)
    {
        natcasesort($users);

        if (!$this->plainText) {
            foreach ($users as &$user) {
                $user = $this->getView()->userLink($user, false, $this->getBaseUrl());
            }
        }

        if (count($users) == 1) {
            return end($users);
        }

        // we can and should escape the 'and' if not building plain-text
        $translator = $this->getView()->plugin($this->getPlainText() ? 't' : 'te');

        return implode(', ', array_slice($users, 0, -1)) . ' ' . $translator('and') . ' ' . end($users);
    }
}
