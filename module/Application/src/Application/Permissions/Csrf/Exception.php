<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Permissions\Csrf;

use Application\Permissions\Exception\ForbiddenException;

/**
 * This exception indicates the CSRF token is missing or invalid
 */
class Exception extends ForbiddenException
{
}
