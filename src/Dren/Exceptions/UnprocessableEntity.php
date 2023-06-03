<?php

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 422
 *
 * Class UnprocessableEntityException
 * @package Dren\Exceptions
 */
class UnprocessableEntity extends Exception
{

    public function __construct(string $message = "", int $code = 422, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

}