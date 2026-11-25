<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check de aplicação, banco e Redis (ARCHITECTURE §14).
 * Não expõe detalhes de erro: só o estado de cada dependência.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Redis $redis = null,
    ) {
    }

    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => $this->check(fn () => $this->connection->executeQuery('SELECT 1')->fetchOne()),
            'redis' => $this->check(fn () => $this->redis?->ping()),
        ];

        $healthy = !\in_array('fail', $checks, true);

        return new JsonResponse(['data' => ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks]], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
