<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KelengkapanEmas extends Model
{
    use HasFactory;

    protected $table = 'kelengkapan_emas';

    protected $fillable = [
        'nama_kelengkapan', 
    ];
}
