<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAsteriskIngestToken
{
    /**
     * Accept only a Sanctum personal access token that can ingest Asterisk events.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $abilities = $token->abilities ?? [];
        $required = (string) config('smartpbx.asterisk_ingest_ability', 'asterisk:ingest');

        if (in_array('*', $abilities, true) || ! in_array($required, $abilities, true)) {
            throw new MissingAbilityException($required);
        }

        return $next($request);
    }
}
