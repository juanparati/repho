<?php

namespace App\Http\Middleware;

use App\Enums\RepositoryAuthType;
use App\Models\Repository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRepository
{
    public function handle(Request $request, Closure $next): Response
    {
        $repository = $request->route('repository');

        if (! $repository instanceof Repository) {
            abort(404);
        }

        return match ($repository->auth_type) {
            RepositoryAuthType::None => $next($request),
            RepositoryAuthType::Basic => $this->handleBasicAuth($request, $repository, $next),
            RepositoryAuthType::Token => $this->handleTokenAuth($request, $repository, $next),
        };
    }

    private function handleBasicAuth(Request $request, Repository $repository, Closure $next): Response
    {
        $username = $request->getUser();
        $password = $request->getPassword();

        if ($username !== null && $password !== null
            && hash_equals((string) $repository->auth_username, $username)
            && hash_equals((string) $repository->auth_password, $password)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid credentials.'], 401, [
            'WWW-Authenticate' => 'Basic realm="Repho Repository"',
        ]);
    }

    private function handleTokenAuth(Request $request, Repository $repository, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->query('token');

        if ($token && hash_equals((string) $repository->auth_token, $token)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid token.'], 401);
    }
}
