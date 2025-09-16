<?php

return [
    'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
    'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID', 'common'),
    'redirect_uri' => env('MICROSOFT_GRAPH_REDIRECT_URI'),
    'user_id' => env('ONEDRIVE_USER_ID', null),
    'root_folder' => env('ONEDRIVE_ROOT_FOLDER', 'ISP_Mail_Backups'),
    'upload_chunk_size' => env('ONEDRIVE_UPLOAD_CHUNK_SIZE', 10485760), // 10MB
    'max_retry_attempts' => env('ONEDRIVE_MAX_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('ONEDRIVE_RETRY_DELAY', 5),
    
    // Personal account specific scopes
    'scopes' => explode(' ', env('MICROSOFT_SCOPES', 'https://graph.microsoft.com/Files.ReadWrite https://graph.microsoft.com/User.Read offline_access')),
    
    // API endpoints for personal accounts
    'api_url' => 'https://graph.microsoft.com/v1.0',
    'auth_url' => 'https://login.microsoftonline.com/' . env('MICROSOFT_GRAPH_TENANT_ID', 'common') . '/oauth2/v2.0/authorize',
    'token_url' => 'https://login.microsoftonline.com/' . env('MICROSOFT_GRAPH_TENANT_ID', 'common') . '/oauth2/v2.0/token',
    
    // Personal OneDrive settings
    'drive_type' => env('ONEDRIVE_TYPE', 'personal'),
    'drive_id' => env('ONEDRIVE_DRIVE_ID', 'me/drive'),
];