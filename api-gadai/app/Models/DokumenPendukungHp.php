<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokumenPendukungHp extends Model
{
    use HasFactory;

    protected $table = 'dokumen_pendukung_hp';

    protected $fillable = [
        'gadai_hp_id',
        'body',
        'imei',
        'about',
        'akun',
        'admin',
        'cam_depan',
        'cam_belakang',
        'rusak',
        'samsung_account',
        'galaxy_store',
        'icloud',
        'battery',
        'utools',
        'iunlocker',
        'cek_pencurian',
    ];

    private function convertPathToUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;

        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);

        $appUrl = rtrim(env('APP_URL'), '/');

        return "{$appUrl}/api/files/{$path}";
    }

    public function getBodyAttribute($value)         { return $this->convertPathToUrl($value); }
    public function getImeiAttribute($value)         { return $this->convertPathToUrl($value); }
    public function getAboutAttribute($value)        { return $this->convertPathToUrl($value); }
    public function getAkunAttribute($value)         { return $this->convertPathToUrl($value); }
    public function getAdminAttribute($value)        { return $this->convertPathToUrl($value); }
    public function getCamDepanAttribute($value)     { return $this->convertPathToUrl($value); }
    public function getCamBelakangAttribute($value)  { return $this->convertPathToUrl($value); }
    public function getRusakAttribute($value)        { return $this->convertPathToUrl($value); }
    public function getSamsungAccountAttribute($value){ return $this->convertPathToUrl($value); }
    public function getGalaxyStoreAttribute($value)  { return $this->convertPathToUrl($value); }
    public function getIcloudAttribute($value)       { return $this->convertPathToUrl($value); }
    public function getBatteryAttribute($value)      { return $this->convertPathToUrl($value); }
    public function getUtoolsAttribute($value)       { return $this->convertPathToUrl($value); }
    public function getIunlockerAttribute($value)    { return $this->convertPathToUrl($value); }
    public function getCekPencurianAttribute($value) { return $this->convertPathToUrl($value); }
}
