<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LaporanGudang extends Model
{
    use HasFactory;

    protected $table = 'laporan_gudangs'; // Pastikan nama table sesuai di migrasi

    protected $fillable = [
        'detail_gadai_id', 
        'user_id', 
        'jenis_pergerakan', 
        'keterangan'
    ];

    /**
     * Relasi ke Data Gadai Utama
     * Menggunakan detail_gadai_id sebagai foreign key
     */
    public function detailGadai() 
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    /**
     * Relasi ke User (Petugas Gudang) yang melakukan scan
     */
    public function user() 
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}