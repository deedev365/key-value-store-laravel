<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KvEntryResource;
use App\Repositories\Contracts\KeyValueRepositoryInterface;
use Illuminate\Http\JsonResponse;

/**
 * GET /object/get_all_records
 * GET /object/get_all_records/{page}
 *
 * One page of the latest value of every key. A page past the end is an
 * empty array rather than a 404 — this is a listing endpoint, and running
 * off the end of a list is not an error.
 */
class GetAllRecordsController extends Controller
{
    public function __construct(
        private readonly KeyValueRepositoryInterface $repository,
    ) {}

    public function __invoke(?int $page = null): JsonResponse
    {
        $limit = (int) config('kvstore.records_per_page');
        $page = max(1, (int) $page);

        $records = $this->repository->allLatest($limit, ($page - 1) * $limit);

        return response()->json(KvEntryResource::collection($records->values()));
    }
}
