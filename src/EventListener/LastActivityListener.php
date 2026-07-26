<?php

declare(strict_types=1);

namespace Tvdt\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tvdt\Entity\User;

/** Records how recently a user was last seen, throttled so it costs at most one write per user per window. */
final readonly class LastActivityListener implements EventSubscriberInterface
{
    // ponytail: per-user throttle window; lower it if "last activity" needs to be closer to real-time
    private const int THROTTLE_SECONDS = 60;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
    ) {}

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new DateTimeImmutable();
        if ($user->lastActivity instanceof \DateTimeImmutable
            && $now->getTimestamp() - $user->lastActivity->getTimestamp() < self::THROTTLE_SECONDS) {
            return;
        }

        $user->lastActivity = $now;
        $this->em->flush();
    }
}
