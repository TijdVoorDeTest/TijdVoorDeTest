<?php

declare(strict_types=1);

namespace Tvdt\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\User;
use Tvdt\Enum\FlashType;
use Tvdt\Repository\SeasonRepository;
use Tvdt\Repository\UserRepository;
use Tvdt\Security\ResetPasswordMailer;

#[AsController]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly ResetPasswordMailer $resetPasswordMailer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin', name: 'tvdt_admin_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('admin/index.html.twig', [
            'activeTab' => 'users',
            'template' => 'admin/tab_users.html.twig',
            'users' => $this->userRepository->findAllForAdminOverview(),
        ]);
    }

    #[Route('/admin/seasons', name: 'tvdt_admin_seasons', methods: ['GET'])]
    public function seasons(): Response
    {
        return $this->render('admin/index.html.twig', [
            'activeTab' => 'seasons',
            'template' => 'admin/tab_seasons.html.twig',
            'seasons' => $this->seasonRepository->findAllForAdminOverview(),
        ]);
    }

    #[IsCsrfTokenValid('admin_reset_password')]
    #[Route(
        '/admin/users/{user}/reset-password',
        name: 'tvdt_admin_user_reset_password',
        requirements: ['user' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function resetPassword(User $user): RedirectResponse
    {
        // An admin-triggered reset must not be blocked by the throttle that guards the user's own
        // self-service request form (e.g. a user asking for support minutes after trying it themselves).
        $this->userRepository->invalidateResetPasswordRequests($user);

        try {
            $resetToken = $this->resetPasswordMailer->send($user);
        } catch (ResetPasswordExceptionInterface) {
            $resetToken = null;
        }

        if (!$resetToken instanceof ResetPasswordToken) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Could not send a password reset email to this user.'));

            return $this->redirectToRoute('tvdt_admin_users');
        }

        $this->addFlash(FlashType::Success, $this->translator->trans('A password reset email has been sent to this user.'));

        return $this->redirectToRoute('tvdt_admin_users');
    }

    #[IsCsrfTokenValid('admin_delete_user')]
    #[Route(
        '/admin/users/{user}/delete',
        name: 'tvdt_admin_user_delete',
        requirements: ['user' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function deleteUser(User $user, Request $request): RedirectResponse
    {
        if ($user === $this->authenticatedUser) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Use Settings to delete your own account.'));

            return $this->redirectToRoute('tvdt_admin_users');
        }

        $confirmation = mb_strtolower(mb_trim($request->request->getString('confirmation')));

        if (!\in_array($confirmation, ['verwijderen', 'delete'], true)) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Type delete to confirm'));

            return $this->redirectToRoute('tvdt_admin_users');
        }

        $this->userRepository->deleteUser($user);

        $this->addFlash(FlashType::Success, $this->translator->trans('User deleted'));

        return $this->redirectToRoute('tvdt_admin_users');
    }
}
