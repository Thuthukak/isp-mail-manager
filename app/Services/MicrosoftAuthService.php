<?php

namespace App\Services;

use App\Models\OAuthToken;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MicrosoftAuthService
{
    private Client $client;
    private array $config;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false, // Set to true in production
        ]);
        
        $this->config = Config::get('onedrive');
    }

    /**
     * Generate authorization URL for OAuth flow
     */
    public function getAuthorizationUrl(): string
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->config['scopes'],
            'response_mode' => 'query',
            'state' => bin2hex(random_bytes(16)) // CSRF protection
        ];

        return $this->config['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function getAccessTokenFromCode(string $code): array
    {
        $data = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['redirect_uri']
        ];

        try {
            $response = $this->client->post($this->config['token_url'], [
                'form_params' => $data,
                'headers' => ['Accept' => 'application/json']
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData['access_token'])) {
                throw new \Exception('No access token in response');
            }

            return $tokenData;
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Token exchange failed', ['error' => $errorBody]);
            throw new \Exception('Failed to exchange code for token: ' . $e->getMessage());
        }
    }

    /**
     * Store OAuth token for a user
     */
    public function storeToken(array $tokenData, User $user = null): OAuthToken
    {
        if (!$user) {
            $user = auth()->user();
        }

        $expiresAt = isset($tokenData['expires_in']) 
            ? Carbon::now()->addSeconds($tokenData['expires_in'])
            : null;

        return OAuthToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'microsoft'
            ],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_at' => $expiresAt,
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
                'scope' => $tokenData['scope'] ?? null
            ]
        );
    }

    /**
     * Get valid access token for user (refresh if needed)
     */
    public function getValidAccessToken(User $user = null): ?string
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            return null;
        }

        $token = OAuthToken::where('user_id', $user->id)
            ->where('provider', 'microsoft')
            ->first();

        if (!$token) {
            return null;
        }

        // If token is not expired, return it
        if (!$token->isExpired()) {
            return $token->access_token;
        }

        // Try to refresh the token
        if ($token->refresh_token) {
            try {
                $newTokenData = $this->refreshAccessToken($token->refresh_token);
                $this->storeToken($newTokenData, $user);
                return $newTokenData['access_token'];
            } catch (\Exception $e) {
                Log::error('Token refresh failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * Refresh access token using refresh token
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $data = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ];

        try {
            $response = $this->client->post($this->config['token_url'], [
                'form_params' => $data,
                'headers' => ['Accept' => 'application/json']
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData['access_token'])) {
                throw new \Exception('No access token in refresh response');
            }

            return $tokenData;
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Token refresh failed', ['error' => $errorBody]);
            throw new \Exception('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Get user information from Microsoft Graph
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->client->get($this->config['api_url'] . '/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json'
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Failed to get user info', ['error' => $errorBody]);
            throw new \Exception('Failed to get user information: ' . $e->getMessage());
        }
    }

    /**
     * Revoke token for user
     */
    public function revokeToken(User $user = null): bool
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            return false;
        }

        $deleted = OAuthToken::where('user_id', $user->id)
            ->where('provider', 'microsoft')
            ->delete();

        return $deleted > 0;
    }

    /**
     * Check if user has valid authentication
     */
    public function isAuthenticated(User $user = null): bool
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            return false;
        }

        $token = OAuthToken::where('user_id', $user->id)
            ->where('provider', 'microsoft')
            ->first();

        if (!$token) {
            return false;
        }

        // Check if token is valid (not expired or has refresh token)
        return !$token->isExpired() || !empty($token->refresh_token);
    }

    /**
     * Get token information for user
     */
    public function getTokenInfo(User $user = null): ?array
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            return null;
        }

        $token = OAuthToken::where('user_id', $user->id)
            ->where('provider', 'microsoft')
            ->first();

        if (!$token) {
            return null;
        }

        return [
            'expires_at' => $token->expires_at,
            'is_expired' => $token->isExpired(),
            'expires_soon' => $token->isExpiringSoon(3600), // 1 hour
            'has_refresh_token' => !empty($token->refresh_token),
            'scope' => $token->scope,
            'token_type' => $token->token_type
        ];
    }
}