<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Authentication Status Card -->
        <x-filament::card>
            <div class="space-y-4">
                <h2 class="text-lg font-medium">
                    Authentication Status
                </h2>
                
                <!-- Status Badge -->
                <div class="flex items-center">
                    @if($authStatus['authenticated'])
                        <div class="flex items-center text-success-600">
                            <x-heroicon-s-check-circle class="w-5 h-5 mr-2" />
                            <span class="font-medium">Connected</span>
                        </div>
                    @else
                        <div class="flex items-center text-danger-600">
                            <x-heroicon-s-x-circle class="w-5 h-5 mr-2" />
                            <span class="font-medium">Not Connected</span>
                        </div>
                    @endif
                </div>

                <!-- Status Message -->
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $authStatus['message'] }}
                    </p>
                </div>

                @if(isset($authStatus['token_info']))
                    <!-- Token Information -->
                    <div class="border-t pt-4">
                    <div class="space-y-2">
                        <h3 class="text-sm font-medium">Token Information</h3>
                        <div class="space-y-1 text-sm">
                            @if($authStatus['token_info']['expires_at'])
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Expires:</span>
                                    <span class="{{ $authStatus['token_info']['expires_soon'] ? 'text-warning-600 font-medium' : '' }}">
                                        {{ $authStatus['token_info']['expires_at'] }}
                                        @if($authStatus['token_info']['expires_soon'])
                                            (Soon!)
                                        @endif
                                    </span>
                                </div>
                            @endif
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Refresh Token:</span>
                                <span>
                                    {{ $authStatus['token_info']['has_refresh_token'] ? 'Available' : 'Not Available' }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::card>

        <!-- Account Information Card -->
        <x-filament::card>
            <div class="space-y-4">
                <h2 class="text-lg font-medium">
                    Account Information
                </h2>
                
                @if(isset($authStatus['user_info']))
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3">
                            <x-heroicon-s-user class="w-5 h-5 text-gray-500" />
                            <div>
                                <p class="font-medium">
                                    {{ $authStatus['user_info']['display_name'] }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $authStatus['user_info']['email'] }}
                                </p>
                            </div>
                        </div>

                        @if(isset($authStatus['drive_info']))
                            <div class="flex items-center space-x-3">
                                <x-heroicon-s-cloud class="w-5 h-5 text-gray-500" />
                                <div>
                                    <p class="font-medium">
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
                            <x-heroicon-s-x-circle class="w-12 h-12 text-gray-400 mx-auto mb-2" />
                            <p class="text-gray-600 dark:text-gray-400">No account information available</p>
                            <p class="text-sm text-gray-500 dark:text-gray-500">Please authenticate to view details</p>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::card>

        <!-- Storage Usage Card -->
        @if(isset($authStatus['storage_usage']))
            <div class="lg:col-span-2">
                <x-filament::card>
                    <div class="space-y-4">
                        <h2 class="text-lg font-medium">
                            OneDrive Storage Usage
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Usage Statistics -->
                            <div class="space-y-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Used Space</p>
                                    <p class="text-2xl font-bold">
                                        {{ $authStatus['storage_usage']['used_formatted'] }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Space</p>
                                    <p class="text-lg font-medium">
                                        {{ $authStatus['storage_usage']['total_formatted'] }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Available Space</p>
                                    <p class="text-lg font-medium text-success-600">
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
                                        class="h-3 rounded-full transition-all duration-300 {{ $authStatus['storage_usage']['usage_percentage'] > 80 ? 'bg-danger-500' : ($authStatus['storage_usage']['usage_percentage'] > 60 ? 'bg-warning-500' : 'bg-primary-500') }}"
                                        style="width: {{ min($authStatus['storage_usage']['usage_percentage'], 100) }}%"
                                    ></div>
                                </div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    @if($authStatus['storage_usage']['usage_percentage'] > 90)
                                        <div class="flex items-center text-danger-600">
                                            <x-heroicon-s-exclamation-triangle class="w-4 h-4 mr-1" />
                                            Storage almost full - consider cleaning up files
                                        </div>
                                    @elseif($authStatus['storage_usage']['usage_percentage'] > 80)
                                        <div class="flex items-center text-warning-600">
                                            <x-heroicon-s-exclamation-triangle class="w-4 h-4 mr-1" />
                                            High storage usage - monitor space carefully
                                        </div>
                                    @else
                                        <div class="flex items-center text-success-600">
                                            <x-heroicon-s-check-circle class="w-4 h-4 mr-1" />
                                            Plenty of storage space available
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::card>
            </div>
        @endif

        <!-- Instructions Card -->
        @if(!$authStatus['authenticated'])
            <div class="lg:col-span-2">
                <x-filament::card>
                    <div class="space-y-4">
                        <h2 class="text-lg font-medium text-primary-600">
                            <x-heroicon-s-information-circle class="w-5 h-5 inline mr-2" />
                            Setup Instructions
                        </h2>
                        
                        <div class="space-y-4 text-sm">
                            <div class="flex items-start space-x-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary-100 text-primary-800 rounded-full flex items-center justify-center text-xs font-medium">1</span>
                                <p>Ensure that you have configured your Microsoft Azure Application ID and Client Secret in <a href="{{ \App\Filament\Resources\SyncConfigurationResource::getUrl('index') }}" class="text-primary-600 hover:underline">Settings</a>. </p>
                            </div>
                            <div class="flex items-start space-x-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary-100 text-primary-800 rounded-full flex items-center justify-center text-xs font-medium">2</span>
                                <p>Click the "Authenticate with OneDrive" button above to start the authentication process.</p>
                            </div>
                            <div class="flex items-start space-x-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary-100 text-primary-800 rounded-full flex items-center justify-center text-xs font-medium">3</span>
                                <p>You will be redirected to Microsoft's login page. Sign in with your Microsoft account that has OneDrive access.</p>
                            </div>
                            <div class="flex items-start space-x-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary-100 text-primary-800 rounded-full flex items-center justify-center text-xs font-medium">4</span>
                                <p>Grant the necessary permissions for the application to access your OneDrive storage.</p>
                            </div>
                            <div class="flex items-start space-x-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary-100 text-primary-800 rounded-full flex items-center justify-center text-xs font-medium">5</span>
                                <p>You will be redirected back to this page with a successful connection status.</p>
                            </div>
                        </div>

                        <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border">
                            <p class="text-xs text-gray-700 dark:text-gray-300">
                                <strong>Note:</strong> Only administrators can manage OneDrive authentication. This connection will be used for all email backup operations in your organization.
                            </p>
                        </div>
                    </div>
                </x-filament::card>
            </div>
        @endif
    </div>
</x-filament-panels::page>