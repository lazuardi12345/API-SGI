<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeHp extends Model
{
    protected $table = 'type_hp';

    protected $fillable = ['merk_hp_id', 'nama_type'];


    public function merk()
    {
        return $this->belongsTo(MerkHp::class, 'merk_hp_id');
    }


    public function hargaHp()
    {
        return $this->hasMany(HargaHp::class, 'type_hp_id');
    }

    public function hargaTerbaru()
{

    return $this->hasOne(HargaHp::class, 'type_hp_id')->latestOfMany();
}


    public function grades()
    {
        return $this->hasManyThrough(
            GradeHp::class,   
            HargaHp::class,   
            'type_hp_id',     
            'harga_hp_id',    
            'id',             
            'id'              
        );
    }
}
