<?php 
namespace App\Jobs;

use App\Models\OAuthToken;
use App\Services\TokenRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // 1 minute backoff

    public function __construct(
        private int $tokenId
    ) {}

    public function handle(TokenRefreshService $tokenRefreshService): void
    {
        $token = OAuthToken::find($this->tokenId);
        
        if (!$token) {
            Log::warning('Token not found for refresh job', ['token_id' => $this->tokenId]);
            return;
        }

        $success = $tokenRefreshService->refreshToken($token);
        
        if (!$success) {
            Log::error('Token refresh job failed', ['token_id' => $this->tokenId]);
            $this->fail('Failed to refresh token');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RefreshTokenJob failed permanently', [
            'token_id' => $this->tokenId,
            'error' => $exception->getMessage()
        ]);
    }
}