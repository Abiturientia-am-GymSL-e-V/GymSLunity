<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuration\ClubSettings;
use App\Demo\DemoAccounts;
use App\PublicSite\PublicPageTemplates;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicPageController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function imprint(): Response|SymfonyResponse
    {
        return $this->demoPage('imprint_url') ?? $this->page('Impressum', 'imprint_text');
    }

    public function privacy(): Response|SymfonyResponse
    {
        return $this->demoPage('privacy_url') ?? $this->page('Datenschutzerklärung', 'privacy_text');
    }

    /** The public demo shows the legal pages of its operator, not those of the sample club. */
    private function demoPage(string $key): ?SymfonyResponse
    {
        $url = config('demo.'.$key);

        return DemoAccounts::enabled() && is_string($url) && $url !== '' ? Inertia::location($url) : null;
    }

    private function page(string $title, string $key): Response
    {
        $data = $this->clubSettings->data();

        return Inertia::render('Legal', [
            'title' => $title,
            'text' => PublicPageTemplates::render($key, $data),
        ]);
    }
}
