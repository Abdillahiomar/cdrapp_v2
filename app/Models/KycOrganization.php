<?php
// app/Models/KycOrganization.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycOrganization extends Model
{
    // Schema kyc + table kyc_organizations
    protected $table = 'kyc.kyc_organizations';

    protected $primaryKey = 'unique_system_id';
    public $incrementing = false;        // PK non auto-incrementee (VARCHAR)
    protected $keyType = 'string';

    // La table a created_at/updated_at (DEFAULT now()), donc timestamps actifs
    public $timestamps = true;

    protected $fillable = [
        'unique_system_id', 'short_code', 'initiator_id', 'initiator_name',
        'source_datetime', 'org_profile', 'legal_representative', 'activity',
        'company_status', 'additional_address_info',
        'address_line_1', 'address_line_2', 'address_line_3',
        'city', 'post_code', 'region',
        'contact_person_name', 'contact_person_phone',
        'notif_channel', 'notif_msisdn', 'notif_email', 'notif_language',
        'id_type', 'id_number', 'id_effective_date', 'id_expiry_date',
        'has_commercial_register', 'has_contract', 'has_id_doc', 'has_owner',
        'has_patent', 'has_procurement', 'has_registration_form', 'has_shop',
        'data_quality_flags', 'source_file',
    ];

    protected $casts = [
        'source_datetime'         => 'datetime',
        'id_effective_date'       => 'date',
        'id_expiry_date'          => 'date',
        'has_commercial_register' => 'boolean',
        'has_contract'            => 'boolean',
        'has_id_doc'              => 'boolean',
        'has_owner'               => 'boolean',
        'has_patent'              => 'boolean',
        'has_procurement'         => 'boolean',
        'has_registration_form'   => 'boolean',
        'has_shop'                => 'boolean',
    ];

    // Lien optionnel vers l'organisation "native" via le short code
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'short_code', 'SHORT_CODE');
    }
}