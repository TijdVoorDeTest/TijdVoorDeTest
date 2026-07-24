<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tvdt\Controller\Backoffice\SettingsController;
use Tvdt\DataFixtures\TestFixtures;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\ResetPasswordRequest;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(SettingsController::class)]
final class SettingsControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('test@example.org');
    }

    private function getCsrfTokenFromSettings(string $formActionContains): string
    {
        return $this->getCsrfTokenFromPage('/backoffice/settings', $formActionContains);
    }

    public function testSettingsPageLoadsAndNavContainsSettingsLink(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/backoffice/settings"]');
    }

    public function testSettingsPageRequiresAuthentication(): void
    {
        $this->client->restart();
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');

        self::assertResponseRedirects();
    }

    public function testLanguageSaveRedirectsBackToSettings(): void
    {
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/language');

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/language', [
            '_token' => $token,
            'language' => 'nl',
        ]);

        self::assertResponseRedirects('/backoffice/settings');
    }

    public function testChangePassword(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/password"]')->form([
            'change_user_password_form[currentPassword]' => TestFixtures::PASSWORD,
            'change_user_password_form[plainPassword][first]' => 'NewPass123!',
            'change_user_password_form[plainPassword][second]' => 'NewPass123!',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/settings');
        $this->entityManager->clear();

        $user = $this->getUserByEmail('test@example.org');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'NewPass123!'));

        // User stays logged in
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPasswordChangeProvider(): iterable
    {
        yield 'wrong current password' => ['wrong-password', 'NewPass123!', 'NewPass123!'];
        yield 'mismatched repeat' => [TestFixtures::PASSWORD, 'NewPass123!', 'SomethingElse!'];
    }

    #[DataProvider('invalidPasswordChangeProvider')]
    public function testChangePasswordIsRejected(string $currentPassword, string $first, string $second): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/password"]')->form([
            'change_user_password_form[currentPassword]' => $currentPassword,
            'change_user_password_form[plainPassword][first]' => $first,
            'change_user_password_form[plainPassword][second]' => $second,
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();

        $user = $this->getUserByEmail('test@example.org');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, TestFixtures::PASSWORD));
    }

    public function testChangeEmailSendsConfirmationAndKeepsUserLoggedIn(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/email"]')->form([
            'change_email_form[email]' => 'new-address@example.org',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/settings');
        self::assertEmailCount(1);
        $this->entityManager->clear();

        $this->assertNotInstanceOf(User::class, $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.org']));
        $user = $this->getUserByEmail('new-address@example.org');
        $this->assertFalse($user->isVerified);

        // User stays logged in
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        self::assertResponseIsSuccessful();
    }

    public function testChangeEmailToTakenAddressIsRejected(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/email"]')->form([
            'change_email_form[email]' => 'user1@example.org',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
        $this->entityManager->clear();

        $this->getUserByEmail('test@example.org');
    }

    public function testResendConfirmationEmailSendsEmail(): void
    {
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/resend-confirmation');

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/resend-confirmation', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/settings');
        self::assertEmailCount(1);
    }

    public function testResendConfirmationEmailForVerifiedUserSendsNothing(): void
    {
        // Get a valid CSRF token while still unverified, then mark the user as verified
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/resend-confirmation');

        $user = $this->getUserByEmail('test@example.org');
        $user->isVerified = true;

        $this->entityManager->flush();

        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('form[action*="/backoffice/settings/resend-confirmation"]'));

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/resend-confirmation', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/settings');
        self::assertEmailCount(0);
    }

    public function testChangeEmailToSameAddressIsAccepted(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/email"]')->form([
            'change_email_form[email]' => 'test@example.org',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/settings');
    }

    private function createResetPasswordRequest(User $user): void
    {
        $request = new ResetPasswordRequest(
            $user,
            new DateTimeImmutable('+1 hour'),
            str_repeat('a', 20),
            str_repeat('b', 100),
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    public function testChangePasswordInvalidatesResetPasswordRequests(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        $this->createResetPasswordRequest($user);

        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/password"]')->form([
            'change_user_password_form[currentPassword]' => TestFixtures::PASSWORD,
            'change_user_password_form[plainPassword][first]' => 'NewPass123!',
            'change_user_password_form[plainPassword][second]' => 'NewPass123!',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/settings');
        $this->entityManager->clear();

        $user = $this->getUserByEmail('test@example.org');
        $this->assertSame(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->count(['user' => $user]));
    }

    public function testChangeEmailInvalidatesResetPasswordRequests(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        $this->createResetPasswordRequest($user);

        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        $form = $this->client->getCrawler()->filter('form[action*="/backoffice/settings/email"]')->form([
            'change_email_form[email]' => 'new-address@example.org',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/settings');
        $this->entityManager->clear();

        $user = $this->getUserByEmail('new-address@example.org');
        $this->assertSame(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->count(['user' => $user]));
    }

    public function testDeleteAccountWithWrongPasswordIsRejected(): void
    {
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/delete', [
            '_token' => $token,
            'password' => 'wrong-password',
        ]);

        self::assertResponseRedirects('/backoffice/settings');
        $this->entityManager->clear();

        $this->getUserByEmail('test@example.org');
    }

    public function testDeleteAccountRemovesSoleOwnerSeasonsAndKeepsSharedSeasons(): void
    {
        $this->loginAs('sole-owner@example.org');
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/delete', [
            '_token' => $token,
            'password' => TestFixtures::PASSWORD,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $this->assertNotInstanceOf(User::class, $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'sole-owner@example.org']));

        // Sole-owner season is removed, including its quiz
        $this->assertNotInstanceOf(Season::class, $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => 'doomd']));
        $this->assertNotInstanceOf(Quiz::class, $this->entityManager->getRepository(Quiz::class)->findOneBy(['name' => 'Doomed Quiz']));

        // Shared season survives, without the deleted owner
        $anotherSeason = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => 'bbbbb']);
        $this->assertInstanceOf(Season::class, $anotherSeason);
        $ownerEmails = $anotherSeason->owners->map(static fn (User $owner): string => $owner->email)->toArray();
        $this->assertNotContains('sole-owner@example.org', $ownerEmails);
        $this->assertContains('user1@example.org', $ownerEmails);

        // User is logged out
        $this->client->request(Request::METHOD_GET, '/backoffice/settings');
        self::assertResponseRedirects();
    }

    public function testDeleteAccountKeepsMultiOwnerSeasons(): void
    {
        $this->loginAs('user2@example.org');
        $token = $this->getCsrfTokenFromSettings('/backoffice/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/settings/delete', [
            '_token' => $token,
            'password' => TestFixtures::PASSWORD,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $this->assertNotInstanceOf(User::class, $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'user2@example.org']));

        foreach (['krtek', 'bbbbb'] as $seasonCode) {
            $season = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => $seasonCode]);
            $this->assertInstanceOf(Season::class, $season);
            $ownerEmails = $season->owners->map(static fn (User $owner): string => $owner->email)->toArray();
            $this->assertNotContains('user2@example.org', $ownerEmails);
            $this->assertNotEmpty($ownerEmails);
        }
    }

    public function testDownloadDataRequiresAuthentication(): void
    {
        $this->client->restart();
        $this->client->request(Request::METHOD_GET, '/backoffice/settings/download-data');

        self::assertResponseRedirects();
    }

    public function testDownloadDataReturnsAZipWithATimestampedAccountFilename(): void
    {
        $this->markUserVerified('test@example.org');

        $this->client->request(Request::METHOD_GET, '/backoffice/settings/download-data');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression(
            '/filename=tijd-voor-de-test-data-test-example-org-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip/',
            $disposition,
        );
    }

    public function testDownloadDataRequiresVerifiedEmail(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        $this->assertFalse($user->isVerified);

        $this->client->request(Request::METHOD_GET, '/backoffice/settings/download-data');

        self::assertResponseRedirects('/backoffice/settings');
    }

    private function markUserVerified(string $email): void
    {
        $user = $this->getUserByEmail($email);
        $user->isVerified = true;

        $this->entityManager->flush();
    }
}
