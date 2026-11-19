<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglages des rapports envoyés par email, en clé / valeur.
 * Clés du tableau de bord des revenus : revenue_recipients (JSON), revenue_enabled,
 * revenue_last_at, revenue_last_status.
 */
class ReportSetting extends Model
{
    protected $table = 'report_settings';
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

    /** @return string[] */
    public static function revenueRecipients(): array
    {
        return array_values(array_filter((array) json_decode((string) self::get('revenue_recipients', '[]'), true)));
    }

    /** @param string[] $emails */
    public static function setRevenueRecipients(array $emails): void
    {
        self::put('revenue_recipients', json_encode(array_values($emails)));
    }

    public static function revenueEnabled(): bool
    {
        return self::get('revenue_enabled') === '1';
    }
}
