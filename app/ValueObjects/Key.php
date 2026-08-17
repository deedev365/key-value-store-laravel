<?php

namespace App\ValueObjects;

use App\Exceptions\InvalidKeyException;
use JsonSerializable;
use Stringable;

/**
 * A storage key: an opaque, non-empty identifier of a limited character set,
 * and the single authority on what that set is — routes take PATTERN, the
 * write path goes through fromString(), and app.js is pinned to REGEX by a
 * test, so the three cannot drift apart. PATTERN is unanchored because
 * Route::where() anchors it itself; REGEX is the anchored form.
 *
 * Instances are immutable and always valid: the constructor is private, and
 * each named constructor states which guarantee it gives. fromString() is for
 * untrusted input and refuses anything invalid, including the RESERVED keys a
 * literal route would otherwise swallow. fromStorage() skips validation on
 * purpose — a stored row was checked on the way in, and re-checking it would
 * make existing rows unreadable the day the rules are tightened.
 */
final class Key implements JsonSerializable, Stringable
{
    public const PATTERN = '[A-Za-z0-9_.-]+';

    public const MAX_LENGTH = 255;

    /**
     * @var list<string>
     */
    public const RESERVED = ['get_all_records'];

    public const REGEX = '/^'.self::PATTERN.'$/';

    private function __construct(public readonly string $value) {}

    public static function routePattern(): string
    {
        $reserved = implode('|', array_map(preg_quote(...), self::RESERVED));

        return '(?!(?:'.$reserved.')(?:/|$))'.self::PATTERN;
    }

    /**
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

    public static function tryFrom(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (InvalidKeyException) {
            return null;
        }
    }

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
