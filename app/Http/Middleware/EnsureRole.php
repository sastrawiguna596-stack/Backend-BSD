<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Pastikan user yang sedang login memiliki salah satu dari role yang diizinkan.
     *
     * Contoh penggunaan di route:
     *   Route::middleware('role:owner,admin')->group(...)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
