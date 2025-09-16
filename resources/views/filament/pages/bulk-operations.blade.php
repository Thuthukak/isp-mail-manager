<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Quick Actions will be handled by header actions -->
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Operation Status</h3>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>Queue Jobs Running:</span>
                        <span class="font-mono">{{ \App\Models\SyncLog::where('status', 'running')->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Last Operation:</span>
                        <span class="font-mono">{{ \App\Models\SyncLog::latest()->first()?->started_at?->diffForHumans() ?? 'Never' }}</span>
                    </div>
                </div>
            </x-filament::card>
            
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">System Resources</h3>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>Disk Usage:</span>
                        <span class="font-mono">{{ number_format(disk_free_space('/') / 1024 / 1024 / 1024, 1) }} GB Free</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Memory Usage:</span>
                        <span class="font-mono">{{ number_format(memory_get_usage() / 1024 / 1024, 1) }} MB</span>
                    </div>
                </div>
            </x-filament::card>
        </div>
    </div>
</x-filament-panels::page>