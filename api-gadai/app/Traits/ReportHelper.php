<?php

namespace App\Traits;

use App\Models\ReportPrint;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

trait ReportHelper {
    private function generateReportQr($title, $tanggal, $doc_id, $acc_by) {
        $params = base64_encode(json_encode([
            'title' => $title,
            'petugas' => auth()->user()->name ?? 'Staff',
            'acc' => $acc_by,
            'tgl' => $tanggal,
            'jam' => now()->format('H:i:s')
        ]));
        $url = url("/api/v1/verify-report/{$doc_id}?d={$params}");
        $image = QrCode::format('png')->size(300)->margin(2)->errorCorrection('M')->generate($url);
        return 'data:image/png;base64,' . base64_encode($image);
    }

    private function syncReportToDatabase($type, $tanggal, $isApproved, $namaManager, $docId, $request) {
        if ($isApproved && $namaManager && $docId) {
            ReportPrint::updateOrCreate(
                ['report_type' => $type, 'report_date' => $tanggal],
                [
                    'doc_id' => $docId,
                    'is_approved' => true,
                    'approved_by' => $namaManager,
                    'printed_by' => auth()->user()->name ?? 'Staff',
                    'printed_at' => now(),
                    'ip_address' => $request->ip()
                ]
            );
        }
    }
}