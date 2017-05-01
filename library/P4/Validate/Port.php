<?php
/**
 * Validates p4 port value for valid hostname (if provided) and the presence
 * of a numeric port.
 *
 * The value should be in one of the following forms:
 *
 *            port only:  1666
 *    hostname and port:  perforce:1666
 *  ip address and port:  192.168.0.100:1666
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

class Port extends AbstractValidate
{
    const INVALID_PORT          = 'invalidPort';
    const INVALID_HOST          = 'invalidHost';
    const INVALID_RSH           = 'invalidRsh';

    protected $messageTemplates = array(
        self::INVALID_PORT  => "'%value%' does not appear to contain a valid numeric port.",
        self::INVALID_HOST  => "'%value%' appears to have a invalid hostname component.",
        self::INVALID_RSH   => "Value does not appear to be a valid 'rsh' port."
    );

    /**
     * Checks value for valid hostname (if present) and the
     * presence of a numeric port.
     *
     * @param   string   $value  port value to validate.
     * @return  boolean  true if port is in a valid format.
     */
    public function isValid($value)
    {
        $this->set($value);

        // split out protocol/host/port
        // the protocol is optional and only one of host/port need be present.
        $parts = explode(':', $value);

        // if we lead with a recognized protocol; strip it off
        // and continue validating the other components.
        if (in_array($parts[0], array("tcp", "tcp4", "tcp6", "ssl", "ssl4", "ssl6"))) {
            array_shift($parts);
        }

        // if we only have a single part it should be a port; pull it out.
        // if more parts are present we have an rsh or port/host style.
        if (count($parts) == 1) {
            $port = $parts[0];
        } else {
            // allow directly (inetd) invoked p4d
            if ($parts[0] == 'rsh') {
                // attempt to seperate out the 'p4d' executable path
                // if we get no result this is an invalid rsh port.
                $subParts = str_getcsv($parts[1], ' ');
                if (!isset($subParts[0])) {
                    $this->error(self::INVALID_RSH);
                    return false;
                }

                // run the specified executable and verify we get
                // back 'p4d' style response data
                exec('"' . escapeshellcmd($subParts[0]) . '" -V', $output);
                $output = implode("\n", $output);
                if (!preg_match("#^Rev. P4D/#m", $output)) {
                    $this->error(self::INVALID_RSH);
                    return false;
                }

                return true;
            }

            $host = $parts[0];
            $port = $parts[1];
        }

        // check that port is numeric.
        if (!is_numeric($port)) {
            $this->error(self::INVALID_PORT);
            return false;
        }

        // validate host if present.
        if (isset($host)) {
            // @see http://stackoverflow.com/questions/1418423/the-hostname-regex
            $pattern = '/^(?=.{1,255}$)[0-9A-Za-z](?:(?:[0-9A-Za-z]|\b-){0,61}'
                     . '[0-9A-Za-z])?(?:\.[0-9A-Za-z](?:(?:[0-9A-Za-z]|\b-)'
                     . '{0,61}[0-9A-Za-z])?)*\.?$/';

            if (!preg_match($pattern, $host)) {
                $this->error(self::INVALID_HOST);
                return false;
            } else {
                return true;
            }
        } else {
            return true;
        }
    }
}
