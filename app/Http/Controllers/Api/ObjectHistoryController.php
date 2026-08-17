<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /object/{key}/history
 * Every version ever recorded for the key, oldest first.
 */
class ObjectHistoryController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(string $key): JsonResponse
    {
        $records = $this->repository->history($key);

        return response()->json(KvEntryResource::collection($records->values()));
    }
}
