<?php

declare(strict_types=1);

namespace Tvdt\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class LogoutRedirectListener implements EventSubscriberInterface
{
    private const array BLOCKED_TARGET_PREFIXES = ['/molshoop', '/elimination'];

    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    public function onLogout(LogoutEvent $event): void
    {
        $target = $event->getRequest()->query->get('target');

        if (\is_string($target) && $this->isAllowedTarget($target)) {
            $event->setResponse(new RedirectResponse($target));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('tvdt_quiz_select_season')));
    }

    public static function getSubscribedEvents(): array
    {
        // Must run before Symfony's DefaultLogoutListener (priority 64), which only
        // sets a response if none is set yet.
        return [
            LogoutEvent::class => ['onLogout', 128],
        ];
    }

    private function isAllowedTarget(string $target): bool
    {
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }

        return !array_any(self::BLOCKED_TARGET_PREFIXES, static fn (string $prefix): bool => str_starts_with($target, $prefix));
    }
}
