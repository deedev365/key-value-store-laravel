<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreObjectRequest;
use App\Models\KvEntry;
use App\Repositories\Contracts\KeyValueRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjectController extends Controller
{

    private const RECORD_LIMIT = 10;

    public function __construct(
        private readonly KeyValueRepositoryInterface $repository,
    ) {}

    /**
     * POST /object
     * Body: {"<key>": <value>}
     */
    public function store(StoreObjectRequest $request): JsonResponse
    {
        $entry = $this->repository->store(
            $request->storageKey(),
            $request->storageValue(),
            now()->timestamp,
        );

        return $this->entryResponse($entry, 201);
    }

    /**
     * GET /object/{key}
     * GET /object/{key}?timestamp=<unix timestamp>
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $timestamp = $this->validatedTimestamp($request);

        $entry = $timestamp === null
            ? $this->repository->findLatest($key)
            : $this->repository->findAtTimestamp($key, $timestamp);

        if ($entry === null) {
            return response()->json([
                'message' => $timestamp === null
                    ? "No value found for key '{$key}'."
                    : "No value found for key '{$key}' at or before timestamp {$timestamp}.",
            ], 404);
        }

        return $this->entryResponse($entry);
    }

    /**
     * GET /object/get_all_records
     */
    public function getAllRecords(?int $page = null): JsonResponse
    {
        $records = $this->repository->allLatest()
            ->map(fn (KvEntry $entry) => $this->entryPayload($entry))
            ->values();

        $countRecords = count($records);
        if ($countRecords < 1) {
            return response()->json([]);
        }

        $limit = self::RECORD_LIMIT;
        $maxPages = (int) ceil($countRecords / $limit);
        if ($page > $maxPages) {
            return response()->json([]);
        }

        if (empty($page) || $page < 2) {
            $index = 0;
        } else {
            $index = (int) ceil(($page - 1) * $limit);
        }

        $records = $records->slice($index, $limit)->values();

        return response()->json($records);
    }

    /**
     * GET /object/{key}/history
     * Every version ever recorded for the key, oldest first.
     */
    public function history(string $key): JsonResponse
    {
        $records = $this->repository->history($key)
            ->map(fn (KvEntry $entry) => $this->entryPayload($entry))
            ->values();

        return response()->json($records);
    }

    /**
     * DELETE /object/{key}
     * Deletes every recorded version of the key.
     */
    public function removeKey(string $key): JsonResponse
    {
        if (! $this->repository->deleteAll($key)) {
            return response()->json([
                'message' => "No value found for key '{$key}'.",
            ], 404);
        }

        return response()->json(null, 204);
    }

    private function validatedTimestamp(Request $request): ?int
    {
        if (! $request->has('timestamp')) {
            return null;
        }

        $request->validate([
            'timestamp' => ['integer', 'min:0'],
        ]);

        return (int) $request->query('timestamp');
    }

    private function entryResponse(KvEntry $entry, int $status = 200): JsonResponse
    {
        return response()->json($this->entryPayload($entry), $status);
    }

    private function entryPayload(KvEntry $entry): array
    {
        return [
            'key' => $entry->key,
            'value' => $entry->value,
            'timestamp' => $entry->recorded_at,
        ];
    }
}
