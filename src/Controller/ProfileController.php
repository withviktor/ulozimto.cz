<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\AvatarService;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly AvatarService               $avatarService,
        private readonly MinioService                $minio,
        private readonly FileRepository              $fileRepo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
        private readonly string                      $mailerFrom,
        private readonly string                      $mailerFromName,
    ) {}

    // ── Hlavní stránka profilu ──────────────────────────────────────

    #[Route('', name: 'profile', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $storageUsed  = $this->fileRepo->getTotalStorageForUser((string) $user->getId());
        $storageLimit = $user->getStorageLimit();
        $fileCount    = count($user->getFiles());
        $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;

        return $this->render('profile/index.html.twig', [
            'user'            => $user,
            'avatarUrl'       => $this->avatarService->getAvatarUrl($user, 160),
            'storageUsed'     => $storageUsed,
            'storageLimit'    => $storageLimit,
            'storagePercent'  => $storagePercent,
            'fileCount'       => $fileCount,
        ]);
    }

    // ── Změna jména ─────────────────────────────────────────────────

    #[Route('/name', name: 'profile_name', methods: ['POST'])]
    public function updateName(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $name = trim($request->request->get('name', ''));

        if (strlen($name) > 100) {
            $this->addFlash('error', 'Jméno může mít maximálně 100 znaků.');
            return $this->redirectToRoute('profile');
        }

        $user->setName($name ?: null);
        $this->em->flush();

        $this->addFlash('success', 'Jméno bylo aktualizováno.');
        return $this->redirectToRoute('profile');
    }

    // ── Avatar upload ───────────────────────────────────────────────

    #[Route('/avatar', name: 'profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $file = $request->files->get('avatar');

        if (!$file) {
            $this->addFlash('error', 'Žádný soubor nebyl nahrán.');
            return $this->redirectToRoute('profile');
        }

        $error = $this->avatarService->upload($user, $file);
        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('profile');
        }

        $this->em->flush();
        $this->addFlash('success', 'Profilový obrázek byl aktualizován.');
        return $this->redirectToRoute('profile');
    }

    // ── Avatar smazání ──────────────────────────────────────────────

    #[Route('/avatar/delete', name: 'profile_avatar_delete', methods: ['POST'])]
    public function deleteAvatar(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->avatarService->deleteAvatar($user);
        $this->em->flush();

        $this->addFlash('success', 'Profilový obrázek byl odstraněn.');
        return $this->redirectToRoute('profile');
    }

    // ── Avatar proxy stream (z MinIO) ────────────────────────────────

    #[Route('/avatar/{id}', name: 'profile_avatar_stream', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function streamAvatar(string $id): Response
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user || !$user->hasCustomAvatar()) {
            return $this->redirect($this->avatarService->gravatarUrl($user?->getEmail() ?? '', 160));
        }

        $stream = $this->minio->getObjectStream($user->getAvatarKey());

        // Zjistit MIME typ z přípony klíče
        $ext  = strtolower(pathinfo($user->getAvatarKey(), PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };

        $response = new StreamedResponse(function () use ($stream) {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'public, max-age=86400'); // 24h cache
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    // ── Změna emailu (žádost) ────────────────────────────────────────

    #[Route('/email', name: 'profile_email', methods: ['POST'])]
    public function requestEmailChange(Request $request): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $newEmail    = trim($request->request->get('new_email', ''));
        $password    = $request->request->get('current_password', '');

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Neplatná e-mailová adresa.');
            return $this->redirectToRoute('profile');
        }

        if ($newEmail === $user->getEmail()) {
            $this->addFlash('error', 'Nový email je stejný jako stávající.');
            return $this->redirectToRoute('profile');
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'Nesprávné aktuální heslo.');
            return $this->redirectToRoute('profile');
        }

        // Zkontrolovat unikátnost
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        if ($existing) {
            $this->addFlash('error', 'Tento email je již registrován.');
            return $this->redirectToRoute('profile');
        }

        // Vygenerovat token
        $token  = bin2hex(random_bytes(32));
        $expiry = new \DateTimeImmutable('+24 hours');

        $user->setPendingEmail($newEmail);
        $user->setEmailChangeToken($token);
        $user->setEmailChangeTokenExpiry($expiry);
        $this->em->flush();

        // Odeslat potvrzovací email
        $confirmUrl = $this->generateUrl(
            'profile_confirm_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new Email())
            ->from("{$this->mailerFromName} <{$this->mailerFrom}>")
            ->to($newEmail)
            ->subject('Potvrďte změnu emailu — ulozimto.cz')
            ->html($this->renderView('emails/email_change.html.twig', [
                'user'       => $user,
                'confirmUrl' => $confirmUrl,
                'newEmail'   => $newEmail,
                'expiry'     => $expiry,
            ]));

        $this->mailer->send($email);

        $this->addFlash('success', "Potvrzovací email byl odeslán na {$newEmail}. Platnost odkazu je 24 hodin.");
        return $this->redirectToRoute('profile');
    }

    // ── Potvrzení změny emailu ────────────────────────────────────────

    #[Route('/confirm-email/{token}', name: 'profile_confirm_email', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function confirmEmail(string $token): Response
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['emailChangeToken' => $token]);

        if (!$user || !$user->isEmailChangeTokenValid()) {
            $this->addFlash('error', 'Odkaz pro změnu emailu je neplatný nebo vypršel.');
            return $this->redirectToRoute('auth_login');
        }

        $user->setEmail($user->getPendingEmail());
        $user->clearEmailChangeRequest();
        $this->em->flush();

        $this->addFlash('success', 'Email byl úspěšně změněn. Přihlaste se s novým emailem.');
        return $this->redirectToRoute('auth_login');
    }

    // ── Změna hesla ──────────────────────────────────────────────────

    #[Route('/password', name: 'profile_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $current = $request->request->get('current_password', '');
        $new     = $request->request->get('new_password', '');
        $confirm = $request->request->get('new_password_confirm', '');

        if (!$this->hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Nesprávné aktuální heslo.');
            return $this->redirectToRoute('profile');
        }

        if (strlen($new) < 8) {
            $this->addFlash('error', 'Nové heslo musí mít alespoň 8 znaků.');
            return $this->redirectToRoute('profile');
        }

        if ($new !== $confirm) {
            $this->addFlash('error', 'Hesla se neshodují.');
            return $this->redirectToRoute('profile');
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();

        $this->addFlash('success', 'Heslo bylo úspěšně změněno.');
        return $this->redirectToRoute('profile');
    }
}
