<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetailGadai extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'detail_gadai';

    protected $fillable = [
        'no_gadai',
        'no_nasabah',
        'tanggal_gadai',
        'jatuh_tempo',
        'taksiran',
        'uang_pinjaman',
        'type_id',
        'nasabah_id',
        'status',
        'nominal_bayar', 
        'tanggal_bayar', 
        'metode_pembayaran', 
        'bukti_transfer',
    ];

    // Relasi satu detail gadai bisa punya satu HP
    public function hp()
    {
        return $this->hasOne(GadaiHp::class, 'detail_gadai_id');
    }

    // Relasi satu detail gadai bisa punya satu perhiasan
    public function perhiasan()
    {
        return $this->hasOne(GadaiPerhiasan::class, 'detail_gadai_id');
    }

    // Relasi satu detail gadai bisa punya satu logam mulia
    public function logamMulia()
    {
        return $this->hasOne(GadaiLogamMulia::class, 'detail_gadai_id');
    }

    // Relasi satu detail gadai bisa punya satu retro
    public function retro()
    {
        return $this->hasOne(GadaiRetro::class, 'detail_gadai_id');
    }

    // Relasi tipe gadai
    public function type()
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    // Relasi nasabah
    public function nasabah()
    {
        return $this->belongsTo(DataNasabah::class, 'nasabah_id');
    }

    // Relasi perpanjangan tempo (satu detail gadai bisa punya banyak perpanjangan)
    public function perpanjanganTempos()
    {
        return $this->hasMany(PerpanjanganTempo::class, 'detail_gadai_id');
    }

    public function user()
{
    return $this->belongsTo(User::class, 'user_id');
}


public function approvals()
{
    return $this->hasMany(Approval::class, 'detail_gadai_id');
}

// Helper untuk ambil approval per role
public function checkerApproval()
{
    return $this->hasOne(Approval::class, 'detail_gadai_id')->where('role', 'checker');
}

public function hmApproval()
{
    return $this->hasOne(Approval::class, 'detail_gadai_id')->where('role', 'hm');
}

public function pelelangan()
    {
        return $this->hasOne(Pelelangan::class, 'detail_gadai_id', 'id');
    }

    public function barang() 
{
    return $this->hasOne(Barang::class, 'detail_gadai_id');
}

}
