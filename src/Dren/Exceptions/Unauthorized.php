<?php

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 401 (Unauthorized)
 *
 * I’m sorry. I know who you are–I believe who you say you
 * are–but you just don’t have permission to access this resource.
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