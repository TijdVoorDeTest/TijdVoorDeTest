<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tvdt\Entity\User;
use Tvdt\EventListener\LastActivityListener;

#[CoversClass(LastActivityListener::class)]
final class LastActivityListenerTest extends TestCase
{
    public function testDoesNothingForAnAnonymousRequest(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        new LastActivityListener($security, $em)->onKernelController($this->createEvent());
    }

    public function testDoesNothingForASubRequest(): void
    {
        $user = new User();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        new LastActivityListener($security, $em)->onKernelController($this->createEvent(HttpKernelInterface::SUB_REQUEST));

        $this->assertNotInstanceOf(\DateTimeImmutable::class, $user->lastActivity);
    }

    public function testSetsLastActivityAndFlushesWhenNeverRecordedBefore(): void
    {
        $user = new User();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        new LastActivityListener($security, $em)->onKernelController($this->createEvent());

        $this->assertInstanceOf(\DateTimeImmutable::class, $user->lastActivity);
    }

    public function testDoesNotFlushAgainWithinTheThrottleWindow(): void
    {
        $user = new User();
        $recent = new DateTimeImmutable('-30 seconds');
        $user->lastActivity = $recent;

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        new LastActivityListener($security, $em)->onKernelController($this->createEvent());

        $this->assertSame($recent, $user->lastActivity);
    }

    public function testFlushesAgainOnceTheThrottleWindowHasPassed(): void
    {
        $user = new User();
        $stale = new DateTimeImmutable('-90 seconds');
        $user->lastActivity = $stale;

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        new LastActivityListener($security, $em)->onKernelController($this->createEvent());

        $this->assertNotSame($stale, $user->lastActivity);
    }

    private function createEvent(int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ControllerEvent($kernel, static fn (): \stdClass => new \stdClass(), Request::create('/'), $requestType);
    }
}
