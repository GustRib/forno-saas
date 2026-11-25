<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proteção CSRF para autenticação por cookie (ARCHITECTURE §10/§12).
 *
 * Camadas:
 *  1. Cookie de sessão SameSite=Lax + HttpOnly.
 *  2. Requisições que alteram estado precisam de Content-Type JSON ou do
 *     cabeçalho X-Requested-With. Formulários HTML de outro site não conseguem
 *     enviar nenhum dos dois sem um preflight CORS (que não é permitido).
 *  3. Se o navegador informar Origin/Sec-Fetch-Site, eles precisam indicar
 *     a mesma origem.
 */
final class CsrfGuardSubscriber implements EventSubscriberInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function getSubscribedEvents(): array
    {
        // Antes do firewall (prioridade 8), inclusive do json_login.
        return [KernelEvents::REQUEST => ['onRequest', 256]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') || \in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        $isJson = str_starts_with(strtolower($contentType), 'application/json');
        $hasXhrHeader = 'XMLHttpRequest' === $request->headers->get('X-Requested-With');

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        $crossSiteFetch = null !== $fetchSite && !\in_array($fetchSite, ['same-origin', 'none'], true);

        $origin = $request->headers->get('Origin');
        $crossOrigin = null !== $origin && 'null' !== $origin && rtrim($origin, '/') !== $request->getSchemeAndHttpHost();
        if ('null' === $origin) {
            $crossOrigin = true;
        }

        if ((!$isJson && !$hasXhrHeader) || $crossSiteFetch || $crossOrigin) {
            $event->setResponse(ApiResponse::error(
                'CSRF_REJECTED',
                'Requisição recusada por segurança. Recarregue a página e tente novamente.',
                403,
            ));
        }
    }
}
