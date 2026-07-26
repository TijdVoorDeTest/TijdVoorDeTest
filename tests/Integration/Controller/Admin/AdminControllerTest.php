<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Controller\Admin\AdminController;
use Tvdt\DataFixtures\TestFixtures;
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
        $crawler = $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('test@example.org', $crawler->filter('body')->text());
    }

    public function testSeasonsTabListsAllSeasons(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/seasons');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Krtek', $crawler->filter('body')->text());
        $this->assertStringContainsString('Doomed Season', $crawler->filter('body')->text());
    }

    public function testUsersTabIsDeniedForNonAdmin(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseStatusCodeSame(403);
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
}
