<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LaporanGudang extends Model
{
    use HasFactory;

    protected $table = 'laporan_gudangs';

    protected $fillable = [
        'detail_gadai_id', 
        'user_id',    
        'penerima_id', 
        'jenis_pergerakan', 
        'keterangan'
    ];

    public function detailGadai() 
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    public function petugasGudang() 
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function penerima() 
    {
        return $this->belongsTo(User::class, 'penerima_id');
    }
}