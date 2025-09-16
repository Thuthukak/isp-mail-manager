<?php
require_once 'vendor/autoload.php';

use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\GraphServiceClient;

try {
    // Create a ClientCredentialContext for app-only authentication
    $tokenRequestContext = new ClientCredentialContext(
        'your_tenant_id',
        'your_client_id', 
        'your_client_secret'
    );
    
    $graph = new GraphServiceClient($tokenRequestContext);
    echo "GraphServiceClient loaded successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} catch (TypeError $e) {
    echo "TypeError: " . $e->getMessage() . "\n";
}
