<?php

namespace App\Controller;

use App\Repository\FileRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly FileRepository         $files,
        private readonly MinioService           $minio,
        private readonly FileExpirationService  $expiration,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $files = $this->files->findByUser((string) $user->getId());

        $storageUsed    = $this->files->getTotalStorageForUser((string) $user->getId());
        $storageLimit   = $user->getStorageLimit();
        $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;

        return $this->render('dashboard/index.html.twig', [
            'files'          => $files,
            'expiration'     => $this->expiration,
            'storageUsed'    => $storageUsed,
            'storageLimit'   => $storageLimit,
            'storagePercent' => $storagePercent,
            'isPlus'         => $user->isPlus(),
        ]);
    }

    #[Route('/delete/{token}', name: 'dashboard_delete', methods: ['POST'])]
    public function delete(string $token): JsonResponse
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->getUser()?->getId()->toRfc4122() !== (string) $this->getUser()->getId()) {
            return $this->json(['error' => 'Nenalezeno'], 404);
        }

        $this->minio->delete($file->getMinioKey());
        $this->em->remove($file);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
