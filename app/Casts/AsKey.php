<?php

namespace App\Casts;

use App\ValueObjects\Key;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns the `key` column into a Key on the way out and back into a string on
 * the way in, so nothing above the model handles a bare string.
 *
 * The two directions deliberately have different strictness. A write is
 * untrusted input and goes through Key::fromString(), which refuses an
 * invalid key. A read trusts what is already stored — see Key::fromStorage()
 * for why reads must not start failing when the rules are tightened.
 *
 * @implements CastsAttributes<Key, Key|string>
 */
final class AsKey implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Key
    {
        return $value === null ? null : Key::fromStorage((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Key
            ? $value->value
            : Key::fromString((string) $value)->value;
    }
}
