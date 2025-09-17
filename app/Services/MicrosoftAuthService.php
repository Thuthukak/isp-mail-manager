<?php

namespace App\Services;

use App\Models\OAuthToken;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    public function getAuthorizationUrl(string $state = null): string
    {
        $state = $state ?: Str::random(40);
        
        $params = [
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => is_array($this->config['scopes']) 
                ? implode(' ', $this->config['scopes']) 
                : $this->config['scopes'],
            'state' => $state,
            'response_mode' => 'query',
        ];

        return $this->config['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function getAccessTokenFromCode(string $code): array
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'grant_type' => 'authorization_code',
            // Note: Do NOT include scope here - it's already established during authorization
        ];

        try {
            $response = $this->client->post($this->config['token_url'], [
                'form_params' => $params,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json'
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse token response');
            }

            if (!isset($tokenData['access_token'])) {
                throw new \Exception('No access token in response');
            }

            return $tokenData;
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Microsoft Auth Error - Token exchange failed', [
                'error' => $e->getMessage(),
                'response' => $errorBody
            ]);
            throw new \Exception('Failed to exchange code for token: ' . $e->getMessage());
        }
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            // Note: Do NOT include scope here - it's preserved from the original authorization
        ];

        try {
            $response = $this->client->post($this->config['token_url'], [
                'form_params' => $params,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json'
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse refresh token response');
            }

            if (!isset($tokenData['access_token'])) {
                throw new \Exception('No access token in refresh response');
            }

            return $tokenData;
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Microsoft Token Refresh Error', [
                'error' => $e->getMessage(),
                'response' => $errorBody
            ]);
            throw new \Exception('Failed to refresh access token: ' . $e->getMessage());
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

        $scopes = isset($tokenData['scope']) 
            ? (is_string($tokenData['scope']) ? explode(' ', $tokenData['scope']) : $tokenData['scope'])
            : (is_array($this->config['scopes']) ? $this->config['scopes'] : explode(' ', $this->config['scopes']));

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
                'scope' => is_array($scopes) ? implode(' ', $scopes) : $scopes
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

        // Try to refresh the token if it's expired
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

            $userData = json_decode($response->getBody()->getContents(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse user info response');
            }

            return $userData;
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Failed to get user info', [
                'error' => $e->getMessage(),
                'response' => $errorBody
            ]);
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
            'expires_soon' => method_exists($token, 'isExpiringSoon') 
                ? $token->isExpiringSoon(3600) // 1 hour
                : ($token->expires_at && $token->expires_at->subMinutes(60)->isPast()),
            'has_refresh_token' => !empty($token->refresh_token),
            'scope' => $token->scope,
            'token_type' => $token->token_type
        ];
    }
}