<?php

namespace App\Controller;

use Resend\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class NotifyController extends AbstractController
{
    public function __construct(
        private readonly string $resendApiKey,
        private readonly string $resendAudienceId,
    ) {}

    #[Route('/api/plus-notify', name: 'plus_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true);
        $email = trim($data['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Neplatná e-mailová adresa.'], 422);
        }

        try {
            $resend = \Resend::client($this->resendApiKey);

            $resend->contacts->create($this->resendAudienceId, [
                'email'        => $email,
                'unsubscribed' => false,
            ]);
        } catch (\Throwable $e) {
            // Duplicitní email (already exists) — považujeme za úspěch
            if (!str_contains($e->getMessage(), 'already exists')) {
                return $this->json(['error' => 'Nepodařilo se přidat email. Zkus to prosím znovu.'], 500);
            }
        }

        return $this->json(['ok' => true]);
    }
}
