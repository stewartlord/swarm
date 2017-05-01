<?php
/**
 * Provides access to information about the operating environment.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Environment;

class Environment
{
    protected static $shutdownRegistered    = false;
    protected static $shutdownCallbacks     = array();

    /**
     * Private constructor to prevent instances from being created.
     *
     * @codeCoverageIgnore
     */
    final private function __construct()
    {
    }

    /**
     * Determines whether or not we are running on a windows system
     * by checking for the PHP_WINDOWS_VERSION_MAJOR constant.
     *
     * @return boolean  whether or not we are running on a windows OS
     */
    public static function isWindows()
    {
        return defined("PHP_WINDOWS_VERSION_MAJOR");
    }

    /**
     * Returns the maximum number of bytes that can be used for arguments
     * when launching an application.
     *
     * @return int      bytes available for arguments
     */
    public static function getArgMax()
    {
        // if we are on windows early exit with 32k
        if (static::isWindows()) {
            return 32768;
        }

        // try getting a value via getconf;
        $argMax = `getconf ARG_MAX`;

        // if we didn't get a plain number back return 32k as a default
        if ($argMax !== (string)(int)$argMax) {
            return 32768;
        }

        return $argMax;
    }

    /**
     * Register a function to execute at shutdown.
     *
     * This provides useful abstraction of PHP's built-in
     * register_shutdown_function facility. This allows registered callbacks
     * to be inspected or executed arbitrarily (especially useful during
     * testing).
     *
     * @param   callable    $callback   the function to execute at shutdown.
     * @return  void
     */
    public static function addShutdownCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot add shutdown callback. Given callback is not callable."
            );
        }

        // if we haven't already done so, register
        // runShutdownCallbacks as a shutdown function.
        if (!static::$shutdownRegistered) {
            register_shutdown_function(
                get_called_class() . "::runShutdownCallbacks"
            );

            static::$shutdownRegistered = true;
        }

        static::$shutdownCallbacks[] = $callback;
    }

    /**
     * Get all of the registered shutdown callbacks.
     *
     * @return  array   the list of registered shutdown functions.
     */
    public static function getShutdownCallbacks()
    {
        return static::$shutdownCallbacks;
    }

    /**
     * Set all of the registered shutdown callbacks at once.
     *
     * This could be useful for clearing, re-ordering or otherwise
     * manipulating the list of registered shutdown callbacks.
     *
     * @param   array|null  $callbacks  functions to execute at shutdown.
     * @return  void
     */
    public static function setShutdownCallbacks(array $callbacks = null)
    {
        // start by clearing existing callbacks if present
        static::$shutdownCallbacks = array();

        // use add method to ensure callback is validated and our
        // runShutdownCallbacks method has an opportunity to register.
        foreach ($callbacks ?: array() as $callback) {
            static::addShutdownCallback($callback);
        }
    }

    /**
     * Execute all of the registered shutdown callbacks.
     *
     * Callbacks are run in the same order that they were registered.
     * Once a callback has been executed, it is removed from the shutdown
     * callbacks list.
     *
     * @return  void
     */
    public static function runShutdownCallbacks()
    {
        foreach (static::$shutdownCallbacks as $key => $callback) {
            // if callback is still callable, execute it.
            // as we run at shutdown it is possible, though unlikely,
            // a callback could go away between add and now.
            if (is_callable($callback)) {
                call_user_func($callback);
            }

            unset(static::$shutdownCallbacks[$key]);
        }
    }
}
