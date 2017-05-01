<?php

namespace P4;

if (!class_exists('\P4\Exception', false)) {

    /**
     * Exception to be thrown when an error occurs in the P4 package.
     *
     * If using the p4php extension, this class is already defined. To avoid
     * redefinition errors, we only define the class if it doesn't already exist.
     *
     * @copyright   2013-2016 Perforce Software. All rights reserved.
     * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
     * @version     2016.1/1400259
     * @todo        Remove this generic exception and use more specific one instead.
     */
    class Exception extends \Exception
    {
    }

}
