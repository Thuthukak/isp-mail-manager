{{-- resources/views/filament/widgets/backup-job-details.blade.php --}}
<div class="prose prose-sm max-w-none">
    {!! $content !!}
</div>

<style>
    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 0.25rem;
        text-transform: uppercase;
    }
    
    .badge-completed {
        background-color: #dcfce7;
        color: #15803d;
    }
    
    .badge-failed {
        background-color: #fee2e2;
        color: #dc2626;
    }
    
    .badge-running {
        background-color: #dbeafe;
        color: #2563eb;
    }
    
    .badge-pending {
        background-color: #f3f4f6;
        color: #6b7280;
    }
</style>