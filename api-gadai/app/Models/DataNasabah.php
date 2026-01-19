<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataNasabah extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'data_nasabah';

    protected $fillable = [
        'user_id',
        'nama_lengkap',
        'nik',
        'alamat',
        'foto_ktp',
        'no_hp',
        'bank',        
        'no_rek', 
    ];

    /**
     * Relasi ke User (Petugas yang input)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relasi ke banyak transaksi Gadai
     */
    public function detailGadai()
    {
        return $this->hasMany(DetailGadai::class, 'nasabah_id', 'id');
    }

    /**
     * Accessor Foto KTP
     */
    public function getFotoKtpAttribute($value)
    {
        if (!$value) return null;
        if (str_starts_with($value, 'http')) return $value;

        $path = ltrim($value, '/');
        $path = str_replace('..', '', $path);
        $appUrl = rtrim(env('APP_URL'), '/');

        return "{$appUrl}/api/files/{$path}";
    }

    /**
     * Helper untuk menampilkan Nama Bank yang rapi (Opsional)
     * Contoh: SEABANK -> SeaBank, BANK_JAGO -> Bank Jago
     */
    public function getNamaBankFormattedAttribute()
    {
        return str_replace('_', ' ', ucwords(strtolower($this->bank)));
    }
}