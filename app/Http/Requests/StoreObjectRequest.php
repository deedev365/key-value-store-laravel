<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The API's write contract is a single-property JSON object where the
 * property name is the storage key, e.g. {"mykey": "value1"} — the key is
 * not a fixed field name, so standard rule-based validation doesn't apply
 * and is instead done by hand in withValidator().
 */
class StoreObjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $data = $this->json()->all();

            if (! is_array($data) || array_is_list($data)) {
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
        return (string) array_key_first($this->json()->all());
    }

    /**
     * The value associated with the storage key. May be any valid JSON
     * type: string, number, bool, null, array or object.
     */
    public function storageValue(): mixed
    {
        return array_values($this->json()->all())[0] ?? null;
    }
}
