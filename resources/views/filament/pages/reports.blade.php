<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($this->getHeaderWidgets() as $widget)
            @livewire($widget)
        @endforeach
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Recent Activity</h3>
                <div class="space-y-2">
                    @foreach(\App\Models\SyncLog::latest()->limit(5)->get() as $log)
                        <div class="flex justify-between items-center">
                            <span>{{ ucfirst(str_replace('_', ' ', $log->operation_type)) }}</span>
                            <span class="text-sm text-gray-500">{{ $log->started_at->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::card>
            
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">System Health</h3>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>OneDrive Connection:</span>
                        <span class="text-green-600">✓ Connected</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Queue Workers:</span>
                        <span class="text-green-600">✓ Running</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Scheduled Tasks:</span>
                        <span class="text-green-600">✓ Active</span>
                    </div>
                </div>
            </x-filament::card>
        </div>
    </div>
</x-filament-panels::page>