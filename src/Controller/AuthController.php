<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface        $mailer,
        private readonly EntityManagerInterface $em,
        private readonly string                 $mailerFrom,
        private readonly string                 $mailerFromName,
        private readonly RateLimiterFactory     $registerLimiter,
    ) {}

    // ── Login ────────────────────────────────────────────────────────

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

    // ── Register ─────────────────────────────────────────────────────

    #[Route('/register', name: 'auth_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            // Rate limit: 10 registrací za hodinu z jedné IP
            $limiter = $this->registerLimiter->create($request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $error = 'Příliš mnoho pokusů o registraci. Zkus to za hodinu.';
                return $this->render('auth/register.html.twig', ['error' => $error]);
            }
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
                $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existing) {
                    $error = 'Tento e-mail je již registrován.';
                } else {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setPassword($hasher->hashPassword($user, $password));

                    $this->em->persist($user);
                    $this->em->flush();

                    $this->addFlash('success', 'Účet byl vytvořen. Přihlaste se.');
                    return $this->redirectToRoute('auth_login');
                }
            }
        }

        return $this->render('auth/register.html.twig', ['error' => $error]);
    }

    // ── Logout ───────────────────────────────────────────────────────

    #[Route('/logout', name: 'auth_logout', methods: ['GET'])]
    public function logout(): never
    {
        // Symfony toto zpracuje automaticky dle security.yaml
        throw new \LogicException('Tato metoda nesmí být nikdy volána přímo.');
    }

    // ── Forgot password ──────────────────────────────────────────────

    #[Route('/forgot-password', name: 'auth_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $sent = false;

        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                /** @var User|null $user */
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

                // Vždy ukážeme stejnou zprávu — proti user enumeration
                if ($user !== null) {
                    $token  = bin2hex(random_bytes(32)); // 64 hex chars
                    $expiry = new \DateTimeImmutable('+1 hour');

                    $user->setResetToken($token);
                    $user->setResetTokenExpiry($expiry);
                    $this->em->flush();

                    $resetUrl = $this->generateUrl(
                        'auth_reset_password',
                        ['token' => $token],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    );

                    $html = $this->renderView('emails/password_reset.html.twig', [
                        'user'     => $user,
                        'resetUrl' => $resetUrl,
                        'expiry'   => $expiry,
                    ]);

                    $message = (new Email())
                        ->from(new Address($this->mailerFrom, $this->mailerFromName))
                        ->to($user->getEmail())
                        ->subject('Obnova hesla — ulozimto.cz')
                        ->html($html);

                    try {
                        $this->mailer->send($message);
                    } catch (\Throwable $e) {
                        // Logujeme tiše — uživateli vždy ukážeme "odesláno"
                    }
                }
            }

            $sent = true;
        }

        return $this->render('auth/forgot_password.html.twig', ['sent' => $sent]);
    }

    // ── Reset password ───────────────────────────────────────────────

    #[Route('/reset-password/{token}', name: 'auth_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token,
        Request $request,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        // Neplatný nebo expirovaný token
        if ($user === null || !$user->isResetTokenValid()) {
            $this->addFlash('error', 'Odkaz pro obnovu hesla je neplatný nebo vypršel. Požádej o nový.');
            return $this->redirectToRoute('auth_forgot_password');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            $confirm  = $request->request->get('password_confirm', '');

            if (strlen($password) < 8) {
                $error = 'Heslo musí mít alespoň 8 znaků.';
            } elseif ($password !== $confirm) {
                $error = 'Hesla se neshodují.';
            } else {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->clearResetToken();
                $this->em->flush();

                $this->addFlash('success', 'Heslo bylo úspěšně změněno. Přihlaste se.');
                return $this->redirectToRoute('auth_login');
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'token' => $token,
            'error' => $error,
        ]);
    }
}
