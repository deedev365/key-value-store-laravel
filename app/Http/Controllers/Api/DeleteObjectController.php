<?php

namespace App\Http\Controllers\Api;

use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Http\JsonResponse;

/**
 * DELETE /object/{key}
 * Deletes every recorded version of the key.
 */
class DeleteObjectController
{
    public function __construct(
        private readonly EloquentKeyValueRepository $repository,
    ) {}

    public function __invoke(string $key): JsonResponse
    {
        if (! $this->repository->deleteAll($key)) {
            return response()->json([
                'message' => "No value found for key '{$key}'.",
            ], 404);
        }

        return response()->json([
            'message' => "Key '{$key}' and all its versions were deleted.",
        ]);
    }
}
