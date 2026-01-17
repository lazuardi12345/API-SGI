<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GadaiRetro extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'gadai_retro';

    protected $fillable = [
        'nama_barang',
        'type_retro',
        'kode_cap',
        'karat',
        'potongan_batu',
        'berat',
        'detail_gadai_id',
    ];

    // RELASI DETAIL GADAI
    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    // RELASI DOKUMEN PENDUKUNG
    public function dokumenPendukung()
{
    return $this->hasOne(DokumenPendukungEmas::class, 'emas_id')
        ->where('emas_type','retro')
        ->withDefault();
}



    // RELASI KELENGKAPAN
    public function kelengkapan()
    {
        return $this->belongsToMany(
            KelengkapanEmas::class,
            'gadai_retro_kelengkapan',
            'gadai_retro_id',
            'kelengkapan_emas_id'
        )->withTimestamps();
    }
}
