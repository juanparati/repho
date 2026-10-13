<?php

namespace App\Http\Middleware;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use App\Models\ProxySetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProxy
{
    public function handle(Request $request, Closure $next, string $ecosystem = 'composer'): Response
    {
        $settings = ProxySetting::instance();

        if (! $settings->isEnabled(Ecosystem::from($ecosystem))) {
            abort(404);
        }

        return match ($settings->auth_type) {
            RepositoryAuthType::None => $next($request),
            RepositoryAuthType::Basic => $this->handleBasicAuth($request, $settings, $next),
            RepositoryAuthType::Token => $this->handleTokenAuth($request, $settings, $next),
        };
    }

    private function handleBasicAuth(Request $request, ProxySetting $settings, Closure $next): Response
    {
        $username = $request->getUser();
        $password = $request->getPassword();

        if ($username !== null && $password !== null
            && hash_equals((string) $settings->auth_username, $username)
            && hash_equals((string) $settings->auth_password, $password)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid credentials.'], 401, [
            'WWW-Authenticate' => 'Basic realm="Repho Proxy"',
        ]);
    }

    private function handleTokenAuth(Request $request, ProxySetting $settings, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->query('token');

        if ($token && hash_equals((string) $settings->auth_token, $token)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid token.'], 401);
    }
}
