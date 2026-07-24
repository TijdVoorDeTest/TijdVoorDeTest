<?php

declare(strict_types=1);

namespace Tvdt\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Enum\FlashType;

#[AsController]
final class LoginController extends AbstractController
{
    public function __construct(private readonly AuthenticationUtils $authenticationUtils, private readonly TranslatorInterface $translator) {}

    #[Route(path: '/login', name: 'tvdt_login_login')]
    public function login(): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('tvdt_molshoop_index');
        }

        // get the login error if there is one
        $error = $this->authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $this->authenticationUtils->getLastUsername();
        if ($error instanceof AuthenticationException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans($error->getMessageKey(), $error->getMessageData(), 'security'));
        }

        return $this->render('molshoop/login/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'tvdt_login_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
