<?php

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 403 (Not Authorized)
 *
 * I’m sorry. I know who you are–I believe who you say you
 * are–but you just don’t have permission to access this resource.
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