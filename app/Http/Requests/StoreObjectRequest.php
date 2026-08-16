<?php

namespace App\Http\Requests;

use App\Exceptions\InvalidBodyException;
use App\ValueObjects\WriteBody;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The API's write contract is a single-property JSON object where the property
 * name is the storage key, e.g. {"mykey": "value1"} — the key is not a fixed
 * field name, so standard rule-based validation doesn't apply.
 *
 * Validation is therefore construction: WriteBody either parses the body or
 * says why it could not, and this class does nothing but translate that answer
 * into the validator's vocabulary.
 */
class StoreObjectRequest extends FormRequest
{
    private ?WriteBody $parsed = null;

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
            try {
                $this->parsed = $this->parse();
            } catch (InvalidBodyException $e) {
                $validator->errors()->add($e->field, $e->getMessage());
            }
        });
    }

    /**
     * The parsed body. Reached only once validation has passed, since that is
     * the only path on which the handler runs, so the memoised instance from
     * withValidator() is what comes back.
     */
    public function body(): WriteBody
    {
        return $this->parsed ??= $this->parse();
    }

    /**
     * @throws InvalidBodyException
     */
    private function parse(): WriteBody
    {
        return WriteBody::fromJson(
            $this->getContent(),
            (int) config('kvstore.max_value_depth'),
        );
    }
}
