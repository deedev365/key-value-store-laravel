<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ParsesWriteBody;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The API's write contract is a single-property JSON object whose property
 * name is the storage key, e.g. {"mykey": "value1"} — the key is not a fixed
 * field name, so rule-based validation does not apply. Validation is instead
 * construction: WriteBody either parses the body or says why it could not,
 * and ParsesWriteBody translates that answer into the validator's vocabulary
 * for this request and for ReplaceObjectRequest alike.
 */
class StoreObjectRequest extends FormRequest
{
    use ParsesWriteBody;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The only rule-shaped part of a write: the optional `publish_time` query
     * parameter. It rides in the query string rather than the body because the
     * body's single property *is* the key — a second property would both break
     * that invariant and make a key named "publish_time" unrepresentable.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'publish_time' => ['integer', 'min:0'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(fn (ValidatorContract $validator) => $this->validateWriteBody($validator));
    }

    /**
     * When the written version should become visible, or null for "now".
     *
     * filled() rather than has(): `?publish_time=` with nothing after it means
     * the caller supplied no time, but has() would accept it and cast the empty
     * string to 0 — a version stamped for the epoch, which is live immediately
     * yet sorts below every unscheduled version and so never shows. An explicit
     * `?publish_time=0` is still filled, and still honoured.
     */
    public function publishTime(): ?int
    {
        if (! $this->filled('publish_time')) {
            return null;
        }

        return (int) $this->query('publish_time');
    }
}
