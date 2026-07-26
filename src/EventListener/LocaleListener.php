<?php

declare(strict_types=1);

namespace Tvdt\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\Season;
use Tvdt\Entity\SeasonSettings;
use Tvdt\Entity\User;

/** Overrides the Accept-Language-derived locale with the user's profile locale on Molshoop pages, or the viewed season's locale (found directly, or via an Elimination's quiz, falling back to the user's profile locale when no season or season settings are available, e.g. on the season-selection homepage) elsewhere. See LocaleListenerTest for the exact precedence per scenario. */
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
            $season = $this->findSeason($event->getArguments());

            if ($season instanceof Season && $season->settings instanceof SeasonSettings) {
                return $season->settings->locale;
            }

            return $user instanceof User ? $user->locale : null;
        }

        return $user instanceof User ? $user->locale : null;
    }

    /** @param list<object> $arguments */
    private function findSeason(array $arguments): ?Season
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Season) {
                return $argument;
            }

            if ($argument instanceof Elimination) {
                return $argument->quiz->season;
            }
        }

        return null;
    }
}
