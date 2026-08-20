<?php
// database/seeders/UserSeeder.php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Mot de passe par defaut (hashe automatiquement par le cast 'hashed')
        $defaultPassword = 'Dmoney@2026';

        $employees = [
            ['name' => 'Super Admin',                     'email' => 'super.admin@d-money.dj'],
            ['name' => 'Hibo Mahamoud',                   'email' => 'hibo.mahamoud@d-money.dj'],
            ['name' => 'Bogoreh Yacin Abdi',              'email' => 'bogoreh.yacin@d-money.dj'],
            ['name' => 'Amal Abdourahman Abib',           'email' => 'amal.abdourahman@d-money.dj'],
            ['name' => 'Djibril Djama Obsieh',            'email' => 'djibril.djama@d-money.dj'],
            ['name' => 'Mohamed Salah Elmi',              'email' => 'mohamed.salah@d-money.dj'],
            ['name' => 'Mahdi Salah Toubeh',              'email' => 'mahdi.salah@d-money.dj'],
            ['name' => 'Abdourahman Abdoulhakim Mohamed', 'email' => 'abdourahman.abdoulhakim@d-money.dj'],
            ['name' => 'Elmi Ahmed Abdi',                 'email' => 'elmi.ahmed@d-money.dj'],
            ['name' => 'Abdillahi Omar Dirieh',           'email' => 'abdillahi.omar@d-money.dj'],
            ['name' => 'Hodan Abdi Osman',                'email' => 'hodan.abdi@d-money.dj'],
            ['name' => 'Salem Ahmed Mohamed',             'email' => 'salem.ahmed@d-money.dj'],
            ['name' => 'Deka Ahmed Amin',                 'email' => 'deka.ahmed@d-money.dj'],
            ['name' => 'Abdourahman Omar Assoweh',        'email' => 'abdourahman.omar@d-money.dj'],
            ['name' => 'Samatar Kassim Abdillahi',        'email' => 'samatar.kassim@d-money.dj'],
            ['name' => 'Yasmin Abdallah Said',            'email' => 'yasmin.abdallah@d-money.dj'],
            ['name' => 'Maguida Mohamed Hagayo',          'email' => 'maguida.mohamed@d-money.dj'],
            ['name' => 'Moustapha Djama Hassan',          'email' => 'moustapha.djama@d-money.dj'],
            ['name' => 'Dayibo Osman Moussa',             'email' => 'dayibo.osman@d-money.dj'],
            ['name' => 'Saredo Ali Houssein',             'email' => 'saredo.ali@d-money.dj'],
            ['name' => 'Fozi Ali Batoum',                 'email' => 'fozzi.ali@d-money.dj'],
            ['name' => 'Nasra Hassan Waberi',             'email' => 'nasra.hassan@d-money.dj'],
            ['name' => 'Awo Mohamed Kayad',               'email' => 'awo.mohamed@d-money.dj'],
            ['name' => 'Iman Mohamed Abdourahman',        'email' => 'iman.mohamed@d-money.dj'],
            ['name' => 'Abdoulfatah Moussa Doualeh',      'email' => 'abdoulfatah.moussa@d-money.dj'],
            ['name' => 'Fathia Ismael Hassan',            'email' => 'fathia.ismael@d-money.dj'],
            ['name' => 'Liban Ahmed Doubad',              'email' => 'liban.ahmed@d-money.dj'],
            ['name' => 'Abdoulkarim Daher Abdi',          'email' => 'abdoulkarim.daher@d-money.dj'],
            ['name' => 'Amin Hassan Douale',              'email' => 'amin.hassan@d-money.dj'],
            ['name' => 'Ranya Latif Said',                'email' => 'ranya.latif@d-money.dj'],
            ['name' => 'Asma Souleiman',                  'email' => 'asma.souleiman@d-money.dj'],
            ['name' => 'Ardo Yahya Mohamed',              'email' => 'ardo.yahya@d-money.dj'],
        ];

        foreach ($employees as $e) {
            $user = User::updateOrCreate(
                ['email' => $e['email']],            // cle d'unicite : pas de doublon
                [
                    'name'              => $e['name'],
                    'password'          => $defaultPassword,   // hashe par le cast 'hashed'
                    'email_verified_at' => now(),              // marque l'email comme verifie
                ]
            );

            // --- Roles Spatie (decommente et adapte a tes roles existants) ---
            // if ($e['email'] === 'super.admin@d-money.dj') {
            //     $user->syncRoles(['Super Admin']);
            // } else {
            //     $user->syncRoles(['agent']);
            // }
        }

        $this->command->info(count($employees) . ' utilisateurs crees/mis a jour.');
    }
}