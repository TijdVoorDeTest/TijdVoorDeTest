<?php

declare(strict_types=1);

namespace Tvdt\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Tvdt\Entity\User;
use Tvdt\Enum\FlashType;
use Tvdt\Form\RegistrationFormType;
use Tvdt\Repository\UserRepository;
use Tvdt\Security\EmailVerifier;

final class RegistrationController extends AbstractController
{
    public function __construct(private readonly EmailVerifier $emailVerifier, private readonly TranslatorInterface $translator, private readonly UserPasswordHasherInterface $userPasswordHasher, private readonly Security $security, private readonly UserRepository $userRepository, private readonly EntityManagerInterface $entityManager) {}

    #[Route('/register', name: 'tvdt_register')]
    public function register(
        Request $request,
    ): Response {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('tvdt_molshoop_index');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->password = $this->userPasswordHasher->hashPassword($user, $plainPassword);
            $user->locale = $request->getPreferredLanguage(['nl', 'en']) ?? 'nl';

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendDefaultConfirmation($user);

            $response = $this->security->login($user, 'form_login', 'main');
            \assert($response instanceof Response);

            return $response;
        }

        return $this->render('molshoop/registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'tvdt_verify_email')]
    public function verifyUserEmail(Request $request): RedirectResponse
    {
        $id = $request->query->get('id');

        if (null === $id) {
            return $this->redirectToRoute('tvdt_register');
        }

        $user = $this->userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('tvdt_register');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $verifyEmailException) {
            $this->addFlash('verify_email_error', $this->translator->trans($verifyEmailException->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('tvdt_register');
        }

        $this->addFlash(FlashType::Success->value, $this->translator->trans('Your email address has been verified.'));

        return $this->redirectToRoute('tvdt_molshoop_index');
    }
}
