<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Correlação de requisições (NFR-012): reaproveita o X-Request-Id gerado pelo
 * Nginx (ou cria um) e o devolve na resposta. Os logs incluem esse ID.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_request_id';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 512],
            KernelEvents::RESPONSE => ['onResponse', -512],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = 1 === preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : Uuid::v7()->toRfc4122();
        $request->attributes->set(self::ATTRIBUTE, $id);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (\is_string($id)) {
            $event->getResponse()->headers->set('X-Request-Id', $id);
        }
    }
}
