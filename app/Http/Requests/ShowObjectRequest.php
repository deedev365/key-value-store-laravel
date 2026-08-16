<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The read contract of GET /object/{key}: an optional `timestamp` query
 * parameter. Absent means "the current value", present means "the value that
 * was current at that moment".
 */
class ShowObjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timestamp' => ['integer', 'min:0'],
        ];
    }

    /**
     * The requested point in time, or null when the caller asked for the
     * current value.
     */
    public function timestamp(): ?int
    {
        if (! $this->has('timestamp')) {
            return null;
        }

        return (int) $this->query('timestamp');
    }
}
