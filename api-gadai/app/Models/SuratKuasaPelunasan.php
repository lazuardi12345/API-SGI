<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuratKuasaPelunasan extends Model
{
    protected $table = 'surat_kuasa_pelunasan';

    protected $fillable = [
        'detail_gadai_id',
        'nasabah_id',
        'wakil_nama',
        'wakil_nik',
        'wakil_alamat',
        'wakil_hp',
        'wakil_hubungan',
        'foto_wakil',
        'foto_surat'
    ];

    private function convertPathToUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;

        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);

        $appUrl = rtrim(env('APP_URL'), '/');

        return "{$appUrl}/files/{$path}";
    }

    public function getFotoWakilAttribute($value) { return $this->convertPathToUrl($value); }
    public function getFotoSuratAttribute($value) { return $this->convertPathToUrl($value); }

    public function detailGadai()
    {
        return $this->belongsTo(DetailGadai::class, 'detail_gadai_id');
    }

    public function pemberiKuasa()
    {
        return $this->belongsTo(DataNasabah::class, 'nasabah_id');
    }
}