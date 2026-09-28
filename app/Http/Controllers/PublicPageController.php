<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClubSetting;
use App\PublicSite\PublicPageTemplates;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageController extends Controller
{
    public function imprint(): Response
    {
        return $this->page('Impressum', 'imprint_text');
    }

    public function privacy(): Response
    {
        return $this->page('Datenschutzerklärung', 'privacy_text');
    }

    private function page(string $title, string $key): Response
    {
        $data = ClubSetting::current()->data;

        return Inertia::render('Legal', [
            'title' => $title,
            'text' => PublicPageTemplates::render($key, $data),
        ]);
    }
}
