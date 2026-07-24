<?php

declare(strict_types=1);

namespace Tvdt\Security;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Tvdt\Entity\User;

readonly class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {}

    /** Sends the standard confirmation email to the user. Returns false (and logs) on transport errors. */
    public function sendDefaultConfirmation(User $user): bool
    {
        try {
            $this->sendEmailConfirmation('tvdt_verify_email', $user,
                new TemplatedEmail()
                    ->to($user->email)
                    ->subject($this->translator->trans('Please Confirm your Email'))
                    ->htmlTemplate('molshoop/registration/confirmation_email.html.twig'),
            );

            return true;
        } catch (TransportExceptionInterface $transportException) {
            $this->logger->error($transportException->getMessage());

            return false;
        }
    }

    /** @throws TransportExceptionInterface */
    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email): void
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            $user->id->toRfc4122(),
            $user->email,
            ['id' => $user->id],
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email->context($context);

        $this->mailer->send($email);
    }

    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, $user->id->toRfc4122(), $user->email);

        $user->isVerified = true;

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
