<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['full_name', 'msisdn', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Ramène un numéro saisi (« 77 08 46 51 », « +253 77084651 »…) au format
     * des transactions (25377084651). Retourne null si le numéro est invalide.
     */
    public static function normalizeMsisdn(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 8) {
            $digits = '253' . $digits;
        }

        return preg_match('/^2537\d{7}$/', $digits) ? $digits : null;
    }

    /** Numéro lisible : 77 08 46 51 */
    public function displayMsisdn(): string
    {
        return trim(chunk_split(substr($this->msisdn, 3), 2, ' '));
    }
}
