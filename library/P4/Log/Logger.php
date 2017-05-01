<?php
/**
 * Provides a static log method that will write to a zend logger
 * instance set via setLogger(). This gives predictable, singleton
 * access to a system-wide logger.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Log;

use P4\Exception;
use Zend\Log\Logger as ZendLogger;

class Logger
{
    const EMERG   = 0;  // Emergency: system is unusable
    const ALERT   = 1;  // Alert: action must be taken immediately
    const CRIT    = 2;  // Critical: critical conditions
    const ERR     = 3;  // Error: error conditions
    const WARN    = 4;  // Warning: warning conditions
    const NOTICE  = 5;  // Notice: normal but significant condition
    const INFO    = 6;  // Informational: informational messages
    const DEBUG   = 7;  // Debug: debug messages

    protected static $logger = null;

    /**
     * Private constructor to prevent instances from being created.
     *
     * @codeCoverageIgnore
     */
    final private function __construct()
    {
    }

    /**
     * Set the logger to use when logging.
     *
     * @param   null|ZendLogger     $logger     a zend log instance to log to or null to clear.
     * @throws  \InvalidArgumentException       if the given log is not a valid zend log.
     */
    public static function setLogger($logger)
    {
        if ($logger !== null && !$logger instanceof ZendLogger) {
            throw new \InvalidArgumentException(
                "Cannot set logger. The given logger is not a valid zend log instance."
            );
        }

        static::$logger = $logger;
    }

    /**
     * Get the logger to use when logging.
     *
     * @return  ZendLogger      the zend log instance to log to.
     * @throws  Exception       if there is no log instance set.
     */
    public static function getLogger()
    {
        if (!static::$logger instanceof ZendLogger) {
            throw new Exception(
                "Cannot get logger. No logger has been set."
            );
        }

        return static::$logger;
    }

    /**
     * Determine if a logger has been set.
     *
     * @return  bool    true if a logger has been set; false otherwise.
     */
    public static function hasLogger()
    {
        try {
            static::getLogger();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Log a message at a priority using the zend log
     * instance set via setLogger. If no logger has been
     * set, fails quietly.
     *
     * @param  string               $message   Message to log
     * @param  integer|null         $priority  Priority of message
     * @param  array|Traversable    $extras    Extra information to log in event
     */
    public static function log($priority, $message, $extras = array())
    {
        try {
            if ($priority === null) {
                $priority = self::INFO;
            }
            static::getLogger()->log($priority, $message, $extras);
        } catch (\Exception $e) {
            // don't let failure to log stop execution.
        }
    }

    /**
     * Log an exception. Logs a caller provided message (to give
     * context) with the exception message and type (as an error).
     * Also logs a backtrace (at debug priority).
     *
     * @param  string   $message    Message to log with the exception.
     * @param  integer  $exception  The exception that occured.
     */
    public static function logException($message, $exception)
    {
        // if caller failed to provide an exception object, just log
        // the message.
        if (!$exception instanceof \Exception) {
            static::log(static::ERR, $message);
            return;
        }

        static::log(
            static::ERR,
            $message . " " . get_class($exception) . ": " . $exception->getMessage()
        );
        static::log(
            static::DEBUG,
            "Backtrace:\n" . $exception->getTraceAsString()
        );
    }
}
