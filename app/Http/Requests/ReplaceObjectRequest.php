<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ParsesWriteBody;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The edit contract of PUT /object/{key}: the same single-property body a write
 * takes, plus two optional query parameters — `timestamp`, naming which version
 * is being corrected (absent means the current one, exactly as it does on
 * GET /object/{key}), and `publish_time`, when the correction goes live.
 *
 * Not a subclass of StoreObjectRequest, though the two overlap: `publish_time`
 * means something different here. On a write, absent means "no schedule, live
 * now"; on an edit it means "keep the schedule the replaced version had", since
 * a correction is a change of wording by default. Inheriting that method would
 * hide the difference behind an identical signature. The part that genuinely is
 * the same — the envelope — is the trait.
 *
 * The body's key must be the key in the path. Two spellings of one identifier
 * in a single request is where a quiet "the path won, the body was ignored"
 * bug lives, and accepting a mismatch would let PUT /object/a delete a's
 * version while writing b's.
 */
class ReplaceObjectRequest extends FormRequest
{
    use ParsesWriteBody;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'timestamp' => ['integer', 'min:0'],
            'publish_time' => ['integer', 'min:0'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $body = $this->validateWriteBody($validator);

            // An unparsable body has already said why; naming the key it does
            // not have on top of that would describe a second problem the
            // caller does not have.
            if ($body === null) {
                return;
            }

            $pathKey = (string) $this->route('key');

            if ($body->key->value !== $pathKey) {
                $validator->errors()->add(
                    'key',
                    "Body key '{$body->key->value}' does not match the key '{$pathKey}' in the URL."
                );
            }
        });
    }

    /**
     * Which version is being corrected, or null for the current one.
     *
     * has() rather than filled(), matching ShowObjectRequest: the same query
     * string has to resolve the same version for a read and for the edit of
     * what that read returned, so the two must not disagree about what an
     * empty `?timestamp=` means.
     */
    public function timestamp(): ?int
    {
        if (! $this->has('timestamp')) {
            return null;
        }

        return (int) $this->query('timestamp');
    }

    /**
     * When the correction should become visible, or null to keep whatever the
     * version being corrected was scheduled for.
     *
     * filled() rather than has(), for the reason StoreObjectRequest gives: an
     * empty `?publish_time=` is a caller who supplied no time, and casting it
     * to 0 would stamp the correction for the epoch.
     *
     * Null means "carry the old schedule over" rather than "no schedule",
     * because a correction is a change of wording by default — clearing a
     * schedule outright is not something this endpoint offers, and nothing is
     * lost by that: every version it can reach is published already, so its
     * time is in the past either way.
     */
    public function publishTime(): ?int
    {
        if (! $this->filled('publish_time')) {
            return null;
        }

        return (int) $this->query('publish_time');
    }
}
