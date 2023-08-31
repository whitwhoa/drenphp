<?php
declare(strict_types=1);

namespace Dren\Exceptions;

use Exception;
use Throwable;

/**
 * For throwing 404
 *
 * Class NotFoundException
 * @package Dren\Exceptions
 */
class NotFound extends Exception
{

    public function __construct(string $message = "", int $code = 404, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

}