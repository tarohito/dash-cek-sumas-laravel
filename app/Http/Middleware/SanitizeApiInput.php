<?php

namespace App\Http\Middleware;

use App\Services\SecurityHardeningService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeApiInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            $input = $request->all();
            if (!empty($input)) {
                $sanitized = SecurityHardeningService::sanitizeRecursive($input, 0, 12);
                if (!$sanitized['ok']) {
                    return response()->json([
                        'error' => 'Struktur payload terlalu dalam atau tidak valid.',
                    ], 400);
                }
                if (is_array($sanitized['value'])) {
                    $request->replace($sanitized['value']);
                }
            }
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
