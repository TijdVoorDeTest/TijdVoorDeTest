<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\User;
use Tvdt\Enum\FlashType;
use Tvdt\Form\ChangeEmailFormType;
use Tvdt\Form\ChangeUserPasswordFormType;
use Tvdt\Helpers\FilenameSanitizer;
use Tvdt\Repository\UserRepository;
use Tvdt\Security\EmailVerifier;
use Tvdt\Service\DataExportService;

final class SettingsController extends AbstractController
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly EmailVerifier $emailVerifier,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly DataExportService $dataExportService,
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {}

    #[Route('/molshoop/settings', name: 'tvdt_molshoop_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderSettings();
    }

    #[IsCsrfTokenValid('settings_language')]
    #[Route('/molshoop/settings/language', name: 'tvdt_molshoop_settings_language', methods: ['POST'])]
    public function saveLanguage(Request $request): RedirectResponse
    {
        $language = (string) $request->request->get('language', '');

        if (\in_array($language, $this->enabledLocales, true)) {
            $this->authenticatedUser->locale = $language;
            $this->entityManager->flush();
        }

        $this->addFlash(FlashType::Success, $this->translator->trans('Language saved'));

        return $this->redirectToRoute('tvdt_molshoop_settings');
    }

    #[Route('/molshoop/settings/password', name: 'tvdt_molshoop_settings_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $user = $this->authenticatedUser;
        $form = $this->createForm(ChangeUserPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);
            $this->entityManager->flush();
            $this->userRepository->invalidateResetPasswordRequests($user);

            $this->security->login($user, 'form_login', 'main');
            $this->addFlash(FlashType::Success, $this->translator->trans('Your password has been changed.'));

            return $this->redirectToRoute('tvdt_molshoop_settings');
        }

        return $this->renderSettings(passwordForm: $form);
    }

    #[Route('/molshoop/settings/email', name: 'tvdt_molshoop_settings_email', methods: ['POST'])]
    public function changeEmail(Request $request): Response
    {
        $user = $this->authenticatedUser;
        $form = $this->createForm(ChangeEmailFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            $found = $this->userRepository->findOneBy(['email' => $email]);
            if ($found instanceof User && $found !== $user) {
                $form->get('email')->addError(new FormError($this->translator->trans('There is already an account with this email')));

                return $this->renderSettings(emailForm: $form);
            }

            $originalEmail = $user->email;
            $originalIsVerified = $user->isVerified;

            $user->email = $email;
            $user->isVerified = false;

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // A concurrent request can claim the email between the uniqueness check above and the flush
                $user->email = $originalEmail;
                $user->isVerified = $originalIsVerified;
                $form->get('email')->addError(new FormError($this->translator->trans('There is already an account with this email')));

                return $this->renderSettings(emailForm: $form);
            }

            $this->userRepository->invalidateResetPasswordRequests($user);

            if ($this->emailVerifier->sendDefaultConfirmation($user)) {
                $this->addFlash(FlashType::Success, $this->translator->trans('Your email address has been changed. Please check your inbox to confirm it.'));
            } else {
                $this->addFlash(FlashType::Success, $this->translator->trans('Your email address has been changed.'));
                $this->addFlash(FlashType::Warning, $this->translator->trans('The confirmation email could not be sent. Please use the resend button to try again.'));
            }

            $this->security->login($user, 'form_login', 'main');

            return $this->redirectToRoute('tvdt_molshoop_settings');
        }

        return $this->renderSettings(emailForm: $form);
    }

    #[IsCsrfTokenValid('resend_confirmation')]
    #[Route('/molshoop/settings/resend-confirmation', name: 'tvdt_molshoop_settings_resend_confirmation', methods: ['POST'])]
    public function resendConfirmationEmail(): RedirectResponse
    {
        $user = $this->authenticatedUser;

        if ($user->isVerified) {
            $this->addFlash(FlashType::Info, $this->translator->trans('Your email address is already confirmed.'));

            return $this->redirectToRoute('tvdt_molshoop_settings');
        }

        if ($this->emailVerifier->sendDefaultConfirmation($user)) {
            $this->addFlash(FlashType::Success, $this->translator->trans('A new confirmation email has been sent. Please check your inbox.'));
        } else {
            $this->addFlash(FlashType::Warning, $this->translator->trans('The confirmation email could not be sent. Please try again later.'));
        }

        return $this->redirectToRoute('tvdt_molshoop_settings');
    }

    #[Route('/molshoop/settings/download-data', name: 'tvdt_molshoop_settings_download_data', methods: ['GET'])]
    public function downloadData(): Response
    {
        if (!$this->authenticatedUser->isVerified) {
            $this->addFlash(FlashType::Warning, $this->translator->trans('Please confirm your email address before downloading your data.'));

            return $this->redirectToRoute('tvdt_molshoop_settings');
        }

        $zipPath = $this->dataExportService->exportForUser($this->authenticatedUser);

        $filename = \sprintf(
            'tijd-voor-de-test-data-%s-%s.zip',
            FilenameSanitizer::sanitize($this->authenticatedUser->email),
            new DateTimeImmutable()->format('Y-m-d_H-i-s'),
        );

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    #[IsCsrfTokenValid('delete_account')]
    #[Route('/molshoop/settings/delete', name: 'tvdt_molshoop_settings_delete', methods: ['POST'])]
    public function deleteAccount(Request $request): Response
    {
        $user = $this->authenticatedUser;
        $password = (string) $request->request->get('password', '');

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Wrong password, your account has not been deleted.'));

            return $this->redirectToRoute('tvdt_molshoop_settings');
        }

        $this->userRepository->deleteUser($user);

        return $this->security->logout(false) ?? $this->redirectToRoute('tvdt_login_login');
    }

    /**
     * @param FormInterface<array{currentPassword: string, plainPassword: string}|null>|null $passwordForm
     * @param FormInterface<array{email: string}|null>|null                                  $emailForm
     */
    private function renderSettings(?FormInterface $passwordForm = null, ?FormInterface $emailForm = null): Response
    {
        return $this->render('molshoop/settings/index.html.twig', [
            'passwordForm' => $passwordForm ?? $this->createForm(ChangeUserPasswordFormType::class),
            'emailForm' => $emailForm ?? $this->createForm(ChangeEmailFormType::class),
        ]);
    }
}
