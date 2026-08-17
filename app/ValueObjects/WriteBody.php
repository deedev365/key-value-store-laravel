<?php

namespace App\ValueObjects;

use App\Exceptions\InvalidBodyException;
use App\Exceptions\InvalidKeyException;
use stdClass;

/**
 * The body of a write: a single-property JSON object whose property name is
 * the storage key, e.g. {"mykey": "value1"}.
 *
 * Parsing and validating are the same act — a WriteBody exists only if the
 * raw body was a well-formed envelope carrying a usable key, so nothing
 * downstream re-checks the shape. It is built from the raw content rather
 * than a request bag because middleware and Symfony's method override both
 * rewrite the decoded bag, and it decodes to stdClass rather than an
 * associative array so that a key of "0" is not misread as an array body.
 */
final class WriteBody
{
    private const DECODE_DEPTH_ALLOWANCE = 2;

    private function __construct(
        public readonly Key $key,
        public readonly mixed $value,
    ) {}

    /**
     * @throws InvalidBodyException
     */
    public static function fromJson(string $raw, int $maxDepth): self
    {
        $decoded = json_decode($raw, false, $maxDepth + self::DECODE_DEPTH_ALLOWANCE);

        if (json_last_error() === JSON_ERROR_DEPTH) {
            throw InvalidBodyException::tooDeep($maxDepth);
        }

        if (! $decoded instanceof stdClass) {
            throw InvalidBodyException::notAnObject();
        }

        $properties = get_object_vars($decoded);

        if (count($properties) !== 1) {
            throw InvalidBodyException::notASinglePair();
        }

        try {
            $key = Key::fromString((string) array_key_first($properties));
        } catch (InvalidKeyException $e) {
            throw InvalidBodyException::invalidKey($e);
        }

        return new self($key, array_values($properties)[0]);
    }
}
