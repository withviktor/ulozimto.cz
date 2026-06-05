<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegalController extends AbstractController
{
    #[Route('/terms', name: 'legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }

    #[Route('/privacy', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [
            ['loc' => $this->generateUrl('homepage',      [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '1.0'],
            ['loc' => $this->generateUrl('upload_index',  [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '0.9'],
            ['loc' => $this->generateUrl('auth_register', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '0.6'],
            ['loc' => $this->generateUrl('legal_terms',   [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '0.3'],
            ['loc' => $this->generateUrl('legal_privacy', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), 'priority' => '0.3'],
        ];

        $xml = $this->renderView('legal/sitemap.xml.twig', ['urls' => $urls]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
