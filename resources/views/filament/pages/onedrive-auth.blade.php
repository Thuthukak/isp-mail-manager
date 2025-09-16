<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Authentication Status Card -->
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Authentication Status
                </h2>
                
                <!-- Status Badge -->
                <div class="flex items-center mb-4">
                    @if($authStatus['authenticated'])
                        <div class="flex items-center text-green-600 dark:text-green-400">
                            <x-heroicon-s-check-circle class="w-5 h-5 mr-2" />
                            <span class="font-medium">Connected</span>
                        </div>
                    @else
                        <div class="flex items-center text-red-600 dark:text-red-400">
                            <x-heroicon-s-x-circle class="w-5 h-5 mr-2" />
                            <span class="font-medium">Not Connected</span>
                        </div>
                    @endif
                </div>

                <!-- Status Message -->
                <div class="mb-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $authStatus['message'] }}
                    </p>
                </div>

                @if(isset($authStatus['token_info']))
                    <!-- Token Information -->
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">Token Information</h3>
                        <div class="space-y-1 text-sm">
                            @if($authStatus['token_info']['expires_at'])
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Expires:</span>
                                    <span class="{{ $authStatus['token_info']['expires_soon'] ? 'text-orange-600 dark:text-orange-400 font-medium' : 'text-gray-900 dark:text-gray-100' }}">
                                        {{ $authStatus['token_info']['expires_at'] }}
                                        @if($authStatus['token_info']['expires_soon'])
                                            (Soon!)
                                        @endif
                                    </span>
                                </div>
                            @endif
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Refresh Token:</span>
                                <span class="text-gray-900 dark:text-gray-100">
                                    {{ $authStatus['token_info']['has_refresh_token'] ? 'Available' : 'Not Available' }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Account Information Card -->
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Account Information
                </h2>
                
                @if(isset($authStatus['user_info']))
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3">
                            <x-heroicon-s-user class="w-5 h-5 text-gray-400" />
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $authStatus['user_info']['display_name'] }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $authStatus['user_info']['email'] }}
                                </p>
                            </div>
                        </div>

                        @if(isset($authStatus['drive_info']))
                            <div class="flex items-center space-x-3">
                                <x-heroicon-s-cloud class="w-5 h-5 text-gray-400" />
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $authStatus['drive_info']['name'] }}
                                    </p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ ucfirst($authStatus['drive_info']['drive_type']) }} Drive
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex items-center justify-center py-8">
                        <div class="text-center">
                            <!-- Using x-circle instead of cloud-slash since it doesn't exist -->
                            <x-heroicon-s-x-circle class="w-12 h-12 text-gray-400 mx-auto mb-2" />
                            <p class="text-gray-600 dark:text-gray-400">No account information available</p>
                            <p class="text-sm text-gray-500 dark:text-gray-500">Please authenticate to view details</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Storage Usage Card -->
        @if(isset($authStatus['storage_usage']))
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg lg:col-span-2">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                        OneDrive Storage Usage
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Usage Statistics -->
                        <div class="space-y-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Used Space</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $authStatus['storage_usage']['used_formatted'] }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Space</p>
                                <p class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    {{ $authStatus['storage_usage']['total_formatted'] }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Available Space</p>
                                <p class="text-lg font-medium text-green-600 dark:text-green-400">
                                    {{ $authStatus['storage_usage']['remaining_formatted'] }}
                                </p>
                            </div>
                        </div>

                        <!-- Usage Progress Bar -->
                        <div class="md:col-span-2">
                            <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                                <span>Storage Usage</span>
                                <span>{{ $authStatus['storage_usage']['usage_percentage'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                                <div 
                                    class="h-3 rounded-full transition-all duration-300 {{ $authStatus['storage_usage']['usage_percentage'] > 80 ? 'bg-red-500' : ($authStatus['storage_usage']['usage_percentage'] > 60 ? 'bg-yellow-500' : 'bg-blue-500') }}"
                                    style="width: {{ min($authStatus['storage_usage']['usage_percentage'], 100) }}%"
                                ></div>
                            </div>
                            <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                @if($authStatus['storage_usage']['usage_percentage'] > 90)
                                    <div class="flex items-center text-red-600 dark:text-red-400">
                                        <x-heroicon-s-exclamation-triangle class="w-4 h-4 mr-1" />
                                        Storage almost full - consider cleaning up files
                                    </div>
                                @elseif($authStatus['storage_usage']['usage_percentage'] > 80)
                                    <div class="flex items-center text-yellow-600 dark:text-yellow-400">
                                        <x-heroicon-s-exclamation-triangle class="w-4 h-4 mr-1" />
                                        High storage usage - monitor space carefully
                                    </div>
                                @else
                                    <div class="flex items-center text-green-600 dark:text-green-400">
                                        <x-heroicon-s-check-circle class="w-4 h-4 mr-1" />
                                        Plenty of storage space available
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Instructions Card -->
        @if(!$authStatus['authenticated'])
            <div class="bg-blue-50 dark:bg-blue-900/20 overflow-hidden shadow-sm rounded-lg lg:col-span-2">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-4">
                        <x-heroicon-s-information-circle class="w-5 h-5 inline mr-2" />
                        Setup Instructions
                    </h2>
                    
                    <div class="space-y-4 text-sm text-blue-800 dark:text-blue-200">
                        <div class="flex items-start space-x-3">
                            <span class="flex-shrink-0 w-6 h-6 bg-blue-200 dark:bg-blue-700 text-blue-800 dark:text-blue-200 rounded-full flex items-center justify-center text-xs font-medium">1</span>
                            <p>Click the "Authenticate with OneDrive" button above to start the authentication process.</p>
                        </div>
                        <div class="flex items-start space-x-3">
                            <span class="flex-shrink-0 w-6 h-6 bg-blue-200 dark:bg-blue-700 text-blue-800 dark:text-blue-200 rounded-full flex items-center justify-center text-xs font-medium">2</span>
                            <p>You will be redirected to Microsoft's login page. Sign in with your Microsoft account that has OneDrive access.</p>
                        </div>
                        <div class="flex items-start space-x-3">
                            <span class="flex-shrink-0 w-6 h-6 bg-blue-200 dark:bg-blue-700 text-blue-800 dark:text-blue-200 rounded-full flex items-center justify-center text-xs font-medium">3</span>
                            <p>Grant the necessary permissions for the application to access your OneDrive storage.</p>
                        </div>
                        <div class="flex items-start space-x-3">
                            <span class="flex-shrink-0 w-6 h-6 bg-blue-200 dark:bg-blue-700 text-blue-800 dark:text-blue-200 rounded-full flex items-center justify-center text-xs font-medium">4</span>
                            <p>You will be redirected back to this page with a successful connection status.</p>
                        </div>
                    </div>

                    <div class="mt-4 p-4 bg-blue-100 dark:bg-blue-800/30 rounded-lg">
                        <p class="text-xs text-blue-700 dark:text-blue-300">
                            <strong>Note:</strong> Only administrators can manage OneDrive authentication. This connection will be used for all email backup operations in your organization.
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>