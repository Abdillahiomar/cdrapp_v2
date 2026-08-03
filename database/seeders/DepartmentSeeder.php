<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name'        => 'Direction',
                'code'        => 'DIR',
                'description' => 'Direction générale et gouvernance',
                'is_active'   => true,
            ],
            [
                'name'        => 'Finance',
                'code'        => 'FIN',
                'description' => 'Gestion financière, comptabilité et facturation',
                'is_active'   => true,
            ],
            [
                'name'        => 'Opérations',
                'code'        => 'OPS',
                'description' => 'Suivi des opérations et intégrations au quotidien',
                'is_active'   => true,
            ],
            [
                'name'        => 'Conformité',
                'code'        => 'COM',
                'description' => 'Gestion des risques, audits et conformité réglementaire',
                'is_active'   => true,
            ],
            [
                'name'        => 'Informatique',
                'code'        => 'IT',
                'description' => 'Support technique, développement et infrastructures',
                'is_active'   => true,
            ],
            [
                'name'        => 'Commercial',
                'code'        => 'MARCOM',
                'description' => 'Comminucation, Marketing',
                'is_active'   => true,
            ],
            [
                'name'        => 'Réseau de Distribution',
                'code'        => 'RD',
                'description' => 'Relations clients, partenariats et ventes',
                'is_active'   => true,
            ],
        ];

        foreach ($departments as $dept) {
            // L'utilisation de firstOrCreate évite les doublons si vous lancez le seeder plusieurs fois
            Department::firstOrCreate(
                ['code' => $dept['code']], // Condition d'unicité basée sur le code
                [
                    'name'        => $dept['name'],
                    'description' => $dept['description'],
                    'slug' => $dept['code'],
                    'is_active'   => $dept['is_active'],
                ]
            );
        }

        $this->command->info('✓ Table departments remplie avec succès.');
    }
}