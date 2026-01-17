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
    'nominal_admin',
    'status_bayar',
    'metode_pembayaran',
    'bukti_transfer'
];

    /**
     * Relasi ke DetailGadai
     * Satu perpanjangan tempo milik satu detail gadai
     */
    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }
}
