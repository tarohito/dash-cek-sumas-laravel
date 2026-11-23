<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SessionAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SumasSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $csrfError = SessionAuthService::validateSameOriginAndCsrf($request, true);
        if ($csrfError !== null) {
            return response()->json([
                'success' => false,
                'error' => $csrfError,
            ], 403);
        }

        $user = SessionAuthService::resolveSessionUser($request);
        if (!$user) {
            return response()->json([
                'error' => 'Sesi login tidak valid atau telah berakhir. Silakan masuk terlebih dahulu.',
            ], 401);
        }

        $matrix = RbacService::getRbacMatrix();
        $scope = RbacService::getUserBrandScope($user, $matrix);
        $actorLabel = NotificationService::getActorLabel($user);

        $request->attributes->set('sumas_user', $user);
        $request->attributes->set('sumas_matrix', $matrix);
        $request->attributes->set('sumas_scope', $scope);
        $request->attributes->set('sumas_actor_label', $actorLabel);

        $response = $next($request);

        $rotated = $request->attributes->get('sumas_rotated_token');
        $activeCsrf = null;
        if (is_array($rotated) && !empty($rotated['token']) && !empty($rotated['minutes'])) {
            $isHttps = SessionAuthService::isHttpsRequest($request);
            $activeCsrf = SessionAuthService::deriveCsrfTokenFromRawSession((string) $rotated['token']);
            $response->headers->setCookie(
                cookie('sumas_session', (string) $rotated['token'], (int) $rotated['minutes'], '/', null, $isHttps, true, false, 'lax')
            );
            $response->headers->setCookie(
                cookie('sumas_csrf', $activeCsrf, (int) $rotated['minutes'], '/', null, $isHttps, false, false, 'lax')
            );
        } else {
            $activeCsrf = SessionAuthService::getSessionCsrfToken($request);
        }

        if ($activeCsrf !== null) {
            $response->headers->set('X-Sumas-CSRF', $activeCsrf);
        }

        return $response;
    }
}
