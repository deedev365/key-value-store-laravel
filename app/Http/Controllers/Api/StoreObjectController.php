<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreObjectRequest;
use App\Http\Resources\KvEntryResource;
use App\Repositories\Contracts\KeyValueRepositoryInterface;
use Illuminate\Http\JsonResponse;

/**
 * POST /object
 * Body: {"<key>": <value>}
 */
class StoreObjectController extends Controller
{
    public function __construct(
        private readonly KeyValueRepositoryInterface $repository,
    ) {}

    public function __invoke(StoreObjectRequest $request): JsonResponse
    {
        $entry = $this->repository->store(
            $request->storageKey(),
            $request->storageValue(),
            now()->timestamp,
        );

        return response()->json(new KvEntryResource($entry), 201);
    }
}
