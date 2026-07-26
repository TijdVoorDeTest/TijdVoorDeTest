<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Safe\DateTimeImmutable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Entity\User;
use Tvdt\Repository\UserRepository;
use Tvdt\Security\ResetPasswordMailer;

#[CoversClass(ResetPasswordMailer::class)]
final class ResetPasswordMailerTest extends TestCase
{
    public function testReturnsTheTokenOnSuccess(): void
    {
        $user = new User();
        $user->email = 'test@example.org';

        $token = new ResetPasswordToken('token', new DateTimeImmutable('+1 hour'), time());

        $resetPasswordHelper = $this->createStub(ResetPasswordHelperInterface::class);
        $resetPasswordHelper->method('generateResetToken')->willReturn($token);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('invalidateResetPasswordRequests');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $result = new ResetPasswordMailer($resetPasswordHelper, $userRepository, $mailer, $this->translator(), $logger)->send($user);

        $this->assertSame($token, $result);
    }

    public function testInvalidatesTheFreshTokenAndReturnsNullOnTransportFailure(): void
    {
        $user = new User();
        $user->email = 'test@example.org';

        $token = new ResetPasswordToken('token', new DateTimeImmutable('+1 hour'), time());

        $resetPasswordHelper = $this->createStub(ResetPasswordHelperInterface::class);
        $resetPasswordHelper->method('generateResetToken')->willReturn($token);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willThrowException(new TransportException('boom'));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('invalidateResetPasswordRequests')->with($user);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $result = new ResetPasswordMailer($resetPasswordHelper, $userRepository, $mailer, $this->translator(), $logger)->send($user);

        $this->assertNotInstanceOf(ResetPasswordToken::class, $result);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
