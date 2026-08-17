<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ShowObjectRequest;
use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /object/{key}
 * GET /object/{key}?timestamp=<unix timestamp>
 */
class ShowObjectController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(ShowObjectRequest $request, string $key): JsonResponse
    {
        $timestamp = $request->timestamp();

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

        return response()->json(new KvEntryResource($entry));
    }
}
