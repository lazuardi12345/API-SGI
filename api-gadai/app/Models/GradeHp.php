<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradeHp extends Model
{
    use HasFactory;

    protected $table = 'grade_hp';

    /**
     * Mass assignable fields
     * Semua kolom Grade dan Taksiran harus didaftarkan di sini 
     * agar bisa disimpan menggunakan metode create() atau update()
     */
    protected $fillable = [
        'harga_hp_id',
        
        // === GRADE A ===
        'taksiran_a_dus',
        'grade_a_dus',
        'taksiran_a_tanpa_dus',
        'grade_a_tanpa_dus',

        // === GRADE B ===
        'taksiran_b_dus',
        'grade_b_dus',
        'taksiran_b_tanpa_dus',
        'grade_b_tanpa_dus',

        // === GRADE C ===
        'taksiran_c_dus',
        'grade_c_dus',
        'taksiran_c_tanpa_dus',
        'grade_c_tanpa_dus',
    ];

    /**
     * Relasi ke HargaHp
     * Menghubungkan hasil penilaian dengan data harga barang asli
     */
    public function hargaHp()
    {
        return $this->belongsTo(HargaHp::class, 'harga_hp_id');
    }

    /**
     * Helper: Mendapatkan selisih (margin) antara taksiran dan grade tertentu
     * Opsional, berguna jika ingin menampilkan 'biaya admin/risiko' di UI
     */
    public function getMarginADusAttribute()
    {
        return $this->taksiran_a_dus - $this->grade_a_dus;
    }
}