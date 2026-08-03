<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionClass = config('permission.models.permission');
        $roleClass       = config('permission.models.role');

        

        // ── Permissions ───────────────────────────────────────────
        $permissions = [
            'dashboard.view',
            'customers.view', 'customers.export',
            'transactions.view', 'transactions.export', 'transactions.summary.view',
            'daily-report.view',
            'organizations.view', 'organizations.operators.view',
            'fraudes.view', 'fraudes.analyse',
            'operations.import-msisdn',
            'admin.users.view', 'admin.users.create', 'admin.users.edit', 'admin.users.delete',
            'admin.roles.view', 'admin.roles.manage',
            'admin.departments.view', 'admin.departments.manage',
        ];

        foreach ($permissions as $perm) {
            $permissionClass::firstOrCreate(
                ['name' => $perm],
                ['guard_name' => 'web']
            );
        }

        // ── Rôles ─────────────────────────────────────────────────

        $superAdmin = $roleClass::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );
        $superAdmin->syncPermissions($permissionClass::all());

        $directeur = $roleClass::firstOrCreate(
            ['name' => 'directrice'],
            ['guard_name' => 'web']
        );

        $directeur->syncPermissions([
            'dashboard.view',
            'customers.view', 'customers.export',
            'transactions.view', 'transactions.export', 'transactions.summary.view',
            'daily-report.view',
            'organizations.view', 'organizations.operators.view',
            'fraudes.view', 'fraudes.analyse',
            'operations.import-msisdn',
        ]);

        $analysteFinance = $roleClass::firstOrCreate(
            ['name' => 'analyste-finance'],
            ['guard_name' => 'web']
        );

        $analysteFinance->syncPermissions([
            'dashboard.view',
            'transactions.view', 'transactions.export', 'transactions.summary.view',
            'daily-report.view',
            'customers.view',
        ]);

        $analysteConformite = $roleClass::firstOrCreate(
            ['name' => 'analyste-conformite'],
            ['guard_name' => 'web']
        );
        $analysteConformite->syncPermissions([
            'dashboard.view',
            'transactions.view',
            'fraudes.view', 'fraudes.analyse',
            'customers.view',
            'organizations.view',
        ]);

        $agentOps = $roleClass::firstOrCreate(
            ['name' => 'agent-operations'],
            ['guard_name' => 'web']
        );

        $agentOps->syncPermissions([
            'dashboard.view',
            'customers.view',
            'operations.import-msisdn',
            'transactions.view',
        ]);

        $auditeur = $roleClass::firstOrCreate(
            ['name' => 'auditeur'],
            ['guard_name' => 'web']
        );
        
        $auditeur->syncPermissions([
            'dashboard.view',
            'customers.view',
            'transactions.view', 
            'transactions.summary.view',
            'daily-report.view',
            'organizations.view', 
            'organizations.operators.view',
            'fraudes.view',
        ]);

        $this->command->info('✓ Départements, rôles et permissions créés avec succès.');
    }
}