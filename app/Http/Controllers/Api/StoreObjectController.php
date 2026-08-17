<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreObjectRequest;
use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * POST /object
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

        $entry = $this->repository->store(
            $body->key->value,
            $body->value,
            now()->timestamp,
        );

        return response()->json(new KvEntryResource($entry), 201);
    }
}
