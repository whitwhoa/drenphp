<?php

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 401 (Not Authenticated)
 *
 * you aren’t authenticated–either not authenticated at all
 * or authenticated incorrectly–but please reauthenticate and try again.
 *
 * Class UnauthorizedException
 * @package Dren\Exceptions
 */
class Unauthorized extends Exception
{

   public function __construct(string $message = "", int $code = 401, Throwable $previous = null)
   {
       parent::__construct($message, $code, $previous);
   }

}