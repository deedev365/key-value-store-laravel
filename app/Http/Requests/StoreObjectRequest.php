<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use stdClass;

/**
 * The API's write contract is a single-property JSON object where the
 * property name is the storage key, e.g. {"mykey": "value1"} — the key is
 * not a fixed field name, so standard rule-based validation doesn't apply
 * and is instead done by hand in withValidator().
 *
 * The body is read from the raw request content rather than from $this->json()
 * or $this->input(). Laravel copies a decoded JSON body into the Symfony
 * request bag, where global middleware rewrites it and Symfony's method
 * override reads from it; the raw content is the only view of the request that
 * is exactly what the client sent.
 */
class StoreObjectRequest extends FormRequest
{
    /**
     * Decoded body, memoised because the raw content is parsed on each access.
     * false means "not parsed yet" — null is a meaningful result (invalid body).
     *
     * @var array<string, mixed>|false|null
     */
    private array|false|null $body = false;

    /**
     * Whether the body failed to decode specifically because it nested past
     * the configured depth, as opposed to being malformed.
     */
    private bool $tooDeep = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $data = $this->bodyProperties();

            if ($this->tooDeep) {
                $validator->errors()->add(
                    'value',
                    'Value must not be nested more than '.config('kvstore.max_value_depth').' levels deep.'
                );

                return;
            }

            if ($data === null) {
                $validator->errors()->add(
                    'body',
                    'Request body must be a JSON object, e.g. {"mykey": "value1"}.'
                );

                return;
            }

            if (count($data) !== 1) {
                $validator->errors()->add(
                    'body',
                    'Request body must contain exactly one key-value pair, e.g. {"mykey": "value1"}.'
                );

                return;
            }

            $key = (string) array_key_first($data);

            if (trim($key) === '') {
                $validator->errors()->add('key', 'Key must not be empty.');
            } elseif (mb_strlen($key) > 255) {
                $validator->errors()->add('key', 'Key must not be longer than 255 characters.');
            } elseif (! preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
                $validator->errors()->add(
                    'key',
                    'Key may only contain letters, digits, underscores, hyphens and dots.'
                );
            }
        });
    }

    /**
     * The storage key parsed from the request body's single top-level
     * property name.
     */
    public function storageKey(): string
    {
        return (string) array_key_first($this->bodyProperties() ?? []);
    }

    /**
     * The value associated with the storage key. May be any valid JSON
     * type: string, number, bool, null, array or object.
     */
    public function storageValue(): mixed
    {
        return array_values($this->bodyProperties() ?? [])[0] ?? null;
    }

    /**
     * The body's top-level properties, or null if the body isn't a JSON object.
     *
     * Decoding to stdClass rather than to an associative array is what makes
     * the object/array distinction reliable: json_decode($body, true) turns
     * both {"0":"a"} and ["a"] into [0 => 'a'], so a key of "0" would
     * otherwise be misread as an array body and rejected. Nested values keep
     * their JSON shape for the same reason — an associative decode would
     * re-encode {"0":"a","1":"b"} as the array ["a","b"].
     *
     * @return array<string, mixed>|null
     */
    private function bodyProperties(): ?array
    {
        if ($this->body !== false) {
            return $this->body;
        }

        // json_decode needs depth N+2 to accept a value nested N levels inside
        // the wrapping single-property object: one level for the object, and
        // one more because its own limit counts the innermost scalar.
        // Decoding with the limit, rather than walking the result afterwards,
        // means an over-deep body is never materialised in the first place.
        $decoded = json_decode(
            $this->getContent(),
            false,
            (int) config('kvstore.max_value_depth') + 2
        );

        $this->tooDeep = json_last_error() === JSON_ERROR_DEPTH;

        return $this->body = $decoded instanceof stdClass
            ? get_object_vars($decoded)
            : null;
    }
}
