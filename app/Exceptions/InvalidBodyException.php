<?php

namespace App\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Why a request body could not become a WriteBody.
 *
 * Each reason carries the field the write endpoint reports it under, because
 * the API distinguishes them: a malformed envelope is a `body` error, an
 * unusable property name is a `key` error, and over-deep nesting is a `value`
 * error. Collapsing them into one field would tell the caller less than the
 * API already knows.
 */
final class InvalidBodyException extends InvalidArgumentException
{
    private function __construct(
        public readonly string $field,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function tooDeep(int $maxDepth): self
    {
        return new self('value', "Value must not be nested more than {$maxDepth} levels deep.");
    }

    public static function notAnObject(): self
    {
        return new self('body', 'Request body must be a JSON object, e.g. {"mykey": "value1"}.');
    }

    public static function notASinglePair(): self
    {
        return new self('body', 'Request body must contain exactly one key-value pair, e.g. {"mykey": "value1"}.');
    }

    /**
     * The envelope was well formed, but its property name is not a usable key.
     * The reason is Key's, so it is passed through verbatim rather than
     * restated here.
     */
    public static function invalidKey(InvalidKeyException $reason): self
    {
        return new self('key', $reason->getMessage(), $reason);
    }
}
