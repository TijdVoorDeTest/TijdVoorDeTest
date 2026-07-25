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

/** Overrides the Accept-Language-derived locale with the user's profile locale on Molshoop pages, or the viewed season's locale (falling back to the user's profile locale, e.g. on the season-selection homepage) elsewhere. See LocaleListenerTest for the exact precedence per scenario. */
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

        $user = $this->security->getUser();

        if (!str_starts_with($route, 'tvdt_molshoop_')) {
            foreach ($event->getArguments() as $argument) {
                if ($argument instanceof Season) {
                    return $argument->settings?->locale;
                }
            }

            return $user instanceof User ? $user->locale : null;
        }

        return $user instanceof User ? $user->locale : null;
    }
}
