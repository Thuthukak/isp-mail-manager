<?php

namespace Database\seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Seeder; 
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // User management
            'manage_users',
            'view_users',
            'create_users',
            'edit_users',
            'delete_users',
            
            // Mail backup management
            'manage_backups',
            'view_backups',
            'create_backups',
            'restore_backups',
            'delete_backups',
            
            // Mail restoration
            'manage_restorations',
            'view_restorations',
            'request_restoration',
            
            // Mailbox monitoring
            'manage_mailbox_monitoring',
            'view_mailbox_alerts',
            'resolve_mailbox_alerts',
            
            // System configuration
            'manage_system_config',
            'view_system_config',
            'edit_system_config',
            
            // Sync operations
            'manage_sync_operations',
            'view_sync_logs',
            'execute_sync_commands',
            'force_sync',
            
            // Reports and analytics
            'view_reports',
            'view_analytics',
            'export_reports',
            
            // System monitoring
            'view_system_health',
            'view_queue_status',
            'manage_queue_jobs',
            
            // Purge operations
            'manage_purge_operations',
            'execute_purge',
            'view_purge_history',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Super Admin - Full access
        $superAdmin = Role::create(['name' => 'super_admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // Admin - Management access
        $admin = Role::create(['name' => 'admin']);
        $admin->givePermissionTo([
            'view_users',
            'create_users',
            'edit_users',
            'manage_backups',
            'view_backups',
            'create_backups',
            'restore_backups',
            'manage_restorations',
            'view_restorations',
            'request_restoration',
            'manage_mailbox_monitoring',
            'view_mailbox_alerts',
            'resolve_mailbox_alerts',
            'view_system_config',
            'edit_system_config',
            'manage_sync_operations',
            'view_sync_logs',
            'execute_sync_commands',
            'force_sync',
            'view_reports',
            'view_analytics',
            'export_reports',
            'view_system_health',
            'view_queue_status',
            'manage_purge_operations',
            'execute_purge',
            'view_purge_history',
        ]);

        // Operator - Operational access
        $operator = Role::create(['name' => 'operator']);
        $operator->givePermissionTo([
            'view_backups',
            'create_backups',
            'restore_backups',
            'view_restorations',
            'request_restoration',
            'view_mailbox_alerts',
            'resolve_mailbox_alerts',
            'view_sync_logs',
            'execute_sync_commands',
            'view_reports',
            'view_system_health',
            'view_queue_status',
            'execute_purge',
            'view_purge_history',
        ]);

        // Viewer - Read-only access
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo([
            'view_backups',
            'view_restorations',
            'view_mailbox_alerts',
            'view_sync_logs',
            'view_reports',
            'view_system_health',
            'view_queue_status',
            'view_purge_history',
        ]);

        // Create default super admin user
        $superAdminUser = User::create([
            'name' => 'Super Administrator',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $superAdminUser->assignRole('super_admin');

        // Create default admin user
        $adminUser = User::create([
            'name' => 'System Administrator',
            'email' => 'sysadmin@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $adminUser->assignRole('admin');

        $this->command->info('Roles and permissions created successfully!');
        $this->command->info('Super Admin: admin@example.com / password');
        $this->command->info('Admin: sysadmin@example.com / password');
    }
}