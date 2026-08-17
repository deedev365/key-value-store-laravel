<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KvEntryResource;
use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /object/get_all_records
 * GET /object/get_all_records/{page}
 * One page of the latest value of every key.
 */
class GetAllRecordsController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(?int $page = null): JsonResponse
    {
        $limit = (int) config('kvstore.records_per_page');
        $page = max(1, (int) $page);

        $records = $this->repository->allLatest($limit, ($page - 1) * $limit);

        return response()->json(KvEntryResource::collection($records->values()));
    }
}
