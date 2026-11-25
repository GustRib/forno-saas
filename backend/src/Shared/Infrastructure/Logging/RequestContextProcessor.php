<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Presentation\RequestIdSubscriber;
use App\Tenant\Application\TenantContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Acrescenta request_id e tenant_id a cada log (sem dados pessoais).
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return $record;
        }

        $extra = $record->extra;
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        if (\is_string($requestId)) {
            $extra['request_id'] = $requestId;
        }

        $tenantId = $this->tenantContext->peekTenantId();
        if (null !== $tenantId) {
            $extra['tenant_id'] = $tenantId;
        }

        return $record->with(extra: $extra);
    }
}
