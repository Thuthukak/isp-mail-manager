<?php

namespace App\Services;

use App\Models\MailboxAlert;
use App\Models\SyncConfiguration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class MailboxMonitorService
{
    private MailServerService $mailServerService;

    public function __construct(MailServerService $mailServerService)
    {
        $this->mailServerService = $mailServerService;
    }

    /**
     * Check all mailbox sizes and create alerts if needed
     */
    public function checkMailboxSizes(): array
    {
        $defaultThreshold = SyncConfiguration::getValue('mailbox_size_threshold_mb', 1000);
        $mailboxes = $this->mailServerService->getAllMailboxes();
        $alerts = [];

        foreach ($mailboxes as $mailbox) {
            $size = $this->mailServerService->getMailboxSize($mailbox);
            $threshold = $this->getMailboxThreshold($mailbox, $defaultThreshold);

            if ($size > $threshold) {
                $alert = $this->createOrUpdateAlert($mailbox, $size, $threshold);
                $alerts[] = $alert;
            } else {
                $this->resolveAlert($mailbox);
            }
        }

        Log::info("Mailbox size check completed", [
            'checked' => count($mailboxes),
            'alerts' => count($alerts)
        ]);

        return $alerts;
    }

    /**
     * Get threshold for specific mailbox
     */
    private function getMailboxThreshold(string $mailbox, float $default): float
    {
        return SyncConfiguration::getValue("mailbox_threshold_{$mailbox}", $default);
    }

    /**
     * Create or update alert for oversized mailbox
     */
    private function createOrUpdateAlert(string $mailbox, float $size, float $threshold): MailboxAlert
    {
        $existingAlert = MailboxAlert::where('mailbox', $mailbox)
            ->whereNull('resolved_at')
            ->first();

        if ($existingAlert) {
            $existingAlert->update([
                'size_mb' => $size,
                'threshold_mb' => $threshold
            ]);
            return $existingAlert;
        }

        $alert = MailboxAlert::create([
            'mailbox' => $mailbox,
            'size_mb' => $size,
            'threshold_mb' => $threshold,
            'alerted_at' => now()
        ]);

        // Send notification
        $this->sendSizeAlert($alert);

        return $alert;
    }

    /**
     * Resolve alert when mailbox size is back to normal
     */
    private function resolveAlert(string $mailbox): void
    {
        MailboxAlert::where('mailbox', $mailbox)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /**
     * Send size alert notification
     */
    private function sendSizeAlert(MailboxAlert $alert): void
    {
        // Implement notification logic here
        // This could be email, Slack, etc.
        Log::warning("Mailbox size alert", [
            'mailbox' => $alert->mailbox,
            'size' => $alert->size_mb,
            'threshold' => $alert->threshold_mb
        ]);
    }

    /**
     * Get active alerts
     */
    public function getActiveAlerts(): array
    {
        return MailboxAlert::whereNull('resolved_at')
            ->orderBy('alerted_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get mailbox size summary
     */
    public function getMailboxSizeSummary(): array
    {
        $mailboxes = $this->mailServerService->getAllMailboxes();
        $summary = [];

        foreach ($mailboxes as $mailbox) {
            $size = $this->mailServerService->getMailboxSize($mailbox);
            $threshold = $this->getMailboxThreshold($mailbox, 1000);
            
            $summary[] = [
                'mailbox' => $mailbox,
                'size_mb' => $size,
                'threshold_mb' => $threshold,
                'percentage_used' => round(($size / $threshold) * 100, 1),
                'status' => $size > $threshold ? 'alert' : 'normal'
            ];
        }

        return $summary;
    }
}