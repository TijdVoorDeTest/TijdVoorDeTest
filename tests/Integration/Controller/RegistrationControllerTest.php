<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Tvdt\Controller\RegistrationController;

#[CoversClass(RegistrationController::class)]
final class RegistrationControllerTest extends AbstractControllerWebTestCase
{
    public function testRegisterPageLoadsWhenNotAuthenticated(): void
    {
        $this->client->request(Request::METHOD_GET, '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testRegisterRedirectsToMolshoopWhenAlreadyAuthenticated(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, '/register');

        self::assertResponseRedirects('/molshoop/');
    }

    public function testRegisterCreatesUserSendsConfirmationAndLogsIn(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/register');
        $form = $crawler->filter('form')->form([
            'registration_form[email]' => 'newuser@example.org',
            'registration_form[plainPassword][first]' => 'NewPass123!',
            'registration_form[plainPassword][second]' => 'NewPass123!',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/molshoop/');
        self::assertEmailCount(1);

        $this->entityManager->clear();
        $user = $this->getUserByEmail('newuser@example.org');
        $this->assertFalse($user->isVerified);
    }

    public function testRegisterSetsLocaleFromBrowserLanguage(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en-US,en;q=0.9');

        $crawler = $this->client->request(Request::METHOD_GET, '/register');
        $form = $crawler->filter('form')->form([
            'registration_form[email]' => 'english-speaker@example.org',
            'registration_form[plainPassword][first]' => 'NewPass123!',
            'registration_form[plainPassword][second]' => 'NewPass123!',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/molshoop/');

        $this->entityManager->clear();
        $user = $this->getUserByEmail('english-speaker@example.org');
        $this->assertSame('en', $user->locale);
    }

    public function testVerifyEmailWithoutIdRedirectsToRegister(): void
    {
        $this->client->request(Request::METHOD_GET, '/verify/email');

        self::assertResponseRedirects('/register');
    }

    public function testVerifyEmailWithUnknownIdRedirectsToRegister(): void
    {
        $this->client->request(Request::METHOD_GET, '/verify/email', ['id' => '00000000-0000-0000-0000-000000000000']);

        self::assertResponseRedirects('/register');
    }

    public function testVerifyEmailWithValidSignatureMarksUserVerified(): void
    {
        $user = $this->getUserByEmail('test@example.org');
        $this->assertFalse($user->isVerified);

        /** @var VerifyEmailHelperInterface $helper */
        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $signature = $helper->generateSignature('tvdt_verify_email', $user->id->toRfc4122(), $user->email, ['id' => $user->id]);

        $this->client->request(Request::METHOD_GET, $signature->getSignedUrl());

        self::assertResponseRedirects('/molshoop/');

        $this->entityManager->clear();
        $updatedUser = $this->getUserByEmail('test@example.org');
        $this->assertTrue($updatedUser->isVerified);
    }

    public function testVerifyEmailWithInvalidSignatureShowsErrorAndRedirects(): void
    {
        $user = $this->getUserByEmail('test@example.org');

        $this->client->request(Request::METHOD_GET, '/verify/email', ['id' => (string) $user->id, 'expires' => '9999999999', 'signature' => 'invalid']);

        self::assertResponseRedirects('/register');
    }
}
