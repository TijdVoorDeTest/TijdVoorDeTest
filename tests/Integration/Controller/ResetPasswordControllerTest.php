<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Controller\ResetPasswordController;
use Tvdt\Entity\User;

#[CoversClass(ResetPasswordController::class)]
final class ResetPasswordControllerTest extends AbstractControllerWebTestCase
{
    public function testRequestPageLoads(): void
    {
        $this->client->request(Request::METHOD_GET, '/reset-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testRequestPagePrefillsEmailFromQueryParameter(): void
    {
        $this->client->request(Request::METHOD_GET, '/reset-password?email=test@example.org');

        $this->assertResponseIsSuccessful();
        $emailInput = $this->client->getCrawler()->filter('input[name="reset_password_request_form[email]"]');
        $this->assertSame('test@example.org', $emailInput->attr('value'));
    }

    /** @return iterable<string, array{string}> */
    public static function emailProvider(): iterable
    {
        yield 'unknown email' => ['unknown@example.org'];
        yield 'known email' => ['test@example.org'];
    }

    #[DataProvider('emailProvider')]
    public function testRequestRedirectsToCheckEmail(string $email): void
    {
        $this->client->request(Request::METHOD_GET, '/reset-password');
        $form = $this->client->getCrawler()->filter('form')->form([
            'reset_password_request_form[email]' => $email,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/reset-password/check-email');
    }

    public function testCheckEmailPageLoads(): void
    {
        $this->client->request(Request::METHOD_GET, '/reset-password/check-email');

        $this->assertResponseIsSuccessful();
    }

    public function testResetWithInvalidTokenRedirectsToRequest(): void
    {
        $this->client->request(Request::METHOD_GET, '/reset-password/reset/invalidtoken');
        $this->client->followRedirect();

        $this->assertResponseRedirects('/reset-password');
    }

    public function testFullResetFlow(): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.org']);
        $this->assertInstanceOf(User::class, $user);

        /** @var ResetPasswordHelperInterface $helper */
        $helper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        $resetToken = $helper->generateResetToken($user);

        $this->client->request(Request::METHOD_GET, '/reset-password/reset/'.$resetToken->getToken());
        $this->assertResponseRedirects('/reset-password/reset');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->filter('form')->form([
            'change_password_form[plainPassword][first]' => 'NewPass123!',
            'change_password_form[plainPassword][second]' => 'NewPass123!',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/backoffice/');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.org']);
        $this->assertInstanceOf(User::class, $updatedUser);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($updatedUser, 'NewPass123!'));
    }
}
