<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Admin;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Controller\Admin\AdminController;
use Tvdt\DataFixtures\TestFixtures;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(AdminController::class)]
final class AdminControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Molshoop pages resolve locale from the logged-in user's profile, not Accept-Language
        // (see LocaleListener), so assertions on English flash text need the fixture user set to 'en'.
        $admin = $this->getUserByEmail(TestFixtures::ADMIN_EMAIL);
        $admin->locale = 'en';

        $this->entityManager->flush();

        $this->loginAs(TestFixtures::ADMIN_EMAIL);
    }

    public function testUsersTabListsUsersWithExpectedColumns(): void
    {
        // test@example.org: unverified, owns no seasons, never had activity recorded.
        $crawler = $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('table tbody tr')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'test@example.org'),
        );
        $this->assertGreaterThan(0, $row->count(), 'No table row found for test@example.org');

        $cells = $row->filter('td')->each(static fn ($node): string => mb_trim($node->text()));

        $this->assertSame('test@example.org', $cells[0]);
        $this->assertSame('', $cells[1]); // confirmed column renders an icon, not text
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2}-\d{4}$/', $cells[2]); // registered date
        $this->assertSame('Never', $cells[3]); // last activity
        $this->assertSame('0', $cells[4]); // season count

        $this->assertCount(
            0,
            $row->filter('i.bi-check-lg'),
            'test@example.org is not email-verified, so the confirmed column must show the "not confirmed" icon',
        );
        $this->assertGreaterThan(0, $row->filter('i.bi-x-lg')->count());
    }

    public function testSeasonsTabListsAllSeasons(): void
    {
        // Krtek Weekend: owned by krtek-admin@example.org and user2@example.org, active (Quiz 1
        // of 9). Doomed Season: sole-owner@example.org's only season, no active quiz.
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/seasons');

        self::assertResponseIsSuccessful();

        $krtekCells = $this->seasonRowCells($crawler, 'Krtek Weekend');
        $this->assertSame('krtek', $krtekCells[1]);
        $this->assertStringContainsString('krtek-admin@example.org', $krtekCells[2]);
        $this->assertStringContainsString('user2@example.org', $krtekCells[2]);
        $this->assertSame('Active', $krtekCells[3]);
        $this->assertSame('Quiz 1', $krtekCells[4]);
        $this->assertSame('9', $krtekCells[5]);

        $doomedCells = $this->seasonRowCells($crawler, 'Doomed Season');
        $this->assertSame('doomd', $doomedCells[1]);
        $this->assertSame('sole-owner@example.org', $doomedCells[2]);
        $this->assertSame('Not active', $doomedCells[3]);
        $this->assertSame('No active quiz', $doomedCells[4]);
        $this->assertSame('1', $doomedCells[5]);
    }

    /** @return list<string> */
    private function seasonRowCells(Crawler $crawler, string $seasonName): array
    {
        $row = $crawler->filter('table tbody tr')->reduce(
            static fn ($node): bool => str_contains($node->text(), $seasonName),
        );
        $this->assertGreaterThan(0, $row->count(), \sprintf('No table row found for %s', $seasonName));

        return $row->filter('td')->each(static fn ($node): string => mb_trim($node->text()));
    }

    public function testUsersTabQueryCountDoesNotGrowWithMoreUsers(): void
    {
        // A throwaway request first, so the assertion below isn't polluted by setUp()'s own writes.
        $this->client->request(Request::METHOD_GET, '/admin');

        for ($i = 0; $i < 20; ++$i) {
            $user = new User();
            $user->email = \sprintf('extra-user-%d@example.org', $i);
            $user->password = 'irrelevant';
            $user->addSeason($this->getSeasonByCode('krtek'));

            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        // A flat query count regardless of row count: one query to list users, one to hydrate
        // every user's $seasons collection, plus the auth reload for the logged-in admin. If this
        // creeps up, something reintroduced a per-row lazy load (N+1).
        $this->assertLessThanOrEqual(5, $this->getQueryCount(), 'Adding more users should not add more queries (N+1 on the per-user season count).');
    }

    public function testUsersTabIsDeniedForNonAdmin(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSeasonsTabQueryCountDoesNotGrowWithMoreSeasons(): void
    {
        // A throwaway request first, so the assertion below isn't polluted by setUp()'s own writes.
        $this->client->request(Request::METHOD_GET, '/admin/seasons');

        $owner = $this->getUserByEmail('test@example.org');
        for ($i = 0; $i < 20; ++$i) {
            $season = new Season();
            $season->name = \sprintf('Extra Season %d', $i);
            $season->seasonCode = \sprintf('sn%03d', $i);
            $season->addOwner($owner);

            $quiz = new Quiz();
            $quiz->name = 'Extra Quiz';
            $season->addQuiz($quiz);
            $season->activeQuiz = $quiz;

            $this->entityManager->persist($season);
        }

        $this->entityManager->flush();

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, '/admin/seasons');

        self::assertResponseIsSuccessful();
        // A flat query count regardless of row count: one query for the seasons + activeQuiz,
        // one to hydrate owners, one to hydrate quizzes, plus the auth reload for the logged-in
        // admin. If this creeps up, something reintroduced a per-row lazy load (N+1).
        $this->assertLessThanOrEqual(5, $this->getQueryCount(), 'Adding more seasons should not add more queries (N+1 on owners/quizzes/activeQuiz).');
    }

    public function testSeasonsTabIsDeniedForNonAdmin(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, '/admin/seasons');

        self::assertResponseStatusCodeSame(403);
    }

    public function testResetPasswordSendsExactlyOneEmailAndFlashesSuccess(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/reset-password', $user->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/reset-password', $user->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin');
        self::assertEmailCount(1);

        $this->client->followRedirect();
        $this->assertStringContainsString('A password reset email has been sent to this user.', (string) $this->client->getResponse()->getContent());
    }

    public function testResetPasswordBypassesTheUsersOwnThrottle(): void
    {
        $user = $this->getUserByEmail('test@example.org');

        // Simulate the user having just hit the self-service form's throttle themselves.
        self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user);

        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/reset-password', $user->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/reset-password', $user->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin');
        self::assertEmailCount(1);

        $this->client->followRedirect();
        $this->assertStringContainsString('A password reset email has been sent to this user.', (string) $this->client->getResponse()->getContent());
    }

    public function testDeleteUserRemovesTheUserAndFlashesSuccess(): void
    {
        $userId = $this->getUserByEmail('sole-owner@example.org')->id;
        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/delete', $userId));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/delete', $userId), [
            '_token' => $token,
            'confirmation' => 'delete',
        ]);

        self::assertResponseRedirects('/admin');
        $this->entityManager->clear();

        $this->assertNotInstanceOf(User::class, $this->entityManager->getRepository(User::class)->find($userId));
    }

    public function testDeleteUserWithoutConfirmationKeepsUser(): void
    {
        $userId = $this->getUserByEmail('sole-owner@example.org')->id;
        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/delete', $userId));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/delete', $userId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin');
        $this->entityManager->clear();

        $this->assertInstanceOf(User::class, $this->entityManager->getRepository(User::class)->find($userId));
    }

    public function testDeleteUserWithWrongConfirmationKeepsUser(): void
    {
        $userId = $this->getUserByEmail('sole-owner@example.org')->id;
        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/delete', $userId));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/delete', $userId), [
            '_token' => $token,
            'confirmation' => 'wrong',
        ]);

        self::assertResponseRedirects('/admin');
        $this->entityManager->clear();

        $this->assertInstanceOf(User::class, $this->entityManager->getRepository(User::class)->find($userId));
    }

    public function testAdminCannotDeleteTheirOwnAccountThroughThisRoute(): void
    {
        $admin = $this->getUserByEmail(TestFixtures::ADMIN_EMAIL);

        // The delete button/modal for the admin's own row is intentionally not rendered (see
        // admin/tab_users.html.twig), so a valid token is scraped from another user's delete form —
        // the CSRF token id is the same for every row — to verify the controller-side guard directly.
        $other = $this->getUserByEmail('test@example.org');
        $token = $this->getCsrfTokenFromPage('/admin', \sprintf('%s/delete', $other->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/users/%s/delete', $admin->id), [
            '_token' => $token,
            'confirmation' => 'delete',
        ]);

        self::assertResponseRedirects('/admin');
        $this->entityManager->clear();

        $this->assertInstanceOf(User::class, $this->entityManager->getRepository(User::class)->find($admin->id));
    }

    private function getQueryCount(): int
    {
        $profile = $this->client->getProfile();
        $this->assertNotFalse($profile);
        $this->assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        $this->assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
