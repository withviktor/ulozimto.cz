<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/auth')]
class AuthController extends AbstractController
{
    #[Route('/login', name: 'auth_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $utils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error'         => $utils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'auth_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email    = trim($request->request->get('email', ''));
            $password = $request->request->get('password', '');
            $confirm  = $request->request->get('password_confirm', '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Neplatná e-mailová adresa.';
            } elseif (strlen($password) < 8) {
                $error = 'Heslo musí mít alespoň 8 znaků.';
            } elseif ($password !== $confirm) {
                $error = 'Hesla se neshodují.';
            } else {
                $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existing) {
                    $error = 'Tento e-mail je již registrován.';
                } else {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setPassword($hasher->hashPassword($user, $password));

                    $em->persist($user);
                    $em->flush();

                    $this->addFlash('success', 'Účet byl vytvořen. Přihlaste se.');
                    return $this->redirectToRoute('auth_login');
                }
            }
        }

        return $this->render('auth/register.html.twig', ['error' => $error]);
    }

    #[Route('/logout', name: 'auth_logout', methods: ['GET'])]
    public function logout(): never
    {
        // Symfony toto zpracuje automaticky dle security.yaml
        throw new \LogicException('Tato metoda nesmí být nikdy volána přímo.');
    }
}
