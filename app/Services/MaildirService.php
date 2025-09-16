<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class MaildirService
{
    /**
     * Create Maildir directory structure (new, cur, tmp)
     */
    public function createMaildirStructure(string $path): bool
    {
        try {
            $directories = ['new', 'cur', 'tmp'];
            
            foreach ($directories as $dir) {
                $fullPath = $path . '/' . $dir;
                if (!is_dir($fullPath)) {
                    if (!mkdir($fullPath, 0755, true)) {
                        throw new Exception("Failed to create directory: {$fullPath}");
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            Log::error("Failed to create Maildir structure", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Save an email message to Maildir format
     */
    public function saveEmailToMaildir($message, string $maildirPath): bool
    {
        try {
            // Generate unique filename for the email
            $filename = $this->generateMaildirFilename($message);
            
            // Determine if email is new or current (typically new emails go to 'cur' after processing)
            $subdir = $this->isNewEmail($message) ? 'new' : 'cur';
            $filePath = $maildirPath . '/' . $subdir . '/' . $filename;

            // Get email content in RFC822 format
            $emailContent = $this->getEmailRFC822Content($message);
            
            // Write email to file
            if (file_put_contents($filePath, $emailContent) === false) {
                throw new Exception("Failed to write email to file: {$filePath}");
            }

            // Set appropriate permissions
            chmod($filePath, 0644);
            
            Log::debug("Email saved to Maildir", [
                'filename' => $filename,
                'path' => $filePath,
                'size' => strlen($emailContent)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error("Failed to save email to Maildir", [
                'maildir_path' => $maildirPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate unique Maildir filename
     * Format: timestamp.pid_sequence.hostname:2,flags
     */
    private function generateMaildirFilename($message): string
    {
        $timestamp = Carbon::now()->timestamp;
        $pid = getmypid();
        $sequence = mt_rand(1000, 9999);
        $hostname = gethostname();
        
        // Get message flags (seen, replied, etc.)
        $flags = $this->getMessageFlags($message);
        
        return "{$timestamp}.{$pid}_{$sequence}.{$hostname}:2,{$flags}";
    }

    /**
     * Get message flags for Maildir filename
     */
    private function getMessageFlags($message): string
    {
        $flags = '';
        
        try {
            // Check various flags
            if ($message->hasFlag('seen') || $message->hasFlag('\\Seen')) {
                $flags .= 'S';
            }
            if ($message->hasFlag('answered') || $message->hasFlag('\\Answered')) {
                $flags .= 'R';
            }
            if ($message->hasFlag('flagged') || $message->hasFlag('\\Flagged')) {
                $flags .= 'F';
            }
            if ($message->hasFlag('deleted') || $message->hasFlag('\\Deleted')) {
                $flags .= 'T';
            }
            if ($message->hasFlag('draft') || $message->hasFlag('\\Draft')) {
                $flags .= 'D';
            }
        } catch (Exception $e) {
            Log::debug("Could not determine message flags", ['error' => $e->getMessage()]);
        }
        
        return $flags;
    }

    /**
     * Check if email should be considered "new"
     */
    private function isNewEmail($message): bool
    {
        try {
            // Consider email "new" if it doesn't have the \Seen flag
            return !($message->hasFlag('seen') || $message->hasFlag('\\Seen'));
        } catch (Exception $e) {
            // Default to current if we can't determine
            return false;
        }
    }

    /**
     * Get email content in RFC822 format
     */
    private function getEmailRFC822Content($message): string
    {
        try {
            // Try to get raw message first
            if (method_exists($message, 'getRawBody')) {
                return $message->getRawBody();
            }
            
            // If raw body not available, construct RFC822 format
            return $this->constructRFC822Format($message);
            
        } catch (Exception $e) {
            Log::warning("Failed to get raw email content, constructing RFC822", [
                'error' => $e->getMessage()
            ]);
            return $this->constructRFC822Format($message);
        }
    }

    /**
     * Construct RFC822 format email from message components
     */
    private function constructRFC822Format($message): string
    {
        $rfc822 = '';
        
        try {
            // Add headers
            $headers = $this->getMessageHeaders($message);
            foreach ($headers as $name => $value) {
                $rfc822 .= "{$name}: {$value}\r\n";
            }
            
            $rfc822 .= "\r\n"; // Empty line separating headers from body
            
            // Add body
            $body = $this->getMessageBody($message);
            $rfc822 .= $body;
            
        } catch (Exception $e) {
            Log::error("Failed to construct RFC822 format", ['error' => $e->getMessage()]);
            $rfc822 = "Error constructing email: " . $e->getMessage();
        }
        
        return $rfc822;
    }

    /**
     * Get message headers
     */
    private function getMessageHeaders($message): array
    {
        $headers = [];
        
        try {
            // Standard headers
            $headers['Date'] = $message->getDate()?->format('r') ?? date('r');
            $headers['From'] = $message->getFrom()?->toString() ?? '';
            $headers['To'] = $message->getTo()?->toString() ?? '';
            $headers['Subject'] = $message->getSubject() ?? '';
            $headers['Message-ID'] = $message->getMessageId() ?? '';
            
            // CC and BCC if available
            if ($cc = $message->getCc()) {
                $headers['Cc'] = $cc->toString();
            }
            if ($bcc = $message->getBcc()) {
                $headers['Bcc'] = $bcc->toString();
            }
            
            // Content type
            $headers['Content-Type'] = $this->getContentType($message);
            
            // Additional headers if available
            if (method_exists($message, 'getHeader')) {
                $additionalHeaders = ['Reply-To', 'Return-Path', 'X-Mailer', 'MIME-Version'];
                foreach ($additionalHeaders as $headerName) {
                    try {
                        if ($headerValue = $message->getHeader($headerName)) {
                            $headers[$headerName] = $headerValue;
                        }
                    } catch (Exception $e) {
                        // Ignore missing headers
                    }
                }
            }
            
        } catch (Exception $e) {
            Log::warning("Error getting message headers", ['error' => $e->getMessage()]);
        }
        
        return array_filter($headers); // Remove empty headers
    }

    /**
     * Get message body
     */
    private function getMessageBody($message): string
    {
        try {
            // Try to get HTML body first, then text body
            if ($htmlBody = $message->getHTMLBody()) {
                return $htmlBody;
            }
            
            if ($textBody = $message->getTextBody()) {
                return $textBody;
            }
            
            // If no body available, try to get body directly
            if (method_exists($message, 'getBody')) {
                return $message->getBody();
            }
            
            return '';
            
        } catch (Exception $e) {
            Log::warning("Error getting message body", ['error' => $e->getMessage()]);
            return "Error retrieving message body: " . $e->getMessage();
        }
    }

    /**
     * Get content type for the message
     */
    private function getContentType($message): string
    {
        try {
            if (method_exists($message, 'getContentType')) {
                return $message->getContentType();
            }
            
            // Default content type based on available content
            if ($message->getHTMLBody()) {
                return 'text/html; charset=utf-8';
            }
            
            return 'text/plain; charset=utf-8';
            
        } catch (Exception $e) {
            return 'text/plain; charset=utf-8';
        }
    }

    /**
     * Validate Maildir structure
     */
    public function validateMaildirStructure(string $path): bool
    {
        $requiredDirs = ['new', 'cur', 'tmp'];
        
        foreach ($requiredDirs as $dir) {
            if (!is_dir($path . '/' . $dir)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get statistics for a Maildir
     */
    public function getMaildirStats(string $path): array
    {
        $stats = [
            'new_emails' => 0,
            'cur_emails' => 0,
            'tmp_emails' => 0,
            'total_emails' => 0,
            'total_size_bytes' => 0
        ];

        if (!$this->validateMaildirStructure($path)) {
            return $stats;
        }

        $directories = ['new', 'cur', 'tmp'];
        
        foreach ($directories as $dir) {
            $dirPath = $path . '/' . $dir;
            $files = glob($dirPath . '/*');
            $count = 0;
            $size = 0;
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $count++;
                    $size += filesize($file);
                }
            }
            
            $stats[$dir . '_emails'] = $count;
            $stats['total_emails'] += $count;
            $stats['total_size_bytes'] += $size;
        }

        return $stats;
    }

    /**
     * Clean up temporary Maildir files older than specified time
     */
    public function cleanupTempFiles(string $maildirPath, int $olderThanHours = 24): int
    {
        $tmpPath = $maildirPath . '/tmp';
        $cutoffTime = time() - ($olderThanHours * 3600);
        $cleaned = 0;

        if (!is_dir($tmpPath)) {
            return 0;
        }

        $files = glob($tmpPath . '/*');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }

        Log::info("Cleaned up Maildir temp files", [
            'path' => $tmpPath,
            'files_cleaned' => $cleaned
        ]);

        return $cleaned;
    }
}