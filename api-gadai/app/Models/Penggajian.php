<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Penggajian extends Model
{
    protected $table = 'penggajian';

    protected $fillable = [
        'bulan',
        'tahun',
        'jumlah_karyawan',
        'total_gaji',
        'keterangan',
    ];

    protected $casts = [
        'bulan'           => 'integer',
        'jumlah_karyawan' => 'integer',
        'total_gaji'      => 'decimal:2',
    ];

    // Accessor nama bulan
    public function getNamaBulanAttribute(): string
    {
        $bulan = [
            1 => 'Januari',  2 => 'Februari', 3 => 'Maret',
            4 => 'April',    5 => 'Mei',       6 => 'Juni',
            7 => 'Juli',     8 => 'Agustus',   9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        return $bulan[$this->bulan] ?? '-';
    }

    // Accessor label periode lengkap, contoh: "Januari 2025"
    public function getPeriodeAttribute(): string
    {
        return $this->nama_bulan . ' ' . $this->tahun;
    }

    // Scope filter by tahun
    public function scopeTahun($query, $tahun)
    {
        return $query->where('tahun', $tahun);
    }
}