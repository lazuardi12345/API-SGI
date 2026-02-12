<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GadaiAwalDetail extends Model
{
    use HasFactory;


    protected $table = 'gadai_awal_details';

    protected $fillable = [
        'detail_gadai_id',
        'pokok',
        'jasa_sewa',
        'administrasi',
        'asuransi',
        'total_diterima',
        'tenor_hari',
        'persen_jasa'
    ];


    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }
}