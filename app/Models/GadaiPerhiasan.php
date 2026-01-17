<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GadaiPerhiasan extends Model
{
    use HasFactory;
       protected $table = 'gadai_perhiasan';
    protected $fillable = [
        'nama_barang',
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
        return $this->hasOne(DokumenPendukungEmas::class, 'emas_id')->where('emas_type', 'perhiasan');
    }

    // RELASI KELENGKAPAN
    public function kelengkapan()
    {
        return $this->belongsToMany(
            KelengkapanEmas::class,      
            'gadai_perhiasan_kelengkapan', 
            'gadai_perhiasan_id',         
            'kelengkapan_emas_id'         
        )->withTimestamps();
    }
}
