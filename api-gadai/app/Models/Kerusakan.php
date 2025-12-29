<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kerusakan extends Model
{
    use HasFactory;

    protected $table = 'kerusakan';

    protected $fillable = [
        'nama_kerusakan',
        'persen',
    ];

    // Hubungan ke GadaiHp murni tanpa override
    public function gadaiHp()
    {
        return $this->belongsToMany(GadaiHp::class, 'gadai_hp_kerusakan');
    }
}