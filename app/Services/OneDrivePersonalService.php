<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class OneDrivePersonalService
{
    private Client $client;
    private MicrosoftAuthService $authService;
    private ConfigurationService $configService;

    public function __construct(MicrosoftAuthService $authService, ConfigurationService $configService)
    {
        $this->authService = $authService;
        $this->configService = $configService;
        $this->client = new Client([
            'timeout' => 60,
            'verify' => false, // Set to true in production
        ]);
    }

    /**
     * Get OneDrive configuration from database
     */
    private function getConfig(): array
    {
        $configs = $this->configService->getGroup('onedrive');
        
        // Build the configuration array with fallback values
        return [
            'client_id' => $configs->get('MICROSOFT_GRAPH_CLIENT_ID'),
            'client_secret' => $configs->get('MICROSOFT_GRAPH_CLIENT_SECRET'),
            'tenant_id' => $configs->get('MICROSOFT_GRAPH_TENANT_ID', 'common'),
            'redirect_uri' => $configs->get('MICROSOFT_GRAPH_REDIRECT_URI'),
            'user_id' => $configs->get('ONEDRIVE_USER_ID'),
            'root_folder' => $configs->get('ONEDRIVE_ROOT_FOLDER', 'ISP_Mail_Backups'),
            'upload_chunk_size' => (int) $configs->get('ONEDRIVE_UPLOAD_CHUNK_SIZE', 10485760), // 10MB
            'max_retry_attempts' => (int) $configs->get('ONEDRIVE_MAX_RETRY_ATTEMPTS', 3),
            'retry_delay' => (int) $configs->get('ONEDRIVE_RETRY_DELAY', 5),
            'scopes' => $this->parseScopes($configs->get('MICROSOFT_SCOPES', 'https://graph.microsoft.com/Files.ReadWrite https://graph.microsoft.com/User.Read offline_access')),
            'api_url' => 'https://graph.microsoft.com/v1.0',
            'auth_url' => 'https://login.microsoftonline.com/' . $configs->get('MICROSOFT_GRAPH_TENANT_ID', 'common') . '/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/' . $configs->get('MICROSOFT_GRAPH_TENANT_ID', 'common') . '/oauth2/v2.0/token',
            'drive_type' => $configs->get('ONEDRIVE_TYPE', 'personal'),
            'drive_id' => $configs->get('ONEDRIVE_DRIVE_ID', 'me/drive'),
        ];
    }

    /**
     * Parse scopes string into array
     */
    private function parseScopes(string $scopes): array
    {
        return array_filter(explode(' ', $scopes));
    }

    /**
     * Validate required configuration
     */
    private function validateConfig(array $config): void
    {
        $required = ['client_id', 'client_secret', 'redirect_uri'];
        
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \Exception("Missing required OneDrive configuration: {$key}. Please configure it in the admin panel.");
            }
        }
    }

    /**
     * Get valid access token
     */
    private function getAccessToken(User $user = null): ?string
    {
        return $this->authService->getValidAccessToken($user);
    }

    /**
     * Make authenticated request to Graph API
     */
    private function makeRequest(string $method, string $endpoint, array $options = [], User $user = null): array
    {
        $accessToken = $this->getAccessToken($user);
        
        if (!$accessToken) {
            throw new \Exception('No valid access token available. Please re-authenticate.');
        }

        $config = $this->getConfig();

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ]);

        try {
            $response = $this->client->request($method, $config['api_url'] . $endpoint, $options);
            $body = $response->getBody()->getContents();
            
            return json_decode($body, true) ?? [];
        } catch (RequestException $e) {
            $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('OneDrive API Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $e->getResponse()?->getStatusCode(),
                'error' => $errorBody
            ]);
            
            throw new \Exception('OneDrive API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Get drive information
     */
    public function getDriveInfo(User $user = null): array
    {
        return $this->makeRequest('GET', '/me/drive', [], $user);
    }

    /**
     * Create root folder if it doesn't exist
     */
    public function ensureRootFolder(User $user = null): array
    {
        $config = $this->getConfig();
        
        try {
            // Try to get the folder first
            return $this->getFolder($config['root_folder'], $user);
        } catch (\Exception $e) {
            // Folder doesn't exist, create it
            Log::info('Creating root folder: ' . $config['root_folder']);
            return $this->createFolder($config['root_folder'], null, $user);
        }
    }

    /**
     * Get folder information
     */
    public function getFolder(string $folderPath, User $user = null): array
    {
        $encodedPath = urlencode($folderPath);
        return $this->makeRequest('GET', "/me/drive/root:/{$encodedPath}", [], $user);
    }

    /**
     * Create a folder
     */
    public function createFolder(string $folderName, ?string $parentPath = null, User $user = null): array
    {
        $parentEndpoint = $parentPath 
            ? "/me/drive/root:/" . urlencode($parentPath) . ":/children"
            : "/me/drive/root/children";

        $folderData = [
            'name' => $folderName,
            'folder' => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'rename'
        ];

        return $this->makeRequest('POST', $parentEndpoint, [
            'json' => $folderData
        ], $user);
    }

    /**
     * List folder contents
     */
    public function listFolderContents(string $folderPath = '', User $user = null): array
    {
        $endpoint = empty($folderPath) 
            ? "/me/drive/root/children"
            : "/me/drive/root:/" . urlencode($folderPath) . ":/children";

        return $this->makeRequest('GET', $endpoint, [], $user);
    }

    /**
     * Upload a small file (< 4MB)
     */
    public function uploadSmallFile(string $localFilePath, string $remotePath, User $user = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new \Exception("Local file does not exist: {$localFilePath}");
        }

        $fileSize = filesize($localFilePath);
        if ($fileSize > 4 * 1024 * 1024) { // 4MB
            throw new \Exception("File too large for simple upload. Use uploadLargeFile() instead.");
        }

        $fileContent = file_get_contents($localFilePath);
        $encodedPath = urlencode($remotePath);
        
        return $this->makeRequest('PUT', "/me/drive/root:/{$encodedPath}:/content", [
            'body' => $fileContent,
            'headers' => [
                'Content-Type' => 'application/octet-stream'
            ]
        ], $user);
    }

    /**
     * Create upload session for large files
     */
    public function createUploadSession(string $remotePath, int $fileSize, User $user = null): array
    {
        $encodedPath = urlencode($remotePath);
        
        $uploadSessionData = [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'rename',
                'name' => basename($remotePath)
            ]
        ];

        return $this->makeRequest('POST', "/me/drive/root:/{$encodedPath}:/createUploadSession", [
            'json' => $uploadSessionData
        ], $user);
    }

    /**
     * Upload file chunk
     */
    public function uploadChunk(string $uploadUrl, string $chunk, int $rangeStart, int $rangeEnd, int $totalSize): array
    {
        $contentRange = "bytes {$rangeStart}-{$rangeEnd}/{$totalSize}";
        
        try {
            $response = $this->client->put($uploadUrl, [
                'body' => $chunk,
                'headers' => [
                    'Content-Range' => $contentRange,
                    'Content-Length' => strlen($chunk),
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            Log::error('Chunk upload error', [
                'range' => $contentRange,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Upload large file using resumable upload
     */
    public function uploadLargeFile(string $localFilePath, string $remotePath, User $user = null, callable $progressCallback = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new \Exception("Local file does not exist: {$localFilePath}");
        }

        $config = $this->getConfig();
        $fileSize = filesize($localFilePath);
        $chunkSize = $config['upload_chunk_size'];

        // Create upload session
        $session = $this->createUploadSession($remotePath, $fileSize, $user);
        $uploadUrl = $session['uploadUrl'];

        $handle = fopen($localFilePath, 'rb');
        if (!$handle) {
            throw new \Exception("Cannot open file for reading: {$localFilePath}");
        }

        $uploadedBytes = 0;
        $retryCount = 0;
        $maxRetries = $config['max_retry_attempts'];

        try {
            while ($uploadedBytes < $fileSize) {
                $chunk = fread($handle, $chunkSize);
                $chunkSize = strlen($chunk);
                
                if ($chunkSize === 0) {
                    break;
                }

                $rangeStart = $uploadedBytes;
                $rangeEnd = $uploadedBytes + $chunkSize - 1;

                try {
                    $result = $this->uploadChunk($uploadUrl, $chunk, $rangeStart, $rangeEnd, $fileSize);
                    
                    $uploadedBytes += $chunkSize;
                    $retryCount = 0; // Reset retry count on successful upload
                    
                    // Call progress callback if provided
                    if ($progressCallback) {
                        $progressCallback($uploadedBytes, $fileSize);
                    }
                    
                    Log::debug("Uploaded chunk", [
                        'range' => "{$rangeStart}-{$rangeEnd}",
                        'progress' => round(($uploadedBytes / $fileSize) * 100, 2) . '%'
                    ]);
                    
                    // Check if upload is complete
                    if (isset($result['id'])) {
                        Log::info("File upload completed", ['file' => $remotePath]);
                        return $result;
                    }
                    
                } catch (RequestException $e) {
                    $retryCount++;
                    
                    if ($retryCount >= $maxRetries) {
                        throw new \Exception("Max retries exceeded for chunk upload");
                    }
                    
                    Log::warning("Chunk upload failed, retrying", [
                        'attempt' => $retryCount,
                        'range' => "{$rangeStart}-{$rangeEnd}",
                        'error' => $e->getMessage()
                    ]);
                    
                    // Exponential backoff
                    sleep(pow(2, $retryCount - 1) * $config['retry_delay']);
                    
                    // Reset file pointer for retry
                    fseek($handle, $rangeStart);
                }
            }
        } finally {
            fclose($handle);
        }

        throw new \Exception("File upload did not complete successfully");
    }

    /**
     * Download a file
     */
    public function downloadFile(string $remotePath, string $localPath, User $user = null): bool
    {
        $encodedPath = urlencode($remotePath);
        $config = $this->getConfig();
        
        try {
            $accessToken = $this->getAccessToken($user);
            if (!$accessToken) {
                throw new \Exception('No valid access token available');
            }

            $response = $this->client->get(
                $config['api_url'] . "/me/drive/root:/{$encodedPath}:/content",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'sink' => $localPath
                ]
            );

            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            Log::error('File download error', [
                'remote_path' => $remotePath,
                'local_path' => $localPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete a file or folder
     */
    public function delete(string $remotePath, User $user = null): bool
    {
        try {
            $encodedPath = urlencode($remotePath);
            $this->makeRequest('DELETE', "/me/drive/root:/{$encodedPath}", [], $user);
            
            Log::info('Successfully deleted file/folder', ['path' => $remotePath]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete file/folder', [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get file/folder metadata
     */
    public function getMetadata(string $remotePath, User $user = null): ?array
    {
        try {
            $encodedPath = urlencode($remotePath);
            return $this->makeRequest('GET', "/me/drive/root:/{$encodedPath}", [], $user);
        } catch (\Exception $e) {
            Log::error('Failed to get metadata', [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $remotePath, User $user = null): bool
    {
        return $this->getMetadata($remotePath, $user) !== null;
    }

    /**
     * Get file size
     */
    public function getFileSize(string $remotePath, User $user = null): ?int
    {
        $metadata = $this->getMetadata($remotePath, $user);
        return $metadata['size'] ?? null;
    }

    /**
     * Move/rename a file or folder
     */
    public function move(string $sourcePath, string $destinationPath, User $user = null): array
    {
        $encodedSourcePath = urlencode($sourcePath);
        $destinationFolder = dirname($destinationPath);
        $newName = basename($destinationPath);
        
        $moveData = [
            'name' => $newName,
        ];
        
        // If moving to a different folder
        if ($destinationFolder !== '.' && $destinationFolder !== dirname($sourcePath)) {
            $moveData['parentReference'] = [
                'path' => "/drive/root:/" . $destinationFolder
            ];
        }
        
        return $this->makeRequest('PATCH', "/me/drive/root:/{$encodedSourcePath}", [
            'json' => $moveData
        ], $user);
    }

    /**
     * Copy a file
     */
    public function copy(string $sourcePath, string $destinationPath, User $user = null): array
    {
        $encodedSourcePath = urlencode($sourcePath);
        $destinationFolder = dirname($destinationPath);
        $newName = basename($destinationPath);
        
        $copyData = [
            'name' => $newName,
            'parentReference' => [
                'path' => "/drive/root:/" . ($destinationFolder === '.' ? '' : $destinationFolder)
            ]
        ];
        
        return $this->makeRequest('POST', "/me/drive/root:/{$encodedSourcePath}:/copy", [
            'json' => $copyData
        ], $user);
    }

    /**
     * Get storage usage information
     */
    public function getStorageUsage(User $user = null): array
    {
        $driveInfo = $this->getDriveInfo($user);
        
        return [
            'total_bytes' => $driveInfo['quota']['total'] ?? 0,
            'used_bytes' => $driveInfo['quota']['used'] ?? 0,
            'remaining_bytes' => $driveInfo['quota']['remaining'] ?? 0,
            'deleted_bytes' => $driveInfo['quota']['deleted'] ?? 0,
            'state' => $driveInfo['quota']['state'] ?? 'normal'
        ];
    }

    /**
     * Search for files
     */
    public function searchFiles(string $query, User $user = null): array
    {
        $encodedQuery = urlencode($query);
        return $this->makeRequest('GET', "/me/drive/root/search(q='{$encodedQuery}')", [], $user);
    }

    /**
     * Get file download URL (for direct download)
     */
    public function getDownloadUrl(string $remotePath, User $user = null): ?string
    {
        try {
            $encodedPath = urlencode($remotePath);
            $response = $this->makeRequest('GET', "/me/drive/root:/{$encodedPath}?select=@microsoft.graph.downloadUrl", [], $user);
            
            return $response['@microsoft.graph.downloadUrl'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get download URL', [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create sharing link
     */
    public function createSharingLink(string $remotePath, string $type = 'view', string $scope = 'anonymous', User $user = null): ?array
    {
        try {
            $encodedPath = urlencode($remotePath);
            
            $linkData = [
                'type' => $type, // 'view' or 'edit'
                'scope' => $scope // 'anonymous', 'organization', or 'users'
            ];
            
            return $this->makeRequest('POST', "/me/drive/root:/{$encodedPath}:/createLink", [
                'json' => $linkData
            ], $user);
        } catch (\Exception $e) {
            Log::error('Failed to create sharing link', [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Test connection to OneDrive
     */
    public function testConnection(User $user = null): bool
    {
        try {
            $this->getDriveInfo($user);
            return true;
        } catch (\Exception $e) {
            Log::error('OneDrive connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get detailed folder structure (recursive)
     */
    public function getFolderStructure(string $folderPath = '', int $maxDepth = 3, int $currentDepth = 0, User $user = null): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        try {
            $contents = $this->listFolderContents($folderPath, $user);
            $structure = [];

            foreach ($contents['value'] ?? [] as $item) {
                $itemPath = empty($folderPath) ? $item['name'] : $folderPath . '/' . $item['name'];
                
                $structureItem = [
                    'name' => $item['name'],
                    'path' => $itemPath,
                    'type' => isset($item['folder']) ? 'folder' : 'file',
                    'size' => $item['size'] ?? 0,
                    'created' => $item['createdDateTime'] ?? null,
                    'modified' => $item['lastModifiedDateTime'] ?? null,
                ];

                // If it's a folder, get its contents recursively
                if (isset($item['folder'])) {
                    $structureItem['children'] = $this->getFolderStructure($itemPath, $maxDepth, $currentDepth + 1, $user);
                    $structureItem['child_count'] = $item['folder']['childCount'] ?? 0;
                }

                $structure[] = $structureItem;
            }

            return $structure;
        } catch (\Exception $e) {
            Log::error('Failed to get folder structure', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Bulk operations helper - process multiple files
     */
    public function bulkUpload(array $files, string $remoteFolderPath = '', User $user = null, callable $progressCallback = null): array
    {
        $results = [];
        $totalFiles = count($files);
        
        foreach ($files as $index => $file) {
            $localPath = $file['local_path'];
            $remoteName = $file['remote_name'] ?? basename($localPath);
            $remotePath = empty($remoteFolderPath) ? $remoteName : $remoteFolderPath . '/' . $remoteName;
            
            try {
                $fileSize = filesize($localPath);
                
                if ($fileSize > 4 * 1024 * 1024) { // > 4MB
                    $result = $this->uploadLargeFile($localPath, $remotePath, $user);
                } else {
                    $result = $this->uploadSmallFile($localPath, $remotePath, $user);
                }
                
                $results[] = [
                    'local_path' => $localPath,
                    'remote_path' => $remotePath,
                    'success' => true,
                    'result' => $result,
                    'size' => $fileSize
                ];
                
                if ($progressCallback) {
                    $progressCallback($index + 1, $totalFiles, $localPath, true);
                }
                
            } catch (\Exception $e) {
                $results[] = [
                    'local_path' => $localPath,
                    'remote_path' => $remotePath,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'size' => filesize($localPath)
                ];
                
                if ($progressCallback) {
                    $progressCallback($index + 1, $totalFiles, $localPath, false, $e->getMessage());
                }
            }
        }
        
        return $results;
    }

    /**
     * Calculate folder size (recursive)
     */
    public function calculateFolderSize(string $folderPath = '', User $user = null): int
    {
        try {
            $contents = $this->listFolderContents($folderPath, $user);
            $totalSize = 0;

            foreach ($contents['value'] ?? [] as $item) {
                if (isset($item['folder'])) {
                    // Recursively calculate folder size
                    $subFolderPath = empty($folderPath) ? $item['name'] : $folderPath . '/' . $item['name'];
                    $totalSize += $this->calculateFolderSize($subFolderPath, $user);
                } else {
                    // Add file size
                    $totalSize += $item['size'] ?? 0;
                }
            }

            return $totalSize;
        } catch (\Exception $e) {
            Log::error('Failed to calculate folder size', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if OneDrive is properly configured
     */
    public function isConfigured(): bool
    {
        try {
            $config = $this->getConfig();
            $this->validateConfig($config);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get configuration status for admin dashboard
     */
    public function getConfigurationStatus(): array
    {
        $config = $this->getConfig();
        
        return [
            'is_configured' => $this->isConfigured(),
            'has_client_id' => !empty($config['client_id']),
            'has_client_secret' => !empty($config['client_secret']),
            'has_redirect_uri' => !empty($config['redirect_uri']),
            'tenant_id' => $config['tenant_id'],
            'drive_type' => $config['drive_type'],
            'root_folder' => $config['root_folder'],
            'upload_chunk_size' => $config['upload_chunk_size'],
            'max_retry_attempts' => $config['max_retry_attempts'],
            'retry_delay' => $config['retry_delay'],
            'scopes' => $config['scopes'],
        ];
    }

    /**
     * Get configuration for external services
     */
    public function getOneDriveConfig(): array
    {
        return $this->getConfig();
    }
}