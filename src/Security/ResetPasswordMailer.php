<?php

declare(strict_types=1);

namespace Tvdt\Security;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Entity\User;
use Tvdt\Repository\UserRepository;

/** Shared by the self-service reset-password request flow and the admin-triggered one, so the
 * token-generation + email-sending logic exists in exactly one place. */
readonly class ResetPasswordMailer
{
    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {}

    /** Generates a reset token and emails it to $user. Returns null (after invalidating the
     * freshly generated token, and logging) on a mail transport failure, so a stray token isn't
     * left usable when its email never actually arrived.
     *
     * @throws ResetPasswordExceptionInterface
     */
    public function send(User $user): ?ResetPasswordToken
    {
        $resetToken = $this->resetPasswordHelper->generateResetToken($user);

        $email = new TemplatedEmail()
            ->to($user->getUserIdentifier())
            ->subject($this->translator->trans('Your password reset request'))
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['resetToken' => $resetToken]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $transportException) {
            $this->logger->error($transportException->getMessage());
            $this->userRepository->invalidateResetPasswordRequests($user);

            return null;
        }

        return $resetToken;
    }
}
