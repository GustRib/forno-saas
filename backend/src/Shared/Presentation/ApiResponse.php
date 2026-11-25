<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Envelope padrão da API:
 *   sucesso: {"data": ...} ou {"data": [...], "meta": {...}}
 *   erro:    {"error": {"code": "...", "message": "...", "details": {...}}}
 */
final class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * @param list<mixed> $items
     */
    public static function page(array $items, int $page, int $limit, int $total): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ([] !== $details) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }
}
