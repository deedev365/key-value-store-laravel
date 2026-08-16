<?php

namespace App\ValueObjects;

use App\Exceptions\InvalidBodyException;
use App\Exceptions\InvalidKeyException;
use stdClass;

/**
 * The body of a write: a single-property JSON object whose property name is
 * the storage key, e.g. {"mykey": "value1"}.
 *
 * Parsing and validating are the same act here. A WriteBody exists only if the
 * raw body was a well-formed envelope carrying a usable key, so nothing
 * downstream re-checks the shape or digs the key back out of an array — the
 * request handler receives a typed key and a value, and that is all it needs.
 */
final class WriteBody
{
    private function __construct(
        public readonly Key $key,
        public readonly mixed $value,
    ) {}

    /**
     * Build from the raw request content.
     *
     * The raw content is the right input, rather than a decoded request bag:
     * Laravel copies a decoded JSON body into the Symfony request bag, where
     * global middleware rewrites it and Symfony's method override reads from
     * it, so only the raw content is exactly what the client sent.
     *
     * @throws InvalidBodyException
     */
    public static function fromJson(string $raw, int $maxDepth): self
    {
        // json_decode needs depth $maxDepth + 2 to accept a value nested
        // $maxDepth levels inside the wrapping single-property object: one
        // level for the object, and one more because its own limit counts the
        // innermost scalar. Decoding with the limit, rather than walking the
        // result afterwards, means an over-deep body is never materialised in
        // the first place.
        //
        // Decoding to stdClass rather than to an associative array is what
        // makes the object/array distinction reliable: json_decode($raw, true)
        // turns both {"0":"a"} and ["a"] into [0 => 'a'], so a key of "0"
        // would otherwise be misread as an array body and rejected. Nested
        // values keep their JSON shape for the same reason — an associative
        // decode would re-encode {"0":"a","1":"b"} as the array ["a","b"].
        $decoded = json_decode($raw, false, $maxDepth + 2);

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
