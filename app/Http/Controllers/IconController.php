<?php

namespace App\Http\Controllers;

use App\Icons\Icons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IconController extends Controller
{
    /** A cached icon, same origin. Icons are logos, not data, so this needs no sign-in (the pages that use them do). */
    public function show(string $slug): Response
    {
        $image = Icons::image($slug);

        abort_if($image === null, 404);

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'public, max-age=86400',
            // An SVG opened directly must not run anything or load anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /** Names for the icon field's suggestion list (admin only). */
    public function search(Request $request): JsonResponse
    {
        Icons::refresh();

        return response()->json(['icons' => Icons::suggest((string) $request->query('q', ''))]);
    }
}
