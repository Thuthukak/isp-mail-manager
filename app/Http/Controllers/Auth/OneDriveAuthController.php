<?php

namespace App\Http\Controllers\Auth;

use App\Services\MicrosoftAuthService;
use App\Services\OneDrivePersonalService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class OneDriveAuthController extends Controller
{
    public function __construct(
        private MicrosoftAuthService $authService,
        private OneDrivePersonalService $oneDriveService
    ) {
        $this->middleware('auth');
    }

    /**
     * Initiate the OAuth flow
     */
    public function authenticate(): RedirectResponse
    {
        $user = Auth::user();
        
        if (!$user->hasRole(['super_admin', 'admin'])) {
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'You do not have permission to manage OneDrive authentication.');
        }

        try {
            // Store the user ID in session to associate the token later
            Session::put('onedrive_auth_user_id', $user->id);
            
            $authUrl = $this->authService->getAuthorizationUrl();
            
            Log::info('OneDrive authentication initiated', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);
            
            return redirect($authUrl);
        } catch (\Exception $e) {
            Log::error('Failed to initiate OneDrive authentication', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'Failed to initiate authentication: ' . $e->getMessage());
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
        
        // Get the user ID from session
        $userId = Session::get('onedrive_auth_user_id');
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            Log::error('OneDrive callback: No user found in session');
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'Authentication session expired. Please try again.');
        }

        // Clear the session
        Session::forget('onedrive_auth_user_id');

        if ($error) {
            Log::warning('OneDrive authentication denied', [
                'user_id' => $user->id,
                'error' => $error,
                'description' => $errorDescription
            ]);
            
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'Authentication was denied: ' . ($errorDescription ?: $error));
        }

        if (!$code) {
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'No authorization code received from Microsoft.');
        }

        try {
            // Exchange code for token
            $tokenData = $this->authService->getAccessTokenFromCode($code);
            
            // Get user info to verify the connection
            $userInfo = $this->authService->getUserInfo($tokenData['access_token']);
            
            // Store the token associated with the user
            $this->authService->storeToken($tokenData, $user);
            
            // Test OneDrive connection
            $driveInfo = $this->oneDriveService->getDriveInfo($user);
            
            Log::info('OneDrive authentication successful', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'microsoft_user' => $userInfo['userPrincipalName'] ?? 'Unknown'
            ]);
            
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('success', 'OneDrive authentication successful! Connected to: ' . 
                       ($userInfo['displayName'] ?? $userInfo['userPrincipalName'] ?? 'Microsoft Account'));
                       
        } catch (\Exception $e) {
            Log::error('OneDrive authentication failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->route('filament.admin.pages.onedrive-auth')
                ->with('error', 'Authentication failed: ' . $e->getMessage());
        }
    }
}