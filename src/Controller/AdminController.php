<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository         $users,
        private readonly FileRepository         $files,
        private readonly MinioService           $minio,
        private readonly FileExpirationService  $expiration,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Přehled ──────────────────────────────────────────────────────

    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig', [
            'stats' => [
                'users'      => $this->users->countAll(),
                'files'      => $this->files->countAll(),
                'storage'    => $this->files->getTotalStorageAll(),
                'infected'   => $this->files->countByStatus(File::SCAN_INFECTED),
                'pending'    => $this->files->countByStatus(File::SCAN_PENDING),
            ],
        ]);
    }

    // ── Uživatelé ────────────────────────────────────────────────────

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(Request $request): Response
    {
        $page   = max(1, (int) $request->query->get('page', 1));
        $result = $this->users->findPaginated($page, 30);

        return $this->render('admin/users.html.twig', [
            'users'   => $result['items'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => (int) ceil($result['total'] / 30),
        ]);
    }

    #[Route('/users/{id}', name: 'admin_user_detail', methods: ['GET'])]
    public function userDetail(string $id): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Uživatel nenalezen.');
        }

        $storageUsed = $this->files->getTotalStorageForUser($id);

        return $this->render('admin/user_detail.html.twig', [
            'user'        => $user,
            'storageUsed' => $storageUsed,
            'expiration'  => $this->expiration,
        ]);
    }

    // ── Změna plánu uživatele ────────────────────────────────────────

    #[Route('/users/{id}/set-plan', name: 'admin_set_plan', methods: ['POST'])]
    public function setPlan(string $id, Request $request): JsonResponse
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['error' => 'Nenalezeno'], 404);
        }

        $plan = $request->request->get('plan', User::PLAN_FREE);
        if (!in_array($plan, [User::PLAN_FREE, User::PLAN_PLUS], true)) {
            return $this->json(['error' => 'Neplatný plán'], 400);
        }

        $user->setPlan($plan);
        $this->em->flush();

        return $this->json(['ok' => true, 'plan' => $plan]);
    }

    // ── Smazání uživatele (admin) ────────────────────────────────────

    #[Route('/users/{id}/delete', name: 'admin_delete_user', methods: ['POST'])]
    public function deleteUser(string $id): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        // Nesmažeme sami sebe
        if ($user->getId()->toRfc4122() === (string) $this->getUser()->getId()) {
            $this->addFlash('error', 'Nemůžeš smazat svůj vlastní účet z administrace.');
            return $this->redirectToRoute('admin_users');
        }

        // Smazat soubory z MinIO
        foreach ($user->getFiles() as $file) {
            try {
                $this->minio->delete($file->getMinioKey());
            } catch (\Throwable) {}
        }

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Uživatel '{$user->getEmail()}' byl smazán.");
        return $this->redirectToRoute('admin_users');
    }

    // ── Soubory ──────────────────────────────────────────────────────

    #[Route('/files', name: 'admin_files', methods: ['GET'])]
    public function fileList(Request $request): Response
    {
        $page   = max(1, (int) $request->query->get('page', 1));
        $status = $request->query->get('status') ?: null;
        $result = $this->files->findPaginatedAll($page, 40, $status);

        return $this->render('admin/files.html.twig', [
            'files'      => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'pages'      => (int) ceil($result['total'] / 40),
            'status'     => $status,
            'expiration' => $this->expiration,
        ]);
    }

    // ── Smazání souboru (admin) ──────────────────────────────────────

    #[Route('/files/{token}/delete', name: 'admin_delete_file', methods: ['POST'])]
    public function deleteFile(string $token): JsonResponse
    {
        $file = $this->files->findByShareToken($token);
        if (!$file) {
            return $this->json(['error' => 'Nenalezeno'], 404);
        }

        try {
            $this->minio->delete($file->getMinioKey());
        } catch (\Throwable) {}

        $this->em->remove($file);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
