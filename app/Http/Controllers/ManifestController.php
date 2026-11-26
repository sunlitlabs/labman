<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** The web app manifest that makes LabMan installable (named after the site title from Settings). */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $title = Settings::title();

        return response()->json([
            'name' => $title,
            'short_name' => mb_substr($title, 0, 12),
            'description' => 'Home-lab status and links at a glance.',
            'id' => '/',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0b1f55',
            'theme_color' => '#2557d6',
            'icons' => [
                ['src' => asset('app-icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('app-icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('app-icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'shortcuts' => [
                ['name' => 'Services', 'url' => '/services'],
                ['name' => 'Servers', 'url' => '/servers'],
                ['name' => 'Links', 'url' => '/links'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }
}
