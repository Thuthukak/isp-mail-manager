<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;
use App\Models\MailBackup;
use App\Models\BackupJob;

class EmailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'username',
        'password',
        'imap_host',
        'imap_port',
        'imap_ssl',
        'department',
        'employee_name',
        'active',
        'last_backup'
    ];

    protected $casts = [
        'imap_port' => 'integer',
        'imap_ssl' => 'boolean',
        'active' => 'boolean',
        'last_backup' => 'datetime',
    ];

    protected $hidden = [
        'password', // Hide password from JSON serialization
    ];

    // Relationship: One EmailAccount has many MailBackups
    public function mailBackups(): HasMany
    {
        return $this->hasMany(MailBackup::class);
    }

    public function backupJobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }

    // Scopes for filtering
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('active', false);
    }

    public function scopeByDepartment($query, string $department)
    {
        return $query->where('department', $department);
    }

    // Accessor for encrypted password (if you want to encrypt passwords)
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCompletedBackupsCount(): int
    {
        return $this->mailBackups()->completed()->count();
    }

    public function getFailedBackupsCount(): int
    {
        return $this->mailBackups()->failed()->count();
    }

    public function getPendingBackupsCount(): int
    {
        return $this->mailBackups()->pending()->count();
    }

    public function getLastBackupDate(): ?string
    {
        return $this->last_backup?->format('Y-m-d H:i:s');
    }

    public function updateLastBackupDate(): void
    {
        $this->update(['last_backup' => now()]);
    }

    // Get IMAP connection string
    public function getImapConnectionString(): string
    {
        $ssl = $this->imap_ssl ? '/ssl' : '';
        return "{{$this->imap_host}:{$this->imap_port}{$ssl}}";
    }

    // Check if account needs backup (example: daily backup)
    public function needsBackup(): bool
    {
        if (!$this->last_backup) {
            return true;
        }
        
        return $this->last_backup->diffInHours(now()) >= 24;
    }

    // Get recent backups (last 30 days)
    public function recentBackups(): HasMany
    {
        return $this->mailBackups()
            ->where('backup_date', '>=', now()->subDays(30))
            ->orderBy('backup_date', 'desc');
    }
}