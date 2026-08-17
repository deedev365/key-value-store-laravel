<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreObjectRequest;
use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * POST /object
 * POST /object?publish_time=<unix timestamp>
 * Body: {"<key>": <value>}
 */
class StoreObjectController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(StoreObjectRequest $request): JsonResponse
    {
        $body = $request->body();

        // recorded_at stays the moment of the write; publish_time is when the
        // version goes live. They differ only for a scheduled write, which is
        // what keeps "who saved this, and when" intact for a queued campaign.
        $entry = $this->repository->store(
            $body->key->value,
            $body->value,
            now()->timestamp,
            $request->publishTime(),
        );

        return response()->json(new KvEntryResource($entry), 201);
    }
}
