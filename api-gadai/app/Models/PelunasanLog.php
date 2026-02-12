<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PelunasanLog extends Model
{
    use HasFactory;

    protected $table = 'pelunasan_logs';

    protected $fillable = [
        'detail_gadai_id',
        'user_id',
        'pokok',
        'denda',
        'penalty',
        'total_bayar',
        'hari_terlambat',
        'metode_pembayaran',
        'bukti_transfer',
        'tanggal_bayar'
    ];

    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}