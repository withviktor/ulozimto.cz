<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\ShortLink;
use App\Repository\FileRepository;
use App\Repository\ShortLinkRepository;
use App\Service\DomainService;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use App\Service\QRCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly FileRepository         $files,
        private readonly ShortLinkRepository    $shortLinks,
        private readonly MinioService           $minio,
        private readonly FileExpirationService  $expiration,
        private readonly DomainService          $domainService,
        private readonly QRCodeService          $qrCodeService,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Hlavní stránka ───────────────────────────────────────────────

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $files = $this->files->findByUser((string) $user->getId());

        $storageUsed    = $this->files->getTotalStorageForUser((string) $user->getId());
        $storageLimit   = $user->getStorageLimit();
        $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;

        // Serializace souborů pro Alpine.js
        $filesData = array_values(array_map(function (File $f) {
            $alias = $f->getCustomAlias();
            $token = $f->getShareToken();
            return [
                'token'             => $token,
                'name'              => $f->getOriginalName(),
                'mime'              => $f->getMimeType() ?? 'application/octet-stream',
                'sizeFormatted'     => $f->getFormattedSize(),
                'sizeBytes'         => $f->getSizeBytes(),
                'downloads'         => $f->getDownloadCount(),
                'expiresLabel'      => $this->expiration->getRemainingLabel($f->getExpiresAt()),
                'expiresTs'         => $f->getExpiresAt()->getTimestamp(),
                'expiresAt'         => $f->getExpiresAt()->format('Y-m-d'),
                'expired'           => $f->isExpired(),
                'scanStatus'        => $f->getScanStatus(),
                'passwordProtected' => $f->isPasswordProtected(),
                'customAlias'       => $alias,
                'createdTs'         => $f->getCreatedAt()->getTimestamp(),
                'shareUrl'          => $this->generateUrl(
                    'share_show',
                    ['token' => $alias ?? $token],
                    UrlGeneratorInterface::ABSOLUTE_PATH
                ),
            ];
        }, $files));

        return $this->render('dashboard/index.html.twig', [
            'filesData'         => $filesData,
            'expirationOptions' => $this->expiration->getOptions($user),
            'isPlus'            => $user->isPlus(),
            'storageUsed'       => $storageUsed,
            'storageLimit'      => $storageLimit,
            'storagePercent'    => $storagePercent,
            'fileCount'         => count($files),
        ]);
    }

    // ── Smazat jeden soubor ──────────────────────────────────────────

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

    // ── Hromadné smazání ─────────────────────────────────────────────

    #[Route('/bulk-delete', name: 'dashboard_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true);
        $tokens = $data['tokens'] ?? [];

        if (empty($tokens) || !is_array($tokens)) {
            return $this->json(['error' => 'Žádné tokeny'], 400);
        }

        $userId  = (string) $this->getUser()->getId();
        $deleted = [];

        foreach ($tokens as $token) {
            $file = $this->files->findByShareToken((string) $token);
            if (!$file || $file->getUser()?->getId()->toRfc4122() !== $userId) {
                continue;
            }
            $this->minio->delete($file->getMinioKey());
            $this->em->remove($file);
            $deleted[] = $token;
        }

        $this->em->flush();

        return $this->json(['deleted' => $deleted]);
    }

    // ── Aktualizace souboru (jméno, platnost, heslo, alias) ──────────

    #[Route('/update/{token}', name: 'dashboard_update', methods: ['POST'])]
    public function update(string $token, Request $request): JsonResponse
    {
        $file = $this->files->findByShareToken($token);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!$file || $file->getUser()?->getId()->toRfc4122() !== (string) $user->getId()) {
            return $this->json(['error' => 'Nenalezeno'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Název
        if (isset($data['name']) && trim($data['name']) !== '') {
            $file->setOriginalName(mb_substr(trim($data['name']), 0, 255));
        }

        // Platnost — přidáme hodiny od teď
        if (isset($data['expireHours'])) {
            $hours = (int) $data['expireHours'];
            if ($hours > 0) {
                $file->setExpiresAt($this->expiration->getExpirationDate($hours, $user));
            }
        }

        // Heslo (null = odstranit, string = nastavit nové)
        if (array_key_exists('password', $data)) {
            $pw = $data['password'];
            if ($pw === null || $pw === '') {
                $file->setPasswordHash(null);
            } else {
                $file->setPasswordHash(password_hash((string) $pw, PASSWORD_BCRYPT));
            }
        }

        // Vlastní alias — jen PLUS
        if (array_key_exists('customAlias', $data) && $user->isPlus()) {
            $alias = trim((string) ($data['customAlias'] ?? ''));
            $file->setCustomAlias($alias !== '' ? $alias : null);
        }

        $this->em->flush();

        $alias = $file->getCustomAlias();
        return $this->json([
            'ok'               => true,
            'name'             => $file->getOriginalName(),
            'customAlias'      => $alias,
            'passwordProtected' => $file->isPasswordProtected(),
            'expiresLabel'     => $this->expiration->getRemainingLabel($file->getExpiresAt()),
            'expiresTs'        => $file->getExpiresAt()->getTimestamp(),
            'expiresAt'        => $file->getExpiresAt()->format('Y-m-d'),
            'expired'          => $file->isExpired(),
            'shareUrl'         => $this->generateUrl(
                'share_show',
                ['token' => $alias ?? $file->getShareToken()],
                UrlGeneratorInterface::ABSOLUTE_PATH
            ),
        ]);
    }

    // ── Short Links Management ───────────────────────────────────────────

    #[Route('/short-links', name: 'dashboard_short_links', methods: ['GET'])]
    public function shortLinksPage(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $total = $this->shortLinks->countByUser($user);
        $shortLinks = $this->shortLinks->findByUser($user);

        $stats = [
            'total' => $total,
            'totalClicks' => array_sum(array_map(fn(ShortLink $sl) => $sl->getAccessedCount(), $shortLinks)),
            'mostPopular' => $shortLinks ? max(array_map(fn(ShortLink $sl) => $sl->getAccessedCount(), $shortLinks)) : 0,
        ];

        return $this->render('dashboard/short-links.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/api/short-links', name: 'api_short_links_list', methods: ['GET'])]
    public function getShortLinksData(Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $search = (string) $request->query->get('search', '');
        $sortBy = (string) $request->query->get('sort', 'created_desc');
        $page = (int) $request->query->get('page', 1);
        $limit = 20;

        $shortLinks = $this->shortLinks->findByUserWithFileInfo($user, $search, $sortBy, $page, $limit);
        $total = $this->shortLinks->countByUser($user);

        $data = array_map(function (ShortLink $sl) {
            $file = $sl->getFile();
            $token = $file->getCustomAlias() ?? $file->getShareToken();
            $shortUrl = $this->domainService->getShortLinkUrl($sl->getSlug());

            return [
                'id' => $sl->getId()->toRfc4122(),
                'slug' => $sl->getSlug(),
                'filename' => $file->getOriginalName(),
                'fileSize' => $file->getFormattedSize(),
                'fileSizeBytes' => $file->getSizeBytes(),
                'clicks' => $sl->getAccessedCount(),
                'created' => $sl->getCreatedAt()->format('Y-m-d H:i'),
                'createdTs' => $sl->getCreatedAt()->getTimestamp(),
                'expires' => $file->getExpiresAt()->format('Y-m-d'),
                'expiresTs' => $file->getExpiresAt()->getTimestamp(),
                'expired' => $file->isExpired(),
                'shareUrl' => $this->generateUrl('share_show', ['token' => $token]),
                'shortUrl' => $shortUrl,
            ];
        }, $shortLinks);

        return $this->json([
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/api/short-links/{fileId}/create', name: 'api_short_links_create', methods: ['POST'])]
    public function createShortLink(string $fileId, Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $file = $this->files->find($fileId);
        if (!$file || $file->getUser()?->getId()->toRfc4122() !== (string) $user->getId()) {
            return $this->json(['error' => 'Soubor nenalezen'], 404);
        }

        // Zkontrolovat, zda už short link existuje
        $existing = $this->shortLinks->findOneBy(['file' => $file]);
        if ($existing) {
            // Vrátit existující
            $slug = $existing->getSlug();
        } else {
            // Vytvořit nový
            $slug = $this->shortLinks->generateUniqueSlug();
            $shortLink = new ShortLink();
            $shortLink->setFile($file);
            $shortLink->setSlug($slug);
            $this->em->persist($shortLink);
            $this->em->flush();
        }

        return $this->json([
            'slug' => $slug,
            'url' => $this->domainService->getShortLinkUrl($slug),
        ]);
    }

    #[Route('/api/short-links/{shortLinkId}/regenerate', name: 'api_short_links_regenerate', methods: ['POST'])]
    public function regenerateShortLink(string $shortLinkId, Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $shortLink = $this->shortLinks->find($shortLinkId);
        if (!$shortLink || !$this->shortLinks->isOwnedBy($shortLink, $user)) {
            return $this->json(['error' => 'Zkrácený link nenalezen'], 404);
        }

        $oldSlug = $shortLink->getSlug();
        $newSlug = $this->shortLinks->generateUniqueSlug();
        $shortLink->setSlug($newSlug);
        $this->em->flush();

        return $this->json([
            'oldSlug' => $oldSlug,
            'newSlug' => $newSlug,
            'url' => $this->domainService->getShortLinkUrl($newSlug),
        ]);
    }

    #[Route('/api/short-links/{shortLinkId}', name: 'api_short_links_delete', methods: ['DELETE'])]
    public function deleteShortLink(string $shortLinkId): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $shortLink = $this->shortLinks->find($shortLinkId);
        if (!$shortLink || !$this->shortLinks->isOwnedBy($shortLink, $user)) {
            return $this->json(['error' => 'Zkrácený link nenalezen'], 404);
        }

        $this->em->remove($shortLink);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/api/short-links/{shortLinkId}/qrcode', name: 'api_short_links_qrcode', methods: ['GET'])]
    public function generateQRCode(string $shortLinkId): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $shortLink = $this->shortLinks->find($shortLinkId);
        if (!$shortLink || !$this->shortLinks->isOwnedBy($shortLink, $user)) {
            throw $this->createNotFoundException('Zkrácený link nenalezen');
        }

        $url = $this->domainService->getShortLinkUrl($shortLink->getSlug());

        return $this->qrCodeService->generateQRCodeResponse($url);
    }

    #[Route('/api/short-links/bulk-delete', name: 'api_short_links_bulk_delete', methods: ['POST'])]
    public function bulkDeleteShortLinks(Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            return $this->json(['error' => 'Žádné IDs poskytnuty'], 400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $shortLink = $this->shortLinks->find($id);
            if ($shortLink && $this->shortLinks->isOwnedBy($shortLink, $user)) {
                $this->em->remove($shortLink);
                $deleted++;
            }
        }

        $this->em->flush();

        return $this->json([
            'deleted' => $deleted,
            'total' => count($ids),
        ]);
    }
}
