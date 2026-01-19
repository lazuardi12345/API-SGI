<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransaksiBrankas extends Model
{
    use HasFactory;

    protected $table = 'transaksi_brankas';

    protected $fillable = [
        'user_id',
        'detail_gadai_id', 
        'deskripsi',
        'kategori',      
        'metode',          
        'saldo_awal',
        'pemasukan',
        'pengeluaran',
        'saldo_awal_rekening',
        'saldo_akhir_rekening',
        'saldo_akhir',
        'bukti_transaksi', 
        'status_validasi', 
        'validator_id',    
        'bukti_validasi', 
        'catatan_admin',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function validator()
    {
        return $this->belongsTo(User::class, 'validator_id');
    }
    public function detailGadai()
    {
        return $this->belongsTo(Gadai::class, 'detail_gadai_id');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeOnlyPemasukan($query)
    {
        return $query->where('pemasukan', '>', 0);
    }

    public function scopeOnlyPengeluaran($query)
    {
        return $query->where('pengeluaran', '>', 0);
    }

    public function scopeKategori($query, $kategori)
    {
        return $query->where('kategori', $kategori);
    }

    public function scopePending($query)
    {
        return $query->where('status_validasi', 'pending');
    }
}