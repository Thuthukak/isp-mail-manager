<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MicrosoftAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class MicrosoftController extends Controller
{
    public function __construct(
        private MicrosoftAuthService $authService
    ) {}

    /**
     * Redirect to Microsoft OAuth
     */
    public function redirect()
    {
        $state = \Str::random(40);
        Session::put('microsoft_oauth_state', $state);
        
        $authUrl = $this->authService->getAuthorizationUrl($state);
        
        return redirect($authUrl);
    }

    /**
     * Handle OAuth callback
     */
    public function callback(Request $request)
    {
        // Validate state parameter
        $state = Session::get('microsoft_oauth_state');
        if (!$state || $state !== $request->get('state')) {
            return redirect()->route('login')->withErrors(['error' => 'Invalid state parameter']);
        }

        // Check for errors
        if ($request->has('error')) {
            Log::error('Microsoft OAuth Error: ' . $request->get('error_description', $request->get('error')));
            return redirect()->route('login')->withErrors(['error' => 'Authentication failed']);
        }

        // Exchange code for token
        $code = $request->get('code');
        if (!$code) {
            return redirect()->route('login')->withErrors(['error' => 'No authorization code received']);
        }

        try {
            // Get access token
            $tokenData = $this->authService->getAccessTokenFromCode($code);
            
            // Get user info
            $userInfo = $this->authService->getUserInfo($tokenData['access_token']);
            
            // Store token (for now, without user association)
            $this->authService->storeToken($tokenData);
            
            Session::forget('microsoft_oauth_state');
            Session::flash('success', 'Successfully authenticated with Microsoft!');
            
            // Store user info in session for now
            Session::put('microsoft_user', $userInfo);
            
            return redirect()->route('filament.admin.pages.dashboard');
            
        } catch (\Exception $e) {
            Log::error('Microsoft OAuth Callback Error: ' . $e->getMessage());
            return redirect()->route('login')->withErrors(['error' => 'Authentication failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Sign out and revoke tokens
     */
    public function logout()
    {
        $this->authService->revokeToken();
        Session::forget('microsoft_user');
        
        return redirect()->route('login')->with('success', 'Successfully signed out');
    }
}