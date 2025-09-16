<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PersonalOneDriveService
{
    protected $accessToken;
    protected $refreshToken;
    protected $guzzle;

    public function __construct()
    {
        $this->guzzle = new GuzzleClient();
    }

    /**
     * Step 1: Get authorization URL for user to visit
     */
    public function getAuthorizationUrl()
    {
        $redirectUri = config('onedrive.redirect_uri', 'http://localhost/auth/callback');
        
        $params = [
            'client_id' => config('onedrive.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => 'https://graph.microsoft.com/Files.ReadWrite offline_access',
            'response_mode' => 'query'
        ];

        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    /**
     * Step 2: Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens($authorizationCode)
    {
        try {
            $redirectUri = config('onedrive.redirect_uri', 'http://localhost/auth/callback');
            
            $response = $this->guzzle->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'form_params' => [
                    'client_id' => config('onedrive.client_id'),
                    'client_secret' => config('onedrive.client_secret'),
                    'code' => $authorizationCode,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ]
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            $this->accessToken = $tokenData['access_token'];
            $this->refreshToken = $tokenData['refresh_token'];
            
            // Cache tokens
            Cache::put('personal_onedrive_access_token', $this->accessToken, now()->addMinutes(55));
            Cache::put('personal_onedrive_refresh_token', $this->refreshToken, now()->addDays(30));
            
            Log::info('Personal OneDrive tokens obtained successfully');
            
            return [
                'access_token' => $this->accessToken,
                'refresh_token' => $this->refreshToken,
                'expires_in' => $tokenData['expires_in']
            ];
            
        } catch (\Exception $e) {
            Log::error('Token exchange failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get valid access token (refresh if needed)
     */
    public function getAccessToken()
    {
        $this->accessToken = Cache::get('personal_onedrive_access_token');
        
        if (!$this->accessToken) {
            $this->refreshToken = Cache::get('personal_onedrive_refresh_token');
            
            if ($this->refreshToken) {
                $this->refreshAccessToken();
            } else {
                throw new \Exception('No valid tokens found. Please re-authorize.');
            }
        }
        
        return $this->accessToken;
    }

    /**
     * Refresh access token using refresh token
     */
    protected function refreshAccessToken()
    {
        try {
            $response = $this->guzzle->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'form_params' => [
                    'client_id' => config('onedrive.client_id'),
                    'client_secret' => config('onedrive.client_secret'),
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ]
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            $this->accessToken = $tokenData['access_token'];
            
            // Update refresh token if provided
            if (isset($tokenData['refresh_token'])) {
                $this->refreshToken = $tokenData['refresh_token'];
                Cache::put('personal_onedrive_refresh_token', $this->refreshToken, now()->addDays(30));
            }
            
            Cache::put('personal_onedrive_access_token', $this->accessToken, now()->addMinutes(55));
            
            Log::info('Personal OneDrive access token refreshed');
            
        } catch (\Exception $e) {
            Log::error('Token refresh failed: ' . $e->getMessage());
            // Clear cached tokens
            Cache::forget('personal_onedrive_access_token');
            Cache::forget('personal_onedrive_refresh_token');
            throw new \Exception('Token refresh failed. Please re-authorize.');
        }
    }

    /**
     * Upload file to personal OneDrive
     */
    public function uploadFile($localPath, $remotePath)
    {
        $this->getAccessToken();
        
        if (!file_exists($localPath)) {
            throw new \Exception("File not found: {$localPath}");
        }
        
        $fileSize = filesize($localPath);
        
        try {
            if ($fileSize < 4 * 1024 * 1024) { // Less than 4MB - simple upload
                return $this->simpleUpload($localPath, $remotePath);
            } else { // Large file - resumable upload
                return $this->resumableUpload($localPath, $remotePath);
            }
        } catch (\Exception $e) {
            Log::error('File upload failed', [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Simple upload for small files
     */
    protected function simpleUpload($localPath, $remotePath)
    {
        $fileContent = file_get_contents($localPath);
        $fileName = basename($remotePath);
        
        try {
            $uploadUrl = "https://graph.microsoft.com/v1.0/me/drive/root:/{$remotePath}:/content";
            
            $response = $this->guzzle->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/octet-stream'
                ],
                'body' => $fileContent
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            Log::info('File uploaded successfully to personal OneDrive', ['file_name' => $fileName]);
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Simple upload failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Resumable upload for large files
     */
    protected function resumableUpload($localPath, $remotePath)
    {
        $fileName = basename($remotePath);
        $fileSize = filesize($localPath);
        
        try {
            // Create upload session
            $sessionUrl = "https://graph.microsoft.com/v1.0/me/drive/root:/{$remotePath}:/createUploadSession";
            
            $sessionData = [
                'item' => [
                    '@microsoft.graph.conflictBehavior' => 'replace',
                    'name' => $fileName
                ]
            ];
            
            $sessionResponse = $this->guzzle->post($sessionUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $sessionData
            ]);
            
            $sessionData = json_decode($sessionResponse->getBody()->getContents(), true);
            $uploadUrl = $sessionData['uploadUrl'];
            
            // Upload in chunks
            $chunkSize = 320 * 1024; // 320KB chunks
            $file = fopen($localPath, 'rb');
            $uploadedBytes = 0;
            
            while (!feof($file)) {
                $chunk = fread($file, $chunkSize);
                $chunkLength = strlen($chunk);
                
                $contentRange = "bytes {$uploadedBytes}-" . ($uploadedBytes + $chunkLength - 1) . "/{$fileSize}";
                
                $response = $this->guzzle->put($uploadUrl, [
                    'body' => $chunk,
                    'headers' => [
                        'Content-Range' => $contentRange,
                        'Content-Length' => $chunkLength,
                    ]
                ]);
                
                $uploadedBytes += $chunkLength;
                
                if ($response->getStatusCode() === 201 || $response->getStatusCode() === 200) {
                    fclose($file);
                    Log::info('Large file uploaded successfully to personal OneDrive', ['file_name' => $fileName, 'size' => $fileSize]);
                    return json_decode($response->getBody()->getContents(), true);
                }
            }
            
            fclose($file);
            
        } catch (\Exception $e) {
            Log::error('Resumable upload failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * List files in personal OneDrive
     */
    public function listFiles($folderPath = null)
    {
        $this->getAccessToken();
        
        try {
            if ($folderPath) {
                $endpoint = "https://graph.microsoft.com/v1.0/me/drive/root:/{$folderPath}:/children";
            } else {
                $endpoint = "https://graph.microsoft.com/v1.0/me/drive/root/children";
            }
            
            $response = $this->guzzle->get($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['value'];
            
        } catch (\Exception $e) {
            Log::error('Failed to list files', ['folder_path' => $folderPath, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create folder in personal OneDrive
     */
    public function createFolder($folderName, $parentPath = null)
    {
        $this->getAccessToken();
        
        try {
            if ($parentPath) {
                $endpoint = "https://graph.microsoft.com/v1.0/me/drive/root:/{$parentPath}:/children";
            } else {
                $endpoint = "https://graph.microsoft.com/v1.0/me/drive/root/children";
            }
            
            $folderData = [
                'name' => $folderName,
                'folder' => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename'
            ];
            
            $response = $this->guzzle->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $folderData
            ]);
            
            $createdFolder = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Folder created in personal OneDrive', ['folder_name' => $folderName]);
            
            return $createdFolder;
            
        } catch (\Exception $e) {
            Log::error('Failed to create folder', ['folder_name' => $folderName, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Test connection to personal OneDrive
     */
    public function testConnection()
    {
        try {
            $this->getAccessToken();
            
            $response = $this->guzzle->get('https://graph.microsoft.com/v1.0/me/drive', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $driveInfo = json_decode($response->getBody()->getContents(), true);
            
            return [
                'success' => true,
                'drive_id' => $driveInfo['id'],
                'drive_type' => $driveInfo['driveType'] ?? 'personal',
                'quota_total' => $driveInfo['quota']['total'] ?? 0,
                'quota_used' => $driveInfo['quota']['used'] ?? 0,
                'owner' => $driveInfo['owner']['user']['displayName'] ?? 'Unknown'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}