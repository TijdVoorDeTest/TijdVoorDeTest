<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const string UNTOUCHED_SENTINEL = 'nl';

    /** @return iterable<string, array{string, ?string, ?string, ?string}> */
    public static function scenarios(): iterable
    {
        // [route, userLocale, seasonLocale, expectedLocale]
        yield 'molshoop + user + season: user wins, season ignored' => ['tvdt_molshoop_season_settings', 'en', 'nl', 'en'];
        yield 'molshoop + user + no season: user wins' => ['tvdt_molshoop_index', 'en', null, 'en'];
        yield 'molshoop + no user + season: untouched, season ignored on molshoop' => ['tvdt_molshoop_season_settings', null, 'nl', null];
        yield 'molshoop + no user + no season: untouched' => ['tvdt_molshoop_index', null, null, null];
        yield 'non-molshoop + user + season: season wins over user' => ['tvdt_elimination', 'nl', 'en', 'en'];
        yield 'non-molshoop + user + no season: untouched, user ignored off molshoop' => ['tvdt_quiz_select_season', 'en', null, null];
        yield 'non-molshoop + no user + season: season wins' => ['tvdt_quiz_enter_name', null, 'en', 'en'];
        yield 'non-molshoop + no user + no season: untouched' => ['tvdt_quiz_select_season', null, null, null];
    }

    #[DataProvider('scenarios')]
    public function testResolvesLocalePerRouteUserAndSeason(string $route, ?string $userLocale, ?string $seasonLocale, ?string $expectedLocale): void
    {
        $user = null;
        if (null !== $userLocale) {
            $user = new User();
            $user->locale = $userLocale;
        }

        $arguments = [];
        if (null !== $seasonLocale) {
            $season = new Season();
            $this->assertInstanceOf(SeasonSettings::class, $season->settings);
            $season->settings->locale = $seasonLocale;
            $arguments[] = $season;
        }

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        if (null !== $expectedLocale) {
            $localeSwitcher->expects($this->once())->method('setLocale')->with($expectedLocale);
        } else {
            $localeSwitcher->expects($this->never())->method('setLocale');
        }

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $request->attributes->set('_route', $route);
        $request->setLocale(self::UNTOUCHED_SENTINEL);

        $event = new ControllerArgumentsEvent($kernel, static fn (): \stdClass => new \stdClass(), $arguments, $request, null);

        new LocaleListener($security, $localeSwitcher)->onControllerArguments($event);

        $this->assertSame($expectedLocale ?? self::UNTOUCHED_SENTINEL, $request->getLocale());
    }
}
