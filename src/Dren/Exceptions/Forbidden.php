<?php
declare(strict_types=1);

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 403 (Forbidden)
 *
 * you aren’t authenticated–either not authenticated at all
 * or authenticated incorrectly–but please reauthenticate and try again.
 *
 * Class ForbiddenException
 * @package Dren\Exceptions
 */
class Forbidden extends Exception
{

    public function __construct(string $message = "", int $code = 403, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

}