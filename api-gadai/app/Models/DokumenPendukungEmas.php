<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokumenPendukungEmas extends Model
{
    use HasFactory;

    protected $table = 'dokumen_pendukung_emas';

    protected $fillable = [
        'emas_type','emas_id','emas_timbangan','gosokan_timer','gosokan_ktp',
        'batu','cap_merek','karatase','ukuran_batu'
    ];

    protected $appends = [
        'emas_timbangan_url',
        'gosokan_timer_url',
        'gosokan_ktp_url',
        'batu_url',
        'cap_merek_url',
        'karatase_url',
        'ukuran_batu_url',
    ];

    private function generateUrl($value)
    {
        if (!$value) return null;
        return url("/api/files/{$value}");
    }

    public function getEmasTimbanganUrlAttribute()
    {
        return $this->generateUrl($this->emas_timbangan);
    }

    public function getGosokanTimerUrlAttribute()
    {
        return $this->generateUrl($this->gosokan_timer);
    }

    public function getGosokanKtpUrlAttribute()
    {
        return $this->generateUrl($this->gosokan_ktp);
    }

    public function getBatuUrlAttribute()
    {
        return $this->generateUrl($this->batu);
    }

    public function getCapMerekUrlAttribute()
    {
        return $this->generateUrl($this->cap_merek);
    }

    public function getKarataseUrlAttribute()
    {
        return $this->generateUrl($this->karatase);
    }

    public function getUkuranBatuUrlAttribute()
    {
        return $this->generateUrl($this->ukuran_batu);
    }
}
