<?php

return [
    'client_id' => env('ONEDRIVE_CLIENT_ID'),
    'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
    'tenant_id' => env('ONEDRIVE_TENANT_ID'),
    'drive_id' => env('ONEDRIVE_DRIVE_ID', 'me/drive'),
];