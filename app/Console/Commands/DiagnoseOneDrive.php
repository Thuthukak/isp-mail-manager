<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Cache;

class DiagnoseOneDrive extends Command
{
    protected $signature = 'diagnose:onedrive {--user-id= : Check specific user ID}';
    protected $description = 'Diagnose OneDrive setup and find available users/sites';

    protected $accessToken;

    public function handle()
    {
        $this->info('🔍 Diagnosing OneDrive setup...');
        $this->newLine();

        try {
            // Step 1: Get access token
            $this->info('1. Getting access token...');
            $this->getAccessToken();
            $this->info('   ✅ Access token obtained successfully');
            $this->newLine();

            // Step 2: Check what permissions we have
            $this->info('2. Checking available permissions...');
            $this->checkPermissions();
            $this->newLine();

            // Step 3: List available users (if we have permission)
            $this->info('3. Checking available users...');
            $this->listUsers();
            $this->newLine();

            // Step 4: Check specific user if provided
            if ($userId = $this->option('user-id')) {
                $this->info("4. Checking specific user: {$userId}");
                $this->checkSpecificUser($userId);
                $this->newLine();
            }

            // Step 5: List available sites
            $this->info('5. Checking available SharePoint sites...');
            $this->listSites();
            $this->newLine();

            // Step 6: Check organization info
            $this->info('6. Checking organization info...');
            $this->checkOrganization();

        } catch (\Exception $e) {
            $this->error('❌ Diagnosis failed: ' . $e->getMessage());
            
            $this->newLine();
            $this->warn('This might indicate:');
            $this->warn('1. Missing permissions in Azure App Registration');
            $this->warn('2. Admin consent not granted');
            $this->warn('3. Incorrect tenant/client configuration');
        }
    }

    protected function getAccessToken()
    {
        $this->accessToken = Cache::get('onedrive_access_token');
        
        if (!$this->accessToken) {
            $guzzle = new GuzzleClient();
            
            $url = 'https://login.microsoftonline.com/' . config('onedrive.tenant_id') . '/oauth2/v2.0/token';
            
            $response = $guzzle->post($url, [
                'form_params' => [
                    'client_id' => config('onedrive.client_id'),
                    'client_secret' => config('onedrive.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            $this->accessToken = $tokenData['access_token'];
            
            Cache::put('onedrive_access_token', $this->accessToken, now()->addMinutes(55));
        }
    }

    protected function checkPermissions()
    {
        try {
            $guzzle = new GuzzleClient();
            
            // Try to access organization info - basic permission check
            $response = $guzzle->get('https://graph.microsoft.com/v1.0/organization', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $this->info('   ✅ Can access organization information');
            
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Limited organization access: ' . $e->getMessage());
        }
    }

    protected function listUsers()
    {
        try {
            $guzzle = new GuzzleClient();
            
            $response = $guzzle->get('https://graph.microsoft.com/v1.0/users?$top=10&$select=id,userPrincipalName,displayName', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!empty($data['value'])) {
                $this->info('   ✅ Found ' . count($data['value']) . ' users:');
                foreach ($data['value'] as $user) {
                    $this->line('   - ' . $user['userPrincipalName'] . ' (' . $user['displayName'] . ')');
                    $this->line('     ID: ' . $user['id']);
                }
                
                $this->newLine();
                $this->warn('💡 Use one of these user emails for ONEDRIVE_USER_ID in your .env file');
            } else {
                $this->warn('   ⚠️  No users found or insufficient permissions');
            }
            
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Cannot list users: ' . $e->getMessage());
            $this->warn('   This might be normal if you only have Files.ReadWrite.All permission');
        }
    }

    protected function checkSpecificUser($userId)
    {
        try {
            $guzzle = new GuzzleClient();
            
            // First check if user exists
            $userResponse = $guzzle->get("https://graph.microsoft.com/v1.0/users/{$userId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $userData = json_decode($userResponse->getBody()->getContents(), true);
            $this->info('   ✅ User exists: ' . $userData['displayName']);
            
            // Now check if we can access their drive
            try {
                $driveResponse = $guzzle->get("https://graph.microsoft.com/v1.0/users/{$userId}/drive", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json'
                    ]
                ]);
                
                $driveData = json_decode($driveResponse->getBody()->getContents(), true);
                $this->info('   ✅ Can access user\'s drive: ' . $driveData['id']);
                $this->info('   📁 Drive type: ' . ($driveData['driveType'] ?? 'unknown'));
                
            } catch (\Exception $e) {
                $this->error('   ❌ Cannot access user\'s drive: ' . $e->getMessage());
                $this->warn('   💡 The user might not have OneDrive enabled or you lack permissions');
            }
            
        } catch (\Exception $e) {
            $this->error('   ❌ User not found or cannot access: ' . $e->getMessage());
        }
    }

    protected function listSites()
    {
        try {
            $guzzle = new GuzzleClient();
            
            $response = $guzzle->get('https://graph.microsoft.com/v1.0/sites?$top=10', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!empty($data['value'])) {
                $this->info('   ✅ Found ' . count($data['value']) . ' SharePoint sites:');
                foreach ($data['value'] as $site) {
                    $this->line('   - ' . $site['name'] . ' (' . $site['webUrl'] . ')');
                    $this->line('     Site ID: ' . $site['id']);
                    
                    // Check if site has a drive
                    try {
                        $driveResponse = $guzzle->get("https://graph.microsoft.com/v1.0/sites/{$site['id']}/drive", [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $this->accessToken,
                                'Content-Type' => 'application/json'
                            ]
                        ]);
                        $this->line('     ✅ Has accessible drive');
                    } catch (\Exception $e) {
                        $this->line('     ❌ No accessible drive');
                    }
                }
                
                $this->newLine();
                $this->warn('💡 You can use a site ID for ONEDRIVE_SITE_ID instead of ONEDRIVE_USER_ID');
            } else {
                $this->warn('   ⚠️  No SharePoint sites found or insufficient permissions');
            }
            
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Cannot list sites: ' . $e->getMessage());
        }
    }

    protected function checkOrganization()
    {
        try {
            $guzzle = new GuzzleClient();
            
            $response = $guzzle->get('https://graph.microsoft.com/v1.0/organization', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!empty($data['value'])) {
                $org = $data['value'][0];
                $this->info('   ✅ Organization: ' . $org['displayName']);
                $domains = [];
                if (isset($org['verifiedDomains']) && is_array($org['verifiedDomains'])) {
                    foreach ($org['verifiedDomains'] as $domain) {
                        $domains[] = $domain['name'] ?? 'Unknown';
                    }
                }
                $domains = [];
                if (isset($org['verifiedDomains']) && is_array($org['verifiedDomains'])) {
                    foreach ($org['verifiedDomains'] as $domain) {
                        if (isset($domain['name'])) {
                            $domains[] = $domain['name'];
                        }
                    }
                }
                $this->info('   🌐 Domain: ' . implode(', ', $domains ?: ['Unknown']));
                $this->info('   🆔 Tenant ID: ' . $org['id']);
            }
            
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Cannot access organization info: ' . $e->getMessage());
        }
    }
}