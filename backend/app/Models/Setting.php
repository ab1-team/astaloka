<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'nama',
        'nama_lembaga',
        'alamat',
        'email',
        'telepon',
        'domain',
        'peraturan_desa',
        'sk_kemenkumham',
        'calk',
        'calk_point_a',
        'status_pembayaran',
        'batas_tagihan',
        'toleransi_tunggakan',
        'billing_unpaid_limit',
        'auto_open_meter_camera',
        'logo',
        'pesan_tagihan',
        'pesan_pembayaran',
    ];

    protected $casts = [
        'status_pembayaran' => 'boolean',
        'batas_tagihan' => 'integer',
        'toleransi_tunggakan' => 'integer',
        'billing_unpaid_limit' => 'integer',
        'auto_open_meter_camera' => 'boolean',
        'calk' => 'array',
    ];

    public function villages()
    {
        return $this->hasMany(Village::class, 'setting_id');
    }
}
