<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GadaiHp extends Model
{
    use HasFactory;

    protected $table = 'gadai_hp';

    protected $fillable = [
        'nama_barang', 'imei', 'warna', 'kunci_password', 'kunci_pin',
        'kunci_pola', 'ram', 'rom', 'merk_hp_id', 'type_hp_id',
        'grade_type', 'grade_hp_id', 'detail_gadai_id', 'grade_nominal'
    ];

    // Relasi ke Merk
    public function merk() { 
        return $this->belongsTo(MerkHp::class, 'merk_hp_id'); 
    }

    // Relasi ke Type
    public function type_hp() { 
        return $this->belongsTo(TypeHp::class, 'type_hp_id'); 
    }

    public function grade() { 
        return $this->belongsTo(GradeHp::class, 'grade_hp_id'); 
    }

    public function detailGadai() { 
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id'); 
    }

    public function kerusakanList() {
        return $this->belongsToMany(Kerusakan::class, 'gadai_hp_kerusakan')
                    ->withPivot('nominal_override')
                    ->withTimestamps();
    }

    public function kelengkapanList() {
        return $this->belongsToMany(Kelengkapan::class, 'gadai_hp_kelengkapan')
                    ->withPivot('nominal_override')
                    ->withTimestamps();
    }

    public function dokumenPendukungHp() {
        return $this->hasOne(DokumenPendukungHp::class, 'gadai_hp_id');
    }
}