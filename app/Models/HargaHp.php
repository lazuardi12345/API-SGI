<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HargaHp extends Model
{
    use HasFactory;


    protected $table = 'harga_hp';

    
    protected $fillable = [
        'type_hp_id',
        'harga_barang',
    ];


    public function typeHp()
    {
        return $this->belongsTo(TypeHp::class, 'type_hp_id');
    }


    public function grades()
    {
        return $this->hasMany(GradeHp::class, 'harga_hp_id');
    }
}
