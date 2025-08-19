<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasRoles, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'locked_until',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * Activity log configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Check if user can access Filament panel
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->locked_until && $this->locked_until->isFuture()) {
            return false;
        }

        // Check if user has any role that allows panel access
        return $this->hasAnyRole(['super_admin', 'admin', 'operator', 'viewer']);
    }

    /**
     * Check if account is locked
     */
    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Lock the user account
     */
    public function lock(int $minutes = 30): void
    {
        $this->update([
            'locked_until' => now()->addMinutes($minutes)
        ]);
    }

    /**
     * Unlock the user account
     */
    public function unlock(): void
    {
        $this->update([
            'locked_until' => null,
            'failed_login_attempts' => 0
        ]);
    }

    /**
     * Increment failed login attempts
     */
    public function incrementFailedAttempts(): void
    {
        $attempts = $this->failed_login_attempts + 1;
        
        $this->update([
            'failed_login_attempts' => $attempts
        ]);

        // Lock account after 5 failed attempts
        if ($attempts >= 5) {
            $this->lock(30); // Lock for 30 minutes
        }
    }

    /**
     * Reset failed login attempts
     */
    public function resetFailedAttempts(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'last_login_at' => now()
        ]);
    }

    /**
     * Check if user has super admin role
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Check if user has admin role
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * Check if user can manage users
     */
    public function canManageUsers(): bool
    {
        return $this->hasPermissionTo('manage_users');
    }

    /**
     * Check if user can manage mail backups
     */
    public function canManageBackups(): bool
    {
        return $this->hasPermissionTo('manage_backups');
    }

    /**
     * Check if user can view reports
     */
    public function canViewReports(): bool
    {
        return $this->hasPermissionTo('view_reports');
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for locked users
     */
    public function scopeLocked($query)
    {
        return $query->where('locked_until', '>', now());
    }
}