<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    // Table KYC dans le schéma PostgreSQL "kyc"
    protected $table = 'kyc.kyc_customers';

    // Clé primaire = unique_system_id (BIGINT, non auto-incrémenté par Eloquent)
    protected $primaryKey = 'unique_system_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'unique_system_id',
        'msisdn',
        'initiator_id',
        'initiator_name',
        'source_datetime',
        'channel',
        'customer_profile',
        'customer_profile_1',
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'mother_full_name',
        'sex',
        'date_of_birth',
        'profession',
        'courtesy',
        'contact_person_name',
        'contact_person_phone',
        'nationality',
        'country',
        'country_of_birth',
        'region',
        'city',
        'notif_channel',
        'notif_msisdn',
        'notif_email',
        'notif_language',
        'id_type',
        'id_number',
        'id_expiry_date',
        'has_id_front',
        'has_id_back',
        'has_face_picture',
        'data_quality_flags',
        'first_seen_at',
        'last_updated_at',
        'source_file',
    ];

    protected $casts = [
        'source_datetime'  => 'datetime',
        'date_of_birth'    => 'date',
        'id_expiry_date'   => 'date',
        'first_seen_at'    => 'datetime',
        'last_updated_at'  => 'datetime',
        'has_id_front'     => 'boolean',
        'has_id_back'      => 'boolean',
        'has_face_picture' => 'boolean',
    ];

    // Le pipeline ETL gère lui-même les timestamps → on désactive Eloquent
    public $timestamps = false;
}