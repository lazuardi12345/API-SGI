<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerpanjanganTempo extends Model
{
    use HasFactory;

    protected $table = 'perpanjangan_tempo';

    protected $fillable = [
        'detail_gadai_id',
        'tanggal_perpanjangan',
        'jatuh_tempo_baru',
        'nominal_jasa',    
        'nominal_denda',    
        'nominal_penalty',   
        'nominal_admin',   
        'total_bayar',       
        'status_bayar',
        'metode_pembayaran',
        'bukti_transfer',
        'tanggal_bayar'     
    ];

    protected $casts = [
        'tanggal_perpanjangan' => 'date',
        'jatuh_tempo_baru'     => 'date',
        'tanggal_bayar'        => 'datetime',
        'nominal_jasa'         => 'decimal:2',   
        'nominal_denda'        => 'decimal:2',    
        'nominal_penalty'      => 'decimal:2',  
        'nominal_admin'        => 'decimal:2',   
        'total_bayar'          => 'decimal:2', 
    ];

    protected $appends = []; 


    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }


    public function scopeLunas($query)
    {
        return $query->where('status_bayar', 'lunas');
    }


    public function scopePending($query)
    {
        return $query->where('status_bayar', 'pending');
    }


    public function getTotalBayarFormattedAttribute()
    {
        return 'Rp ' . number_format($this->total_bayar, 0, ',', '.');
    }


    public function isLunas()
    {
        return $this->status_bayar === 'lunas';
    }


    public function isPending()
    {
        return $this->status_bayar === 'pending';
    }
}