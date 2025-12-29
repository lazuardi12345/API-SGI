<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerkHp extends Model
{
    protected $table = 'merk_hp';

    protected $fillable = ['nama_merk'];

    public function types()
    {
        return $this->hasMany(TypeHp::class, 'merk_hp_id');
    }
}
