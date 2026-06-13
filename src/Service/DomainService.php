<?php

namespace App\Service;

/**
 * Služba pro správu domén a URL generování
 * Podporuje hlavní doménu a volitelně zkrácenou doménu
 */
class DomainService
{
    public function __construct(
        private readonly string $mainDomain,
        private readonly ?string $shortDomain = null,
        private readonly bool $redirectShortToMain = false,
    ) {}

    /**
     * Vrátí hlavní doménu (ulozimto.cz)
     */
    public function getMainDomain(): string
    {
        return $this->mainDomain;
    }

    /**
     * Vrátí zkrácenou doménu (pokud je nastavena)
     */
    public function getShortDomain(): ?string
    {
        return $this->shortDomain;
    }

    /**
     * Kontrola, zda je zkrácená doména nastavena
     */
    public function hasShortDomain(): bool
    {
        return $this->shortDomain !== null && $this->shortDomain !== '';
    }

    /**
     * Kontrola, zda má krátká doména přesměrovávat na hlavní
     */
    public function shouldRedirectShortToMain(): bool
    {
        return $this->redirectShortToMain;
    }

    /**
     * Vygeneruje URL pro krátký link (preferuje krátkou doménu)
     */
    public function getShortLinkUrl(string $slug): string
    {
        $domain = $this->hasShortDomain() ? $this->shortDomain : $this->mainDomain;
        return 'https://' . $domain . '/l/' . $slug;
    }

    /**
     * Vygeneruje URL pro sdílení (hlavní doména + token/alias)
     */
    public function getShareUrl(string $token): string
    {
        return 'https://' . $this->mainDomain . '/s/' . $token;
    }

    /**
     * Vygeneruje úplnou URL pro krátký link s možností přesměrování
     */
    public function getRedirectUrl(string $slug, string $mainShareToken): string
    {
        if ($this->shouldRedirectShortToMain()) {
            return $this->getShareUrl($mainShareToken);
        }

        return $this->getShortLinkUrl($slug);
    }
}
