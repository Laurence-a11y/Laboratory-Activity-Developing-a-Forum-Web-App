<?php
declare(strict_types=1);

namespace App\Services;

/** A user-correctable input problem; the message is safe to show. */
final class ValidationException extends \RuntimeException
{
}
