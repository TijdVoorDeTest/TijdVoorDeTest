<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Tvdt\Entity\Season;
use Tvdt\Entity\SeasonSettings;
use Tvdt\Entity\User;
use Tvdt\EventListener\LocaleListener;

#[CoversClass(LocaleListener::class)]
final class LocaleListenerTest extends TestCase
{
    /** @param list<mixed> $arguments */
    private function makeEvent(string $route, array $arguments): ControllerArgumentsEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new ControllerArgumentsEvent($kernel, static fn (): \stdClass => new \stdClass(), $arguments, $request, null);
    }

    private function seasonWithLocale(string $locale): Season
    {
        $season = new Season();
        $this->assertInstanceOf(SeasonSettings::class, $season->settings);
        $season->settings->locale = $locale;

        return $season;
    }

    public function testMolshoopPageUsesTheAuthenticatedUsersLocaleEvenWithASeasonResolved(): void
    {
        $user = new User();
        $user->locale = 'en';

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->once())->method('setLocale')->with('en');

        $listener = new LocaleListener($security, $localeSwitcher);
        $event = $this->makeEvent('tvdt_molshoop_season_settings', [$this->seasonWithLocale('nl')]);

        $listener->onControllerArguments($event);

        $this->assertSame('en', $event->getRequest()->getLocale());
    }

    public function testMolshoopPageIsUntouchedWhenNoUserIsAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->never())->method('setLocale');

        $listener = new LocaleListener($security, $localeSwitcher);
        $event = $this->makeEvent('tvdt_molshoop_index', []);

        $listener->onControllerArguments($event);
    }

    public function testNonMolshoopPageUsesTheSeasonsLocaleWhenNoUserIsAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->once())->method('setLocale')->with('en');

        $listener = new LocaleListener($security, $localeSwitcher);
        $event = $this->makeEvent('tvdt_quiz_enter_name', ['some-candidate', $this->seasonWithLocale('en')]);

        $listener->onControllerArguments($event);

        $this->assertSame('en', $event->getRequest()->getLocale());
    }

    public function testNonMolshoopPageUsesTheSeasonsLocaleEvenForAnAuthenticatedUser(): void
    {
        $user = new User();
        $user->locale = 'nl';

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->once())->method('setLocale')->with('en');

        $listener = new LocaleListener($security, $localeSwitcher);
        $event = $this->makeEvent('tvdt_elimination', [$this->seasonWithLocale('en')]);

        $listener->onControllerArguments($event);

        $this->assertSame('en', $event->getRequest()->getLocale());
    }

    public function testLocaleIsUntouchedWhenNoUserOrSeasonIsResolved(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->never())->method('setLocale');

        $listener = new LocaleListener($security, $localeSwitcher);
        $event = $this->makeEvent('tvdt_quiz_select_season', []);
        $event->getRequest()->setLocale('nl');

        $listener->onControllerArguments($event);

        $this->assertSame('nl', $event->getRequest()->getLocale());
    }
}
