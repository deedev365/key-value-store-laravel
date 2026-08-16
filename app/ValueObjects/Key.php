<?php

namespace App\ValueObjects;

use App\Exceptions\InvalidKeyException;
use JsonSerializable;
use Stringable;

/**
 * A storage key: an opaque, non-empty identifier of a limited character set.
 *
 * What a valid key is used to be stated in three places at once — the route
 * constraint, the write validator and the front end — so a change to the
 * character set could land in one and not the others, and the two would then
 * disagree silently: the route would 404 a key the validator had accepted.
 * This class is the single authority. Routes take PATTERN, the write
 * validator goes through fromString(), and app.js is pinned to the same
 * expression by a test.
 *
 * Instances are immutable and always valid: the constructor is private, so
 * the only ways in are the named constructors below, and each one states
 * which guarantee it gives.
 */
final class Key implements JsonSerializable, Stringable
{
    /**
     * Deliberately unanchored: Route::where() drops the expression into the
     * middle of the compiled route regex and anchors it there, so anchors in
     * this constant would be doubled. The anchored form is REGEX, below.
     */
    public const PATTERN = '[A-Za-z0-9_.-]+';

    public const MAX_LENGTH = 255;

    /**
     * Keys the API cannot serve back, because a literal route claims the same
     * path segment (see routes/api.php). A key stored under one of these would
     * be written happily and then read as something else entirely — GET
     * /object/get_all_records is the listing, not that key's value — so the
     * write is refused rather than left silently unreadable.
     *
     * @var list<string>
     */
    public const RESERVED = ['get_all_records'];

    /**
     * The anchored form, for matching a whole string rather than a route
     * segment. Public because the front-end contract test asserts that
     * app.js validates against this exact expression.
     */
    public const REGEX = '/^'.self::PATTERN.'$/';

    private function __construct(public readonly string $value) {}

    /**
     * Build a key from untrusted input — a request body, a query string —
     * refusing anything that is not a valid key.
     *
     * @throws InvalidKeyException
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidKeyException::empty();
        }

        if (in_array($value, self::RESERVED, true)) {
            throw InvalidKeyException::reserved($value);
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidKeyException::tooLong(self::MAX_LENGTH);
        }

        if (! preg_match(self::REGEX, $value)) {
            throw InvalidKeyException::malformed();
        }

        return new self($value);
    }

    /**
     * The same, but answering with null instead of throwing, for the callers
     * that treat an invalid key as an ordinary outcome rather than an error.
     */
    public static function tryFrom(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (InvalidKeyException) {
            return null;
        }
    }

    /**
     * Rebuild a key from a value that is already in the database.
     *
     * Validation is skipped on purpose. A stored row was checked on the way
     * in, and re-checking it on every read would mean that tightening the
     * rules later makes existing rows unreadable — the table would start
     * throwing on data it had accepted years earlier. Reads must not fail
     * because policy moved.
     */
    public static function fromStorage(string $value): self
    {
        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
