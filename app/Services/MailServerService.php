<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MailServerService
{
    protected $connection;
    protected $config;

    public function __construct()
    {
        $this->config = config('mail-backup.mail_server');
    }

    public function connect()
    {
        $serverType = $this->config['type'];
        
        switch ($serverType) {
            case 'imap':
                return $this->connectImap();
            case 'pop3':
                return $this->connectPop3();
            case 'local':
                return $this->connectLocal();
            default:
                throw new \Exception("Unsupported mail server type: {$serverType}");
        }
    }

    protected function connectImap()
    {
        $host = $this->config['host'];
        $port = $this->config['port'];
        $encryption = $this->config['encryption'];
        $username = $this->config['username'];
        $password = $this->config['password'];
        
        $flags = '';
        if ($encryption === 'ssl') {
            $flags = '/ssl';
        } elseif ($encryption === 'tls') {
            $flags = '/tls';
        }
        
        $mailbox = "{{$host}:{$port}{$flags}}";
        
        $this->connection = imap_open($mailbox, $username, $password);
        
        if (!$this->connection) {
            $error = imap_last_error();
            throw new \Exception("IMAP connection failed: {$error}");
        }
        
        Log::info('IMAP connection established', ['host' => $host]);
        return $this->connection;
    }

    protected function connectPop3()
    {
        // POP3 connection implementation
        throw new \Exception("POP3 support not yet implemented");
    }

    protected function connectLocal()
    {
        $path = $this->config['local_path'] ?? config('mail-backup.storage.local_path');
        
        if (!is_dir($path)) {
            throw new \Exception("Mail storage directory not found: {$path}");
        }
        
        if (!is_readable($path)) {
            throw new \Exception("Mail storage directory not readable: {$path}");
        }
        
        $this->connection = $path;
        Log::info('Local mail storage connected', ['path' => $path]);
        
        return $this->connection;
    }

    public function getMailboxes()
    {
        if ($this->config['type'] === 'local') {
            return $this->getLocalMailboxes();
        }
        
        if (!$this->connection) {
            $this->connect();
        }
        
        $mailboxes = imap_list($this->connection, "{{$this->config['host']}}", "*");
        
        if (!$mailboxes) {
            return [];
        }
        
        // Clean up mailbox names
        return array_map(function($mailbox) {
            return str_replace("{{$this->config['host']}}", '', $mailbox);
        }, $mailboxes);
    }

    protected function getLocalMailboxes()
    {
        $path = $this->connection;
        $mailboxes = [];
        
        $directories = glob($path . '/*', GLOB_ONLYDIR);
        
        foreach ($directories as $directory) {
            $mailboxes[] = basename($directory);
        }
        
        // Also check for individual mail files in the root
        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'mbox') {
                $mailboxes[] = basename($file, '.mbox');
            }
        }
        
        return array_unique($mailboxes);
    }

    public function getMailboxSize($mailbox)
    {
        if ($this->config['type'] === 'local') {
            return $this->getLocalMailboxSize($mailbox);
        }
        
        if (!$this->connection) {
            $this->connect();
        }
        
        imap_reopen($this->connection, "{{$this->config['host']}}{$mailbox}");
        $info = imap_mailboxmsginfo($this->connection);
        
        return round($info->Size / (1024 * 1024), 2); // Size in MB
    }

    protected function getLocalMailboxSize($mailbox)
    {
        $path = $this->connection;
        $totalSize = 0;
        
        // Check for directory
        $mailboxDir = $path . '/' . $mailbox;
        if (is_dir($mailboxDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mailboxDir)
            );
            
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                }
            }
        }
        
        // Check for single file
        $mailboxFile = $path . '/' . $mailbox . '.mbox';
        if (is_file($mailboxFile)) {
            $totalSize += filesize($mailboxFile);
        }
        
        return round($totalSize / (1024 * 1024), 2); // Size in MB
    }

    public function getMailFiles($mailbox, $limit = null)
    {
        if ($this->config['type'] === 'local') {
            return $this->getLocalMailFiles($mailbox, $limit);
        }
        
        if (!$this->connection) {
            $this->connect();
        }
        
        imap_reopen($this->connection, "{{$this->config['host']}}{$mailbox}");
        $messageCount = imap_num_msg($this->connection);
        
        if ($limit) {
            $messageCount = min($messageCount, $limit);
        }
        
        $messages = [];
        for ($i = 1; $i <= $messageCount; $i++) {
            $header = imap_headerinfo($this->connection, $i);
            $messages[] = [
                'id' => $i,
                'subject' => $header->subject ?? 'No Subject',
                'from' => $header->from[0]->mailbox . '@' . $header->from[0]->host,
                'date' => $header->date,
                'size' => imap_fetchstructure($this->connection, $i)->bytes,
            ];
        }
        
        return $messages;
    }

    protected function getLocalMailFiles($mailbox, $limit = null)
    {
        $path = $this->connection;
        $files = [];
        
        // Handle directory-based mailbox
        $mailboxDir = $path . '/' . $mailbox;
        if (is_dir($mailboxDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mailboxDir)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = [
                        'path' => $file->getPathname(),
                        'name' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                    ];
                }
                
                if ($limit && count($files) >= $limit) {
                    break;
                }
            }
        }
        
        // Handle single mbox file
        $mailboxFile = $path . '/' . $mailbox . '.mbox';
        if (is_file($mailboxFile)) {
            $files[] = [
                'path' => $mailboxFile,
                'name' => basename($mailboxFile),
                'size' => filesize($mailboxFile),
                'modified' => filemtime($mailboxFile),
            ];
        }
        
        return $files;
    }

    public function exportMailbox($mailbox, $exportPath)
    {
        if ($this->config['type'] === 'local') {
            return $this->exportLocalMailbox($mailbox, $exportPath);
        }
        
        // For IMAP, we need to fetch all messages and save them
        throw new \Exception("IMAP mailbox export not yet implemented");
    }

    protected function exportLocalMailbox($mailbox, $exportPath)
    {
        $sourcePath = $this->connection . '/' . $mailbox;
        
        if (is_dir($sourcePath)) {
            // Copy entire directory
            return $this->copyDirectory($sourcePath, $exportPath);
        }
        
        $sourceFile = $this->connection . '/' . $mailbox . '.mbox';
        if (is_file($sourceFile)) {
            // Copy single file
            return copy($sourceFile, $exportPath);
        }
        
        throw new \Exception("Mailbox not found: {$mailbox}");
    }

    protected function copyDirectory($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $destination . '/' . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                mkdir($destPath, 0755, true);
            } else {
                copy($item, $destPath);
            }
        }
        
        return true;
    }

    public function disconnect()
    {
        if ($this->connection && $this->config['type'] !== 'local') {
            imap_close($this->connection);
        }
        
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}