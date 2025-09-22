<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MicrosoftAuthService;
use App\Services\OneDrivePersonalService;
use App\Services\ConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Filament\Notifications\Notification;

class OneDriveAuthController extends Controller
{
    public function __construct(
        private MicrosoftAuthService $authService,
        private OneDrivePersonalService $oneDriveService,
        private ConfigurationService $configService
    ) {
        
    }

    /**
     * Initiate the OAuth flow
     */
    public function authenticate(): RedirectResponse
    {
        $user = Auth::user();
        
        if (!$user->hasRole(['super_admin', 'admin'])) {
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'You do not have permission to manage OneDrive authentication.');
        }

        // Check if OneDrive is properly configured before starting auth flow
        if (!$this->authService->isConfigured()) {
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'OneDrive is not properly configured. Please configure it in System Configuration first.');
        }

        try {
            // Generate CSRF state parameter
            $state = bin2hex(random_bytes(16));
            
            // Store both user ID and state in session
            Session::put('onedrive_auth_user_id', $user->id);
            Session::put('onedrive_oauth_state', $state);
            
            $authUrl = $this->authService->getAuthorizationUrl($state);
            
            Log::info('OneDrive authentication initiated', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'state' => $state,
                'config_status' => $this->authService->getConfigurationStatus()
            ]);
            
            return redirect($authUrl);
        } catch (\Exception $e) {
            Log::error('Failed to initiate OneDrive authentication', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'is_configured' => $this->authService->isConfigured()
            ]);
            
            $errorMessage = 'Failed to initiate authentication: ' . $e->getMessage();
            
            // Provide more helpful error messages for common configuration issues
            if (str_contains($e->getMessage(), 'client_id')) {
                $errorMessage = 'Microsoft Graph Client ID is not configured. Please check your OneDrive settings.';
            } elseif (str_contains($e->getMessage(), 'redirect_uri')) {
                $errorMessage = 'OAuth Redirect URI is not configured. Please check your OneDrive settings.';
            }
            
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', $errorMessage);
        }
    }

    /**
     * Handle the OAuth callback
     */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->get('code');
        $error = $request->get('error');
        $errorDescription = $request->get('error_description');
        $receivedState = $request->get('state');
        
        // Get the user ID and state from session
        $userId = Session::get('onedrive_auth_user_id');
        $sessionState = Session::get('onedrive_oauth_state');
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        // Always clean up session data
        Session::forget(['onedrive_auth_user_id', 'onedrive_oauth_state']);
        
        if (!$user) {
            Log::error('OneDrive callback: No user found in session');
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'Authentication session expired. Please try again.');
        }

        // Validate CSRF state parameter
        if (!$sessionState || $sessionState !== $receivedState) {
            Log::warning('OneDrive callback: Invalid state parameter', [
                'user_id' => $user->id,
                'expected_state' => $sessionState,
                'received_state' => $receivedState
            ]);
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'Invalid authentication state. Possible security issue detected.');
        }

        if ($error) {
            Log::warning('OneDrive authentication denied', [
                'user_id' => $user->id,
                'error' => $error,
                'description' => $errorDescription
            ]);
            
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'Authentication was denied: ' . ($errorDescription ?: $error));
        }

        if (!$code) {
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'No authorization code received from Microsoft.');
        }

        try {
            // Double-check configuration before proceeding
            if (!$this->authService->isConfigured()) {
                throw new \Exception('OneDrive configuration is incomplete. Please check your settings.');
            }

            // Exchange code for token
            $tokenData = $this->authService->getAccessTokenFromCode($code);
            
            // Get user info to verify the connection
            $userInfo = $this->authService->getUserInfo($tokenData['access_token']);
            
            // Store the token associated with the user
            $oauthToken = $this->authService->storeToken($tokenData, $user);
            
            // Test OneDrive connection
            $driveInfo = $this->oneDriveService->getDriveInfo($user);
            
            // Get configuration for logging
            $config = $this->configService->getGroup('onedrive');
            
            Log::info('OneDrive authentication successful', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'microsoft_user' => $userInfo['userPrincipalName'] ?? 'Unknown',
                'drive_id' => $driveInfo['id'] ?? null,
                'drive_type' => $config->get('ONEDRIVE_TYPE', 'personal'),
                'root_folder' => $config->get('ONEDRIVE_ROOT_FOLDER', 'ISP_Mail_Backups'),
                'token_expires_at' => $oauthToken->expires_at?->toISOString()
            ]);
            
            $displayName = $userInfo['displayName'] ?? $userInfo['userPrincipalName'] ?? 'Microsoft Account';
            $driveOwner = $driveInfo['owner']['user']['displayName'] ?? '';
            
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('success', sprintf(
                    'OneDrive authentication successful! Connected to: %s%s',
                    $displayName,
                    $driveOwner ? " (Drive: $driveOwner)" : ''
                ));
                       
        } catch (\Exception $e) {
            Log::error('OneDrive authentication failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'code_present' => !empty($code),
                'config_status' => $this->authService->getConfigurationStatus(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Provide more specific error messages
            $errorMessage = 'Authentication failed: ' . $e->getMessage();
            
            if (str_contains($e->getMessage(), 'client_secret')) {
                $errorMessage = 'Invalid client secret. Please check your OneDrive configuration.';
            } elseif (str_contains($e->getMessage(), 'redirect_uri')) {
                $errorMessage = 'Invalid redirect URI. Please check your OneDrive configuration matches your Azure app settings.';
            } elseif (str_contains($e->getMessage(), 'configuration')) {
                $errorMessage = 'OneDrive configuration error. Please check your settings in System Configuration.';
            }
            
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', $errorMessage);
        }
    }

    /**
     * Revoke OneDrive authentication
     */
    public function revoke(): RedirectResponse
    {
        $user = Auth::user();
        
        if (!$user->hasRole(['super_admin', 'admin'])) {
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'You do not have permission to manage OneDrive authentication.');
        }

        try {
            $success = $this->authService->revokeToken($user);
            
            if ($success) {
                Log::info('OneDrive authentication revoked', [
                    'user_id' => $user->id,
                    'user_email' => $user->email
                ]);
                
                return redirect()
                    ->route('filament.admin.pages.one-drive-auth')
                    ->with('success', 'OneDrive authentication has been revoked successfully.');
            } else {
                return redirect()
                    ->route('filament.admin.pages.one-drive-auth')
                    ->with('info', 'No OneDrive authentication found to revoke.');
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to revoke OneDrive authentication', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()
                ->route('filament.admin.pages.one-drive-auth')
                ->with('error', 'Failed to revoke authentication: ' . $e->getMessage());
        }
    }

    /**
     * Get authentication status
     */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        
        if (!$user || !$user->hasRole(['super_admin', 'admin'])) {
            return response()->json([
                'authenticated' => false,
                'error' => 'Unauthorized'
            ], 403);
        }

        try {
            $isAuthenticated = $this->authService->isAuthenticated($user);
            $tokenInfo = $this->authService->getTokenInfo($user);
            $configStatus = $this->authService->getConfigurationStatus();
            
            $response = [
                'authenticated' => $isAuthenticated,
                'configured' => $configStatus['is_configured'],
                'token_info' => $tokenInfo,
                'configuration_status' => $configStatus
            ];
            
            if ($isAuthenticated && $configStatus['is_configured']) {
                try {
                    $driveInfo = $this->oneDriveService->getDriveInfo($user);
                    $response['drive_info'] = [
                        'id' => $driveInfo['id'] ?? null,
                        'owner' => $driveInfo['owner']['user']['displayName'] ?? null,
                        'quota' => $driveInfo['quota'] ?? null
                    ];
                    
                    // Test connection
                    $response['connection_test'] = $this->oneDriveService->testConnection($user);
                } catch (\Exception $e) {
                    $response['drive_error'] = $e->getMessage();
                    $response['connection_test'] = false;
                }
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            Log::error('Failed to get OneDrive authentication status', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'authenticated' => false,
                'configured' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test OneDrive configuration without authentication
     */
    public function testConfiguration(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        
        if (!$user || !$user->hasRole(['super_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $configStatus = $this->authService->getConfigurationStatus();
            
            return response()->json([
                'configured' => $configStatus['is_configured'],
                'status' => $configStatus,
                'message' => $configStatus['is_configured'] 
                    ? 'OneDrive is properly configured' 
                    : 'OneDrive configuration is incomplete'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'configured' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}