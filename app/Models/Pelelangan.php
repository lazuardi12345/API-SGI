<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pelelangan extends Model
{
    use HasFactory;

    protected $table = 'pelelangan';

    protected $fillable = [
        'detail_gadai_id',
        'status_lelang',
        'nominal_diterima',   
        'keuntungan_lelang',  
        'metode_pembayaran',  
        'waktu_bayar',       
        'bukti_transfer',     
        'keterangan',
    ];

  protected $casts = [
    'waktu_bayar' => 'datetime',
    'nominal_diterima' => 'double', 
    'keuntungan_lelang' => 'double',
];

    /**
     * Relasi ke DetailGadai
     */
    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    /**
     * Helper untuk cek apakah pembayaran via transfer
     */
    public function isTransfer(): bool
    {
        return $this->metode_pembayaran === 'transfer';
    }
}