<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles  Allowed role names (e.g. 'admin', 'agent').
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        if (empty($roles)) {
            return $next($request);
        }

        $userRoleValue = $user->role->value;

        if (! in_array($userRoleValue, $roles, true)) {
            throw new AuthorizationException('This action is unauthorized for your role.');
        }

        return $next($request);
    }
}
