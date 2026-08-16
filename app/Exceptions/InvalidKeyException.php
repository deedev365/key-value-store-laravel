<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Why a string could not become a Key.
 *
 * The named constructors exist so that the reason survives the throw: the
 * write endpoint reports these messages back to the caller verbatim, and
 * "key is invalid" would be a worse answer than "key is reserved by the API".
 */
final class InvalidKeyException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Key must not be empty.');
    }

    public static function reserved(string $key): self
    {
        return new self("Key '{$key}' is reserved by the API.");
    }

    public static function tooLong(int $maxLength): self
    {
        return new self("Key must not be longer than {$maxLength} characters.");
    }

    public static function malformed(): self
    {
        return new self('Key may only contain letters, digits, underscores, hyphens and dots.');
    }
}
