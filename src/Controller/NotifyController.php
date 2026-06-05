<?php

namespace App\Controller;

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

    /**
     * Přidá email do Resend Audience (PLUS waitlist).
     *
     * Resend PHP SDK v1.3+ — správná forma volání:
     *   $resend->contacts->create(['audience_id' => ..., 'email' => ..., ...])
     *
     * Starý dvouargumentový tvar create($audienceId, $params) byl odstraněn
     * a způsoboval TypeError zachycený catch blokem → vždy 500.
     *
     * API klíč musí mít oprávnění "Audiences" (ne jen "Sending access").
     * Vytvoř dedikovaný klíč na https://resend.com/api-keys a nastav
     * RESEND_API_KEY v .env.local.
     */
    #[Route('/api/plus-notify', name: 'plus_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true);
        $email = trim($data['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Neplatná e-mailová adresa.'], 422);
        }

        if ($this->resendApiKey === 'changeme' || $this->resendAudienceId === 'changeme') {
            // Dev fallback: API klíče nejsou nakonfigurované
            return $this->json(['ok' => true, 'dev' => true]);
        }

        try {
            $resend = \Resend::client($this->resendApiKey);

            $resend->contacts->create([
                'audience_id'  => $this->resendAudienceId,
                'email'        => $email,
                'unsubscribed' => false,
            ]);
        } catch (\Resend\Exceptions\ErrorException $e) {
            $msg = $e->getMessage();

            // Duplicitní email — považujeme za úspěch
            if (str_contains($msg, 'already exists') || str_contains($msg, 'Contact already')) {
                return $this->json(['ok' => true]);
            }

            // API klíč nemá oprávnění Audiences
            if (str_contains($msg, 'restricted') || str_contains($msg, 'permission') || str_contains($msg, 'not allowed')) {
                return $this->json([
                    'error' => 'Chyba konfigurace — API klíč nemá oprávnění pro správu kontaktů.',
                ], 500);
            }

            return $this->json(['error' => 'Nepodařilo se přidat email. Zkus to prosím znovu.'], 500);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Nepodařilo se přidat email. Zkus to prosím znovu.'], 500);
        }

        return $this->json(['ok' => true]);
    }
}
