<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $fillable = ['code', 'name', 'city', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** « 1300 — D-Money Agence Principal » */
    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
