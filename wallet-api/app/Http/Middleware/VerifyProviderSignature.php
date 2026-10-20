<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every call from a game studio is signed: HMAC-SHA256 of "timestamp.body" with a shared secret.
 *
 * - no / wrong signature  → 401 (anyone can find the URL; only the provider knows the secret)
 * - timestamp too old     → 401 (stops "replay": re-sending a captured valid request later)
 * - hash_equals()         → constant-time comparison, so the response time leaks nothing
 */
class VerifyProviderSignature
{
    private const MAX_AGE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (! $timestamp || ! $signature) {
            abort(401, 'Missing signature');
        }

        if (abs(now()->timestamp - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            abort(401, 'Stale request');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), config('services.game_providers.secret'));

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
