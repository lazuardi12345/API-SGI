<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotaKehilangan extends Model
{
    use HasFactory;

    protected $table = 'nota_kehilangan';

    protected $fillable = [
        'no_nota',
        'detail_gadai_id',
        'nasabah_id',
        'foto_nasabah',
        'foto_nota_ilang',
    ];

    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    public function nasabah()
    {
        return $this->belongsTo(DataNasabah::class, 'nasabah_id');
    }

    public function getFotoNasabahAttribute($value)
    {
        if (!$value) return null;
        if (str_starts_with($value, 'http')) return $value;
        $appUrl = rtrim(env('APP_URL'), '/');
        return "{$appUrl}/files/" . ltrim($value, '/');
    }

    public function getFotoNotaIlangAttribute($value)
    {
        if (!$value) return null;
        if (str_starts_with($value, 'http')) return $value;
        $appUrl = rtrim(env('APP_URL'), '/');
        return "{$appUrl}/files/" . ltrim($value, '/');
    }
}