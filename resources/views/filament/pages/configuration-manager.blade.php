<?php

return <<<'BLADE'
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        
        <div class="flex justify-end gap-x-3 mt-6">
            {{ $this->getFormActions() }}
        </div>
    </form>
    
    <x-filament-actions::modals />
</x-filament-panels::page>
BLADE;