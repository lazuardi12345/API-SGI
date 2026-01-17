<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasFactory;

    protected $fillable = [
        'detail_gadai_id',
        'user_id',
        'role',
        'status',
        'catatan',
    ];

   public function detailGadai()
    {
        return $this->belongsTo(\App\Models\DetailGadai::class, 'detail_gadai_id')
                    ->with(['nasabah', 'type']); 
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    
}
