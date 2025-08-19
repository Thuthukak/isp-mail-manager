<?php

namespace App\Services;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\DriveItem;
use Microsoft\Graph\Model\UploadSession;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Exception;

class OneDriveService
{
    private Graph $graph;
    private string $driveId;

    public function __construct()
    {
        $this->graph = new Graph();
        $this->graph->setAccessToken($this->getAccessToken());
        $this->driveId = config('onedrive.drive_id', 'me/drive');
    }

    /**
     * Get OneDrive access token
     */
    private function getAccessToken(): string
    {
        // Implement OAuth2 token retrieval
        // This would typically use client credentials flow for server apps
        $client = new Client();
        
        $response = $client->post('https://login.microsoftonline.com/' . config('onedrive.tenant_id') . '/oauth2/v2.0/token', [
            'form_params' => [
                'client_id' => config('onedrive.client_id'),
                'client_secret' => config('onedrive.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials'
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['access_token'];
    }

    /**
     * Upload file to OneDrive
     */
    public function uploadFile(string $localPath, string $remotePath): bool
    {
        try {
            $fileSize = filesize($localPath);
            
            if ($fileSize > 4 * 1024 * 1024) { // 4MB threshold for resumable upload
                return $this->uploadLargeFile($localPath, $remotePath);
            }

            $fileContent = file_get_contents($localPath);
            $driveItem = $this->graph->createRequest('PUT', "/me/drive/root:/{$remotePath}:/content")
                ->attachBody($fileContent)
                ->execute();

            Log::info("File uploaded successfully", ['path' => $remotePath]);
            return true;

        } catch (Exception $e) {
            Log::error("Failed to upload file", [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Upload large file using resumable upload
     */
    private function uploadLargeFile(string $localPath, string $remotePath): bool
    {
        try {
            $fileSize = filesize($localPath);
            
            // Create upload session
            $uploadSession = $this->graph->createRequest('POST', "/me/drive/root:/{$remotePath}:/createUploadSession")
                ->addHeaders(['Content-Type' => 'application/json'])
                ->attachBody(json_encode([
                    'item' => [
                        '@microsoft.graph.conflictBehavior' => 'replace'
                    ]
                ]))
                ->execute();

            $uploadUrl = $uploadSession->getUploadUrl();
            
            // Upload in chunks
            $chunkSize = 320 * 1024; // 320KB chunks
            $file = fopen($localPath, 'rb');
            $offset = 0;

            while (!feof($file)) {
                $chunk = fread($file, $chunkSize);
                $chunkLength = strlen($chunk);
                
                $client = new Client();
                $response = $client->put($uploadUrl, [
                    'headers' => [
                        'Content-Range' => "bytes {$offset}-" . ($offset + $chunkLength - 1) . "/{$fileSize}",
                        'Content-Length' => $chunkLength
                    ],
                    'body' => $chunk
                ]);

                $offset += $chunkLength;
            }

            fclose($file);
            Log::info("Large file uploaded successfully", ['path' => $remotePath, 'size' => $fileSize]);
            return true;

        } catch (Exception $e) {
            Log::error("Failed to upload large file", [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Download file from OneDrive
     */
    public function downloadFile(string $remotePath, string $localPath): bool
    {
        try {
            $downloadUrl = $this->graph->createRequest('GET', "/me/drive/root:/{$remotePath}:/content")
                ->execute();

            $client = new Client();
            $response = $client->get($downloadUrl);
            
            file_put_contents($localPath, $response->getBody());
            
            Log::info("File downloaded successfully", ['remote' => $remotePath, 'local' => $localPath]);
            return true;

        } catch (Exception $e) {
            Log::error("Failed to download file", [
                'remote' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * List files in OneDrive directory
     */
    public function listFiles(string $remotePath = ''): array
    {
        try {
            $endpoint = $remotePath ? "/me/drive/root:/{$remotePath}:/children" : "/me/drive/root/children";
            $response = $this->graph->createRequest('GET', $endpoint)->execute();
            
            return $response->getValue() ?? [];

        } catch (Exception $e) {
            Log::error("Failed to list files", [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Delete file from OneDrive
     */
    public function deleteFile(string $remotePath): bool
    {
        try {
            $this->graph->createRequest('DELETE', "/me/drive/root:/{$remotePath}")
                ->execute();
                
            Log::info("File deleted successfully", ['path' => $remotePath]);
            return true;

        } catch (Exception $e) {
            Log::error("Failed to delete file", [
                'path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if file exists in OneDrive
     */
    public function fileExists(string $remotePath): bool
    {
        try {
            $this->graph->createRequest('GET', "/me/drive/root:/{$remotePath}")
                ->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}