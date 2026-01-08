<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ReportPrint extends Model
{
    use HasFactory;

    protected $table = 'report_prints';

    protected $fillable = [
        'doc_id',
        'report_type',
        'report_date',
        'is_approved',
        'approved_by',
        'printed_by',
        'printed_at',
        'ip_address',
    ];

    protected $casts = [
        'report_date' => 'date',
        'is_approved' => 'boolean',
        'printed_at' => 'datetime',
    ];

    /**
     * Scope: Filter laporan yang sudah di-ACC
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope: Filter berdasarkan jenis laporan
     */
    public function scopeByType($query, $type)
    {
        return $query->where('report_type', $type);
    }

    /**
     * Scope: Filter berdasarkan tanggal laporan
     */
    public function scopeByDate($query, $date)
    {
        return $query->whereDate('report_date', $date);
    }

    /**
     * Scope: Cek apakah sudah pernah cetak hari ini
     */
    public function scopePrintedToday($query)
    {
        return $query->whereDate('printed_at', Carbon::today());
    }

    /**
     * Helper: Cek apakah laporan sudah pernah dicetak dengan ACC hari ini
     * 
     * @param string $reportType (harian, bulanan, dll)
     * @param string $reportDate (format: Y-m-d)
     * @return bool
     */
    public static function isAlreadyPrintedToday($reportType, $reportDate)
    {
        return self::approved()
            ->byType($reportType)
            ->byDate($reportDate)
            ->printedToday()
            ->exists();
    }

    /**
     * Helper: Get data cetak terakhir untuk laporan tertentu
     * 
     * @param string $reportType
     * @param string $reportDate
     * @return ReportPrint|null
     */
    public static function getLastPrint($reportType, $reportDate)
    {
        return self::approved()
            ->byType($reportType)
            ->byDate($reportDate)
            ->printedToday()
            ->latest('printed_at')
            ->first();
    }

    /**
     * Helper: Generate doc_id unik
     * 
     * @param string $prefix (REP-HRI, REP-BLN, dll)
     * @return string
     */
    public static function generateDocId($prefix = 'REP')
    {
        return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }

    /**
     * Accessor: Format tanggal laporan
     */
    public function getFormattedReportDateAttribute()
    {
        return Carbon::parse($this->report_date)->translatedFormat('l, d F Y');
    }

    /**
     * Accessor: Format waktu cetak
     */
    public function getFormattedPrintedAtAttribute()
    {
        return Carbon::parse($this->printed_at)->translatedFormat('d F Y, H:i:s');
    }

    /**
     * Accessor: Status approval dalam bahasa
     */
    public function getApprovalStatusAttribute()
    {
        return $this->is_approved ? 'Disetujui' : 'Belum Disetujui';
    }
}