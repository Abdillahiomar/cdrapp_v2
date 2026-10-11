<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Réglages PCA en clé / valeur. Le mot de passe du ZIP est stocké chiffré
 * (APP_KEY) : il n'apparaît jamais en clair en base.
 */
class PcaSetting extends Model
{
    protected $table = 'pca_settings';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function zipPassword(): ?string
    {
        $encrypted = self::get('zip_password');

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    public static function setZipPassword(string $password): void
    {
        self::put('zip_password', Crypt::encryptString($password));
    }

    /** @return string[] */
    public static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) self::get('email_recipients')))));
    }
}
