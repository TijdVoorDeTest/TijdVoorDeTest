<?php

declare(strict_types=1);

namespace Tvdt\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;

/**
 * Overrides the Accept-Language-derived locale: with the authenticated user's profile
 * language on Molshoop (backoffice) pages, or with the resolved season's configured
 * language everywhere else (public quiz, elimination view, ...).
 */
final readonly class LocaleListener implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private LocaleSwitcher $localeSwitcher,
    ) {}

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $locale = $this->resolveLocale($event);

        if (null === $locale) {
            return;
        }

        $event->getRequest()->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }

    private function resolveLocale(ControllerArgumentsEvent $event): ?string
    {
        $route = (string) $event->getRequest()->attributes->get('_route');

        // Molshoop is the only backoffice context, so the user's profile language always wins there.
        // Everywhere else (public quiz, elimination view, ...) the season being viewed wins, even for
        // an authenticated owner previewing it, since that content is scoped to the season, not to them.
        if (!str_starts_with($route, 'tvdt_molshoop_')) {
            foreach ($event->getArguments() as $argument) {
                if ($argument instanceof Season) {
                    return $argument->settings?->locale;
                }
            }

            return null;
        }

        $user = $this->security->getUser();

        return $user instanceof User ? $user->locale : null;
    }
}
