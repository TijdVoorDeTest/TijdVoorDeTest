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
    private function makeEvent(Request $request, array $arguments): ControllerArgumentsEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ControllerArgumentsEvent($kernel, static fn (): \stdClass => new \stdClass(), $arguments, $request, null);
    }

    public function testAuthenticatedUsersLocaleWinsOverEverythingElse(): void
    {
        $user = new User();
        $user->locale = 'en';

        $season = new Season();
        $this->assertInstanceOf(SeasonSettings::class, $season->settings);
        $season->settings->language = 'nl';

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->once())->method('setLocale')->with('en');

        $listener = new LocaleListener($security, $localeSwitcher);
        $request = Request::create('/molshoop/');
        $event = $this->makeEvent($request, [$season]);

        $listener->onControllerArguments($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testSeasonLanguageIsUsedWhenNoUserIsAuthenticated(): void
    {
        $season = new Season();
        $this->assertInstanceOf(SeasonSettings::class, $season->settings);
        $season->settings->language = 'en';

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->once())->method('setLocale')->with('en');

        $listener = new LocaleListener($security, $localeSwitcher);
        $request = Request::create('/krtek/some-candidate');
        $event = $this->makeEvent($request, ['some-candidate', $season]);

        $listener->onControllerArguments($event);

        $this->assertSame('en', $request->getLocale());
    }

    public function testLocaleIsUntouchedWhenNoUserOrSeasonIsResolved(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $localeSwitcher->expects($this->never())->method('setLocale');

        $listener = new LocaleListener($security, $localeSwitcher);
        $request = Request::create('/');
        $request->setLocale('nl');

        $event = $this->makeEvent($request, []);

        $listener->onControllerArguments($event);

        $this->assertSame('nl', $request->getLocale());
    }
}
