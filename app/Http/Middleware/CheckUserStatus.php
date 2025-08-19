<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();
            
            // Check if user is active
            if (!$user->is_active) {
                auth()->logout();
                return redirect()->route('filament.admin.auth.login')
                    ->withErrors(['email' => 'Your account has been deactivated.']);
            }

            // Check if user is locked
            if ($user->isLocked()) {
                auth()->logout();
                return redirect()->route('filament.admin.auth.login')
                    ->withErrors(['email' => 'Your account is currently locked.']);
            }
        }

        return $next($request);
    }
}

// App\Http\Kernel.php - Add this to your middleware groups
// 'web' => [
//     // ... other middleware
//     \App\Http\Middleware\CheckUserStatus::class,
// ],