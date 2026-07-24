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
 * Overrides the Accept-Language-derived locale with the authenticated user's profile
 * language (backoffice), or failing that, the season's configured language (public quiz).
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
        $user = $this->security->getUser();

        if ($user instanceof User) {
            return $user->locale;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof Season) {
                return $argument->settings?->language;
            }
        }

        return null;
    }
}
