<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kelengkapan extends Model
{
    protected $table = 'kelengkapan';

    protected $fillable = ['nama_kelengkapan'];

    public function gadaiHp()
    {
        return $this->belongsToMany(GadaiHp::class, 'gadai_hp_kelengkapan');
    }


}
