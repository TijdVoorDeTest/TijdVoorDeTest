<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Tvdt\Controller\RegistrationController;

#[CoversClass(RegistrationController::class)]
final class RegistrationControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The limiter's cache outlives individual tests and test runs, so reset it
        // to keep the attempt count deterministic.
        $limiterFactory = self::getContainer()->get('limiter.registration');
        $this->assertInstanceOf(RateLimiterFactory::class, $limiterFactory);
        $limiterFactory->create('127.0.0.1')->reset();
        $limiterFactory->create('203.0.113.9')->reset();
    }

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

    public function testRegisterIsThrottledAfterTooManyAttempts(): void
    {
        // Invalid submissions still count against the limiter but keep the client
        // anonymous, because successful registrations would auto-login and make
        // GET /register redirect away from the form.

        for ($i = 0; $i < 3; ++$i) {
            $crawler = $this->client->request(Request::METHOD_GET, '/register');
            $form = $crawler->filter('form')->form([
                'registration_form[email]' => "throttled-{$i}@example.org",
                'registration_form[plainPassword][first]' => 'NewPass123!',
                'registration_form[plainPassword][second]' => 'DifferentPass123!',
            ]);
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
        }

        $crawler = $this->client->request(Request::METHOD_GET, '/register');
        $form = $crawler->filter('form')->form([
            'registration_form[email]' => 'throttled-3@example.org',
            'registration_form[plainPassword][first]' => 'NewPass123!',
            'registration_form[plainPassword][second]' => 'DifferentPass123!',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(429);
    }

    public function testRegisterThrottleKeysOnForwardedClientIp(): void
    {
        $submit = function (string $clientIp): void {
            $crawler = $this->client->request(Request::METHOD_GET, '/register');
            $form = $crawler->filter('form')->form([
                'registration_form[email]' => 'forwarded@example.org',
                'registration_form[plainPassword][first]' => 'NewPass123!',
                'registration_form[plainPassword][second]' => 'DifferentPass123!',
            ]);
            $this->client->setServerParameter('HTTP_X_FORWARDED_FOR', $clientIp);
            $this->client->submit($form);
        };

        for ($i = 0; $i < 3; ++$i) {
            $submit('203.0.113.9');
            self::assertResponseStatusCodeSame(422);
        }

        $submit('203.0.113.9');
        self::assertResponseStatusCodeSame(429);

        // A different client behind the same proxy has its own bucket.
        $submit('203.0.113.10');
        self::assertResponseStatusCodeSame(422);
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
