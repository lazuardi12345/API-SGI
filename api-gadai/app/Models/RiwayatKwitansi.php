<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatKwitansi extends Model
{
    use HasFactory;

    protected $table = 'riwayat_kwitansi';

    protected $fillable = [
        'no_kwitansi',
        'jenis_transaksi',
        'jumlah_cetak',
        'transaksi_id',
        'user_id',
        'tgl_cetak_pertama',
        'tgl_cetak_terakhir'
    ];

    /**
     * Relasi ke User (Kasir)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}