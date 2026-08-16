<?php

namespace App\Http\Resources;

use App\Models\KvEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single JSON shape a stored version is rendered as. Every endpoint that
 * returns records — the write, the two lookups and the two listings — renders
 * them through here, so the response contract lives in one file rather than in
 * each controller.
 *
 * The stored column is `recorded_at`; the API calls it `timestamp`. That
 * rename is the whole reason this class exists instead of the model's own
 * toArray().
 */
class KvEntryResource extends JsonResource
{
    /**
     * A record is the response, not a payload inside a "data" envelope, so
     * wrapping stays off for the times this resource is returned directly
     * from a route rather than through response()->json().
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KvEntry $entry */
        $entry = $this->resource;

        return [
            // Cast explicitly rather than leaning on Key's JsonSerializable:
            // this array is also read by callers that never encode it.
            'key' => (string) $entry->key,
            'value' => $entry->value,
            'timestamp' => $entry->recorded_at,
        ];
    }
}
