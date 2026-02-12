<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerpanjanganTempo extends Model
{
    use HasFactory;


    protected $table = 'perpanjangan_tempo';

    protected $fillable = [
        'detail_gadai_id',
        'tanggal_perpanjangan',
        'jatuh_tempo_baru',
        'nominal_jasa',    
        'nominal_denda',    
        'nominal_penalty',   
        'nominal_admin',   
        'total_bayar',       
        'status_bayar',
        'metode_pembayaran',
        'bukti_transfer',
        'tanggal_bayar'     
    ];


    protected $casts = [
        'tanggal_perpanjangan' => 'date',
        'jatuh_tempo_baru'     => 'date',
        'tanggal_bayar'        => 'datetime',
        'nominal_jasa'         => 'float',
        'nominal_denda'        => 'float',
        'nominal_penalty'      => 'float',
        'nominal_admin'        => 'float',
        'total_bayar'          => 'float',
    ];

    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }
}