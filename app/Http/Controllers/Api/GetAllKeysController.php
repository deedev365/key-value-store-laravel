<?php

namespace App\Http\Controllers\Api;

use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /object/get_all_records/keys
 * The name of every key that has something published, alphabetically.
 *
 * A sub-path of the listing rather than a route of its own, so no second word
 * has to be reserved: `get_all_records` already is, and a key cannot contain a
 * slash, so nothing a caller may store can reach this path.
 *
 * The answer is a flat array of strings, not records — it exists to fill the
 * page's key selector, and a dropdown needs names. Capped by
 * `kvstore.max_keys_listed`, so this is not the unbounded read the paged
 * listing deliberately avoids being.
 */
class GetAllKeysController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(): JsonResponse
    {
        $keys = $this->repository->allKeys((int) config('kvstore.max_keys_listed'));

        return response()->json($keys->values());
    }
}
