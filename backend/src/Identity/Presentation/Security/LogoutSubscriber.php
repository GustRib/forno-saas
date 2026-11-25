<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Security;

use App\Shared\Presentation\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class LogoutSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', -100]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(ApiResponse::noContent());
    }
}
