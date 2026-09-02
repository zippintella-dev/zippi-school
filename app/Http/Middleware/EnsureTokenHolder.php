<?php

namespace App\Http\Middleware;

use App\Models\Guardian;
use App\Models\SchoolStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins an API route group to ONE kind of token holder.
 *
 * ⚠ WHY THIS EXISTS, AND WHY IT IS NOT OPTIONAL.
 *
 * `auth:sanctum` authenticates whoever a bearer token belongs to. It does not
 * care WHICH model that is. While the only tokens in the system were minted for
 * Guardians, `/api/parent` was safe by accident — routes/api.php said so in a
 * comment: "a staff token could never satisfy these routes because no staff
 * token is ever minted."
 *
 * Zippi Fleet mints staff tokens. That sentence stopped being true the moment
 * `SchoolStaff` gained `HasApiTokens`, and without this middleware:
 *
 *   · an attendant's token would authenticate against `/api/parent/*`, where
 *     `$request->user()->children()` would explode or, worse, resolve — handing
 *     crew the live GPS of children they have no relationship with; and
 *   · a parent's token would authenticate against `/api/fleet/*`, where the
 *     writes mark children boarded and released.
 *
 * So every group declares what it accepts, and anything else is a flat 403.
 * The check is on the CLASS of the tokenable, not on a claim inside the token —
 * there is nothing here for a client to assert.
 *
 * Usage:  ->middleware(['auth:sanctum', 'token.holder:guardian'])
 *         ->middleware(['auth:sanctum', 'token.holder:staff'])
 */
class EnsureTokenHolder
{
    private const MODELS = [
        'guardian' => Guardian::class,
        'staff' => SchoolStaff::class,
    ];

    public function handle(Request $request, Closure $next, string $holder): Response
    {
        $expected = self::MODELS[$holder]
            ?? throw new \InvalidArgumentException("Unknown token holder [$holder].");

        $user = $request->user();

        if (! $user instanceof $expected) {
            // ⚠ The message says nothing about what the token IS. A parent
            // probing the fleet API learns only that they cannot use it.
            return response()->json([
                'status' => false,
                'message' => 'This sign-in cannot be used here. '
                           . 'Please use the app this account belongs to.',
            ], 403);
        }

        return $next($request);
    }
}
