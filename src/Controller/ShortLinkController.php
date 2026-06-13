<?php

namespace App\Controller;

use App\Repository\ShortLinkRepository;
use App\Service\DomainService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ShortLinkController extends AbstractController
{
    public function __construct(
        private readonly ShortLinkRepository $shortLinks,
        private readonly EntityManagerInterface $em,
        private readonly DomainService $domainService,
    ) {}

    /** Přesměrování z krátké URL na sdílený soubor */
    #[Route('/l/{slug}', name: 'short_link_redirect', methods: ['GET'])]
    public function redirectShortLink(string $slug): Response
    {
        $shortLink = $this->shortLinks->findBySlug($slug);

        if (!$shortLink) {
            throw $this->createNotFoundException('Zkrácený link neexistuje.');
        }

        $file = $shortLink->getFile();

        // Kontrola vypršení souboru
        if ($file->isExpired()) {
            throw $this->createNotFoundException('Soubor vypršel.');
        }

        // Zvýšit počet přístupů
        $shortLink->incrementAccessedCount();
        $this->em->flush();

        // Přesměrovat na sdílený soubor
        $token = $file->getCustomAlias() ?? $file->getShareToken();

        // Pokud je nastaveno přesměrování na hlavní doménu, použít absolutní URL
        if ($this->domainService->shouldRedirectShortToMain()) {
            return $this->redirect($this->domainService->getShareUrl($token));
        }

        // Jinak přesměrovat na stejné doméně
        return $this->redirectToRoute('share_show', ['token' => $token]);
    }
}
