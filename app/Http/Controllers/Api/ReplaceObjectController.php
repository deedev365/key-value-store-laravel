<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ReplaceObjectRequest;
use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * PUT /object/{key}
 * PUT /object/{key}?timestamp=<unix timestamp>
 *
 * Corrects one stored version: the version this URL would read is removed and
 * a corrected one is appended. The target is resolved by exactly the reads
 * GET /object/{key} uses, so what is edited is what was just read — including
 * the publish filter, which is why a version that has not gone live yet can
 * never be edited out from under its schedule.
 *
 * The key's other versions are untouched. Because the current value is the
 * last-written version, correcting an older one also makes the correction
 * current: an append-only store has no way to write a version that is not the
 * newest.
 *
 * 200, not 201: the resource named by this URL is being replaced and the
 * response is its new current representation. A row is created, but that is
 * storage mechanics — there is no per-version URL for a Location header to
 * point at, since ids are never exposed.
 */
class ReplaceObjectController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(ReplaceObjectRequest $request, string $key): JsonResponse
    {
        $timestamp = $request->timestamp();

        $target = $timestamp === null
            ? $this->repository->findLatest($key)
            : $this->repository->findAtTimestamp($key, $timestamp);

        if ($target === null) {
            // The same two sentences ShowObjectController answers a miss with,
            // so a failed edit reads like the failed lookup it follows.
            return response()->json([
                'message' => $timestamp === null
                    ? "No value found for key '{$key}'."
                    : "No value found for key '{$key}' at or before timestamp {$timestamp}.",
            ], 404);
        }

        $replacement = $this->repository->replace(
            $target,
            $request->body()->value,
            now()->timestamp,
            $request->publishTime(),
        );

        // Resolved a moment ago, gone now: another request corrected or deleted
        // that version in between. Nothing was written, so the answer is the
        // one a caller gets for a version that does not exist.
        if ($replacement === null) {
            return response()->json([
                'message' => "No value found for key '{$key}'.",
            ], 404);
        }

        return response()->json(new KvEntryResource($replacement));
    }
}
