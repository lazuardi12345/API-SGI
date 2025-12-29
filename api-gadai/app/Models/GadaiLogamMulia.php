<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GadaiLogamMulia extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'gadai_logam_mulia';

    protected $fillable = [
        'nama_barang',
        'type_logam_mulia',
        'kode_cap',
        'karat',
        'potongan_batu',
        'berat',
        'detail_gadai_id',
    ];

    // Relasi ke Detail Gadai
    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    // Relasi Dokumen Pendukung khusus logam mulia (HAS ONE)
    public function dokumenPendukung()
    {
        return $this->hasOne(DokumenPendukungEmas::class, 'emas_id')
                    ->where('emas_type', 'logam_mulia');
    }

    // Relasi pivot ke kelengkapan emas
    public function kelengkapanEmas()
    {
        return $this->belongsToMany(
            KelengkapanEmas::class,                 
            'gadai_logam_mulia_kelengkapan',       
            'gadai_logam_mulia_id',               
            'kelengkapan_emas_id'                  
        )->withTimestamps();
    }
}
