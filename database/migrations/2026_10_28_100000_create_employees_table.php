<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employés D-Money suivis dans le classement par nombre de transactions.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('msisdn', 20)->unique();           // format des transactions : 2537XXXXXXX
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Liste initiale fournie par D-Money
        $employees = [
            ['SALEM AHMED MOHAMED', '77084651'],
            ['ABDOULFATAH MOUSSA DOUALEH', '77128413'],
            ['ABDOURAHMAN ABDOULHAKIM MOHAMED', '77657470'],
            ['BOGOREH YACIN ABDI', '77171634'],
            ['MOUSTAPHA DJAMA HASSAN', '77108906'],
            ['ELMI AHMED ABDI', '77546078'],
            ['HIBO MAHAMOUD ABDILLAHI', '77812815'],
            ['FATHIA ISMAEL HASSAN', '77162474'],
            ['FOZZI ALI BATOUN', '77811832'],
            ['AMAL ABDOURAHMAN ABIB', '77653929'],
            ['ABDILLAHI OMAR DIRIEH', '77825892'],
            ['SAMATAR KASSIM ABDILLAHI', '77825625'],
            ['AMIN HASSAN DOUALEH', '77049495'],
            ['MAHDI SALEH TOUBEH', '77631405'],
            ['AWO MOHAMED KAYAD', '77027269'],
            ['FOUAD BOUH ISSA', '77611909'],
            ['SOUMEYA MOUSTAPHA ELMI', '77842494'],
            ['RANYA LATIF SAID', '77660707'],
            ['YASMIN ABDALLAH SAID', '77193315'],
            ['OUBADO ASSOWEH ROBLEH', '77772021'],
            ['DEKA AMIN AHMED', '77844556'],
            ['IMAN MOHAMED ABDOURAHMAN', '77059809'],
            ['NOURIA DJIBRIL OBSIEH', '77651926'],
            ['LIBAN AHMED DOUBED', '77334000'],
            ['DJIBRIL DJAMA OBSIEH', '77132436'],
            ['ABDOULKARIM DAHER ABDI', '77661300'],
            ['MOHAMED SALAH ELMI', '77136582'],
            ['MAGUIDA MOHAMED HAGAYO', '77881920'],
            ['SAREDO ALI HOUSSEIN', '77820369'],
            ['HODAN ABDI OSMAN', '77848013'],
            ['ABOURAHMAN OMAR ASSOWEH', '77861692'],
            ['NASRA HASSAN WABERI', '77049596'],
            ['ASMA SOULEIMAN MAHAMOUD', '77499359'],
            ['DAYIBO OSMAN MOUSSA', '77657909'],
            ['HAWA ISSA MOHAMED', '77092735'],
            ['ARDO YAHYA MOHAMED', '77389674'],
            ['SAMI IBRAHIM MOHAMED', '77206755'],
            ['YACOUB OSMAN ELABEH', '77721500'],
            ['RADWAN ADEN FARAH', '77265972'],
            ['SAIDA MAHAMOUD HOUSSEIN', '77733985'],
        ];

        DB::table('employees')->insertOrIgnore(array_map(fn ($e) => [
            'full_name'  => $e[0],
            'msisdn'     => '253' . $e[1],
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $employees));
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
