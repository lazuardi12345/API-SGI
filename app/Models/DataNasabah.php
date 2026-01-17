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
        'no_rek',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }


        public function detailGadai()
    {
        return $this->hasMany(DetailGadai::class, 'nasabah_id', 'id');
    }

    /**
     * Override accessor foto_ktp supaya langsung jadi URL backend streaming
     */
    public function getFotoKtpAttribute($value)
    {
        if (!$value) return null;

 
        if (str_starts_with($value, 'http')) return $value;

        // Sanitasi path
        $path = ltrim($value, '/');
        $path = str_replace('..', '', $path);

        // URL backend streaming
        $appUrl = rtrim(env('APP_URL'), '/');

        return "{$appUrl}/api/files/{$path}";
    }
}
