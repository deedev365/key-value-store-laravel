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

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'timestamp' => ['integer', 'min:0'],
        ];
    }

    public function timestamp(): ?int
    {
        if (! $this->has('timestamp')) {
            return null;
        }

        return (int) $this->query('timestamp');
    }
}
