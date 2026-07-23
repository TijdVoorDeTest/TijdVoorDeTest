<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Tvdt\Security\LogoutRedirectListener;

#[CoversClass(LogoutRedirectListener::class)]
final class LogoutRedirectListenerTest extends TestCase
{
    public function testLogoutRedirectsBackToTheGivenTarget(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->never())->method('generate');
        $listener = new LogoutRedirectListener($urlGenerator);

        $event = new LogoutEvent(Request::create('/logout?target=/krtek'), null);
        $listener->onLogout($event);

        $this->assertSame('/krtek', $event->getResponse()?->headers->get('Location'));
    }

    public function testLogoutWithoutTargetRedirectsToSeasonSelect(): void
    {
        $listener = new LogoutRedirectListener($this->seasonSelectUrlGenerator());

        $event = new LogoutEvent(Request::create('/logout'), null);
        $listener->onLogout($event);

        $this->assertSame('/', $event->getResponse()?->headers->get('Location'));
    }

    #[DataProvider('blockedTargetProvider')]
    public function testLogoutIgnoresBlockedOrUnsafeTargets(string $target): void
    {
        $listener = new LogoutRedirectListener($this->seasonSelectUrlGenerator());

        $event = new LogoutEvent(Request::create('/logout?target='.urlencode($target)), null);
        $listener->onLogout($event);

        $this->assertSame('/', $event->getResponse()?->headers->get('Location'));
    }

    /** @return iterable<string, array{string}> */
    public static function blockedTargetProvider(): iterable
    {
        yield 'backoffice page' => ['/backoffice/season/krtek'];
        yield 'elimination page' => ['/elimination/00000000-0000-0000-0000-000000000000'];
        yield 'protocol-relative url' => ['//evil.example.org'];
        yield 'absolute url' => ['https://evil.example.org'];
    }

    private function seasonSelectUrlGenerator(): UrlGeneratorInterface&MockObject
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('tvdt_quiz_select_season')
            ->willReturn('/');

        return $urlGenerator;
    }
}
