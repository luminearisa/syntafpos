<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use JsonSerializable;

/**
 * Standard API envelope used by every endpoint:
 * { success, message, data, meta }
 */
trait ApiResponse
{
    protected function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data instanceof Arrayable || $data instanceof JsonSerializable
                ? $data
                : $data,
            'meta' => $meta,
        ], $status);
    }

    /**
     * Wrap a paginated resource collection in the standard envelope.
     *
     * Laravel's default resource pagination emits a raw {data, links, meta}
     * document, which breaks the contract every other endpoint follows.
     */
    protected function paginated(
        AnonymousResourceCollection $collection,
        string $message = 'Success'
    ): JsonResponse {
        $paginator = $collection->resource;

        return $this->success(
            $collection->resolve(),
            $message,
            200,
            $paginator instanceof LengthAwarePaginator ? [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ] : []
        );
    }

    protected function error(
        string $message,
        int $status = 400,
        array $errors = [],
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'data' => $data,
        ], $status);
    }
}
