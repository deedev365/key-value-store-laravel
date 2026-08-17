<?php

namespace App\Http\Resources;

use App\Models\KvEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single JSON shape a stored version is rendered as, used by every
 * endpoint that returns records. It exists because the stored column is
 * `recorded_at` while the API calls it `timestamp`; $wrap is null because a
 * record is the response itself, not a payload inside a "data" envelope.
 */
class KvEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KvEntry $entry */
        $entry = $this->resource;

        return [
            'key' => $entry->key,
            'value' => $entry->value,
            'timestamp' => $entry->recorded_at,

            // Only present when the version carries a schedule, so an
            // unscheduled record renders exactly as it did before scheduling
            // existed — an always-present null would change every response.
            'publish_time' => $this->whenNotNull($entry->publish_time),
        ];
    }
}
