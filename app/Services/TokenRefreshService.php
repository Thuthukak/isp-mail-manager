<?php
namespace App\Services;

use App\Models\OAuthToken;
use App\Jobs\RefreshTokenJob;
use Illuminate\Support\Facades\Log;

class TokenRefreshService
{
    private MicrosoftAuthService $authService;

    public function __construct(MicrosoftAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Refresh all expiring tokens
     */
    public function refreshExpiringTokens(): void
    {
        $expiringTokens = OAuthToken::forProvider('microsoft')
            ->where('expires_at', '<=', now()->addMinutes(10))
            ->whereNotNull('refresh_token')
            ->get();

        foreach ($expiringTokens as $token) {
            RefreshTokenJob::dispatch($token->id);
        }

        Log::info('Queued refresh jobs for ' . $expiringTokens->count() . ' expiring tokens');
    }

    /**
     * Refresh a specific token
     */
    public function refreshToken(OAuthToken $token): bool
    {
        if (!$token->refresh_token) {
            Log::warning('No refresh token available', ['token_id' => $token->id]);
            return false;
        }

        try {
            $refreshedData = $this->authService->refreshAccessToken($token->refresh_token);
            $this->authService->storeToken($refreshedData, $token->user);
            
            Log::info('Successfully refreshed token', ['token_id' => $token->id]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to refresh token', [
                'token_id' => $token->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check token health across all stored tokens
     */
    public function checkTokenHealth(): array
    {
        $tokens = OAuthToken::forProvider('microsoft')->get();
        
        $stats = [
            'total' => $tokens->count(),
            'valid' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
            'no_refresh_token' => 0,
        ];

        foreach ($tokens as $token) {
            if ($token->isExpired()) {
                $stats['expired']++;
            } elseif ($token->isExpiringSoon(30)) {
                $stats['expiring_soon']++;
            } else {
                $stats['valid']++;
            }

            if (!$token->refresh_token) {
                $stats['no_refresh_token']++;
            }
        }

        return $stats;
    }
}