<?php

declare(strict_types=1);

namespace Tvdt\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Tvdt\Entity\User;
use Tvdt\Enum\FlashType;
use Tvdt\Form\ChangePasswordFormType;
use Tvdt\Form\ResetPasswordRequestFormType;
use Tvdt\Security\ResetPasswordMailer;

final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordMailer $resetPasswordMailer,
        private readonly TranslatorInterface $translator,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/reset-password', name: 'tvdt_forgot_password_request')]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class, [
            'email' => $request->query->get('email', ''),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            return $this->processSendingPasswordResetEmail($email);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/reset-password/check-email', name: 'tvdt_check_email')]
    public function checkEmail(): Response
    {
        if (!($resetToken = $this->getTokenObjectFromSession()) instanceof ResetPasswordToken) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'tvdt_reset_password')]
    public function reset(Request $request, ?string $token = null): Response
    {
        if ($token) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('tvdt_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $resetPasswordException) {
            $this->addFlash(FlashType::Danger->value, \sprintf(
                '%s - %s',
                $this->translator->trans(ResetPasswordExceptionInterface::MESSAGE_PROBLEM_VALIDATE, [], 'ResetPasswordBundle'),
                $this->translator->trans($resetPasswordException->getReason(), [], 'ResetPasswordBundle'),
            ));

            return $this->redirectToRoute('tvdt_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            return $this->redirectToRoute('tvdt_molshoop_index');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
            'email' => $user->email,
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $emailFormData,
        ]);

        if (!$user instanceof User) {
            return $this->redirectToRoute('tvdt_check_email');
        }

        try {
            $resetToken = $this->resetPasswordMailer->send($user);
        } catch (ResetPasswordExceptionInterface) {
            return $this->redirectToRoute('tvdt_check_email');
        }

        if (!$resetToken instanceof ResetPasswordToken) {
            return $this->redirectToRoute('tvdt_check_email');
        }

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('tvdt_check_email');
    }
}
