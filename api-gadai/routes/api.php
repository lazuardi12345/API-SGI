<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DataNasabahController;
use App\Http\Controllers\TypeController;
use App\Http\Controllers\DetailGadaiController;
use App\Http\Controllers\GadaiHpController;
use App\Http\Controllers\GadaiPerhiasanController;
use App\Http\Controllers\GadaiLogamMuliaController;
use App\Http\Controllers\GadaiRetroController;
use App\Http\Controllers\PerpanjanganTempoController;
use App\Http\Controllers\DashboardGadaiController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ApprovalFilterController;
use App\Http\Controllers\GadaiWizardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AdminApprovalController;
use App\Http\Controllers\PelelanganController;
use App\Http\Controllers\KerusakanController;
use App\Http\Controllers\MerkHpController;
use App\Http\Controllers\TypeHpController;
use App\Http\Controllers\GradeHpController;
use App\Http\Controllers\KelengkapanController;
use App\Http\Controllers\KelengkapanEmasController;
use App\Http\Controllers\GadaiEmasController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\GadaiUlangController;
use App\Http\Controllers\GadaiUlangEmasController;
use App\Http\Controllers\HargaHpController;
use App\Http\Controllers\BrankasController;
use App\Http\Controllers\LaporanHarianCheckerController;
use App\Http\Controllers\PetugasLaporanController;
use App\Http\Controllers\AdminLaporanMingguanController;
use App\Http\Controllers\PelunasanController;
use App\Http\Controllers\LaporanGudangController;

use App\Events\TransaksiBaru;
// ================== AUTH ================== //



Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
Route::get('/v1/verify-report/{doc_id}', [LaporanHarianCheckerController::class, 'publicVerify']);
Route::get('/v1/verify-sbg/{no_gadai}', [DetailGadaiController::class, 'publicVerifySBG']);


Route::get('/files/{path}', [App\Http\Controllers\StorageController::class, 'get'])
    ->where('path', '.*')
    ->name('storage.get');

    Route::get('/test-notif', function () {
    event(new TransaksiBaru("Ini tes notifikasi Selesai", "Selesai", "SGI-TEST-001"));
    return "Notif Selesai terkirim!";
});

Route::get('/test-notification', function() {
    // Menggunakan app() agar constructor __construct() di controller berjalan sempurna
    $notifService = app(\App\Http\Controllers\NotificationServiceController::class);
    
    $result = $notifService->notifyRole(
        'hm',
        'Test Koneksi Laravel-NestJS',
        'Jembatan notifikasi sudah LUNAS terhubung! ',
        'info',
        [
            'test_id' => rand(100, 999),
            'environment' => config('app.env')
        ]
    );
    
    return response()->json([
        'info' => 'Mencoba mengirim notifikasi ke role HM...',
        'nestjs_response' => $result
    ]);
});



Route::get('/test-nestjs-connection', function() {
    $controller = app(NotificationServiceController::class);
    $result = $controller->testConnection();
    
    return response()->json($result);
});

Route::post('/test-send-notification', function() {
    $controller = app(NotificationServiceController::class);
    
    $result = $controller->sendNotification([
        'type' => 'UNIT_VALIDATED',
        'user_id' => auth()->id() ?? 1,
        'no_gadai' => 'TEST-' . now()->format('YmdHis'),
        'nama_nasabah' => 'Test User',
        'title' => 'Test Notification',
        'message' => 'This is a test notification from Laravel',
        'status_transaksi' => 'selesai',
        'nominal_cair' => 1000000,
    ]);
    
    return response()->json($result);
});

Route::get('/test-lunas', function () {
    event(new TransaksiBaru("Ini tes notifikasi Lunas", "Lunas", "SGI-TEST-002"));
    return "Notif Lunas terkirim!";
});

Route::middleware('auth:api')->post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

Route::middleware(['auth:api'])->group(function () {
    Route::get('all/gudang/riwayat', [LaporanGudangController::class, 'index']);
    Route::get('all/gudang/pending', [LaporanGudangController::class, 'getPendingItems']);
    Route::get('all/gudang/riwayat/{id}', [LaporanGudangController::class, 'show']);
});


// ================== PETUGAS ================== //
// hanya GET (index/show) dan POST (store)
Route::middleware(['auth:api', 'role:petugas'])->group(function () {
    Route::apiResource('petugas/data-nasabah', DataNasabahController::class);
    Route::apiResource('petugas/type', TypeController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/detail-gadai', DetailGadaiController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/gadai-hp', GadaiHpController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/gadai-perhiasan', GadaiPerhiasanController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/gadai-logam-mulia', GadaiLogamMuliaController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/gadai-retro', GadaiRetroController::class)->only(['index', 'show', 'store']);
    Route::apiResource('petugas/perpanjangan-tempo', PerpanjanganTempoController::class)->only(['index', 'show', 'store']);
    Route::get('petugas/notifications', [NotificationController::class, 'getNotifications']);
    Route::post('petugas/notifications/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('petugas/gadai-wizard', [GadaiWizardController::class, 'store']);
    Route::post('petugas/gadai-emas', [GadaiEmasController::class, 'store']);
    Route::get('petugas/notifications/new', [NotificationController::class, 'getNew']); 
    Route::get('petugas/notifications', [NotificationController::class, 'getAll']);     
    Route::post('petugas/notifications/mark-read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('petugas/kelengkapan', KelengkapanController::class)->only(['index', 'show']);
    Route::apiResource('petugas/kelengkapan-emas', KelengkapanEmasController::class)->only(['index', 'show']);
    Route::apiResource('petugas/kerusakan', KerusakanController::class)->only(['index', 'show']);
    Route::apiResource('petugas/type-hp', TypeHpController::class)->only(['index', 'show']);
    Route::apiResource('petugas/merk-hp', MerkHpController::class)->only(['index', 'show']);
    Route::get('petugas/harga-hp', [HargaHpController::class, 'index']);
    Route::get('petugas/harga-hp/{id}', [HargaHpController::class, 'show']);
    Route::get('petugas/harga-hp/type/{typeHpId}', [HargaHpController::class, 'getByType']);
    Route::get('petugas/type-hp/{typeId}/grade', [HargaHpController::class, 'getGradeByType']);
    
    // Grade HP - Read Only
    Route::get('petugas/grade-hp', [GradeHpController::class, 'index']);
    Route::get('petugas/grade-hp/{id}', [GradeHpController::class, 'show']);
    Route::get('petugas/grade-hp/harga/{hargaHpId}', [GradeHpController::class, 'getByHargaHp']);
    Route::get('petugas/grade-hp/by-merk/{merkId}', [GradeHpController::class, 'getByMerk']);
    Route::get('petugas/type-hp/by-merk/{merkId}', [TypeHpController::class, 'getByMerk']);
    Route::get('petugas/harga-hp/by-type/{typeHpId}', [HargaHpController::class, 'getByType']);
    
    // Preview Kalkulasi (untuk bantuan input)
    Route::post('petugas/grade-hp/preview', [GradeHpController::class, 'previewCalculation']);

        // Gadai Ulang (Nasabah Lama)
    Route::post('petugas/gadai/ulang', [GadaiUlangController::class, 'store']);
    
    // Cek Nasabah by NIK (untuk validasi sebelum gadai ulang)
    Route::post('petugas/gadai/ulang/check-nasabah', [GadaiUlangController::class, 'checkNasabah']);
    Route::post('petugas/gadai/ulang-emas', [GadaiUlangEmasController::class, 'store']);
    Route::post('petugas/gadai/ulang-emas/check-nasabah', [GadaiUlangEmasController::class, 'checkNasabah']);
    Route::patch('petugas/perpanjangan-tempo/{id}/bayar', [PerpanjanganTempoController::class, 'bayarPerpanjangan']);
    Route::get('petugas/harian/cetak', [PetugasLaporanController::class, 'cetakLaporanHarian']);
    Route::post('petugas/report/submit', [PetugasLaporanController::class, 'ajukanLaporan']);
    Route::post('petugas/detail-gadai/submit/{id}', [DetailGadaiController::class, 'ajukanSBG']);
    

});

// ================== HM + CHECKER ================== //
// semua method boleh
Route::middleware(['auth:api', 'role:hm'])->group(function () {
    Route::apiResource('data-nasabah', DataNasabahController::class);
    Route::apiResource('type', TypeController::class);
    Route::apiResource('detail-gadai', DetailGadaiController::class);
    Route::apiResource('gadai-hp', GadaiHpController::class);
    Route::apiResource('gadai-perhiasan', GadaiPerhiasanController::class);
    Route::apiResource('gadai-logam-mulia', GadaiLogamMuliaController::class);
    Route::apiResource('gadai-retro', GadaiRetroController::class);
    Route::apiResource('perpanjangan-tempo', PerpanjanganTempoController::class);
    Route::apiResource('kelengkapan', KelengkapanController::class);
    Route::apiResource('kelengkapan-emas', KelengkapanEmasController::class);
    Route::apiResource('kerusakan', KerusakanController::class);
    Route::apiResource('type-hp', TypeHpController::class);
    Route::apiResource('merk-hp', MerkHpController::class);
    Route::apiResource('harga-hp', HargaHpController::class);
    Route::get('harga-hp/type/{typeHpId}', [HargaHpController::class, 'getByType']);
    Route::get('harga-hp/by-type/{typeHpId}', [HargaHpController::class, 'getByType']);
    
    // Grade HP - Full CRUD + Calculation Tools
    Route::apiResource('grade-hp', GradeHpController::class);
    Route::get('grade-hp/harga/{hargaHpId}', [GradeHpController::class, 'getByHargaHp']);
    Route::get('grade-hp/by-merk/{merkId}', [GradeHpController::class, 'getByMerk']);
    Route::get('type-hp/by-merk/{merkId}', [TypeHpController::class, 'getByMerk']);
    Route::get('type-hp/{typeId}/grade', [HargaHpController::class, 'getGradeByType']);
    
    // Grade Calculation Tools
    Route::post('grade-hp/preview', [GradeHpController::class, 'previewCalculation']);
    Route::put('grade-hp/{id}/manual', [GradeHpController::class, 'updateManual']);
    Route::post('grade-hp/recalculate/{hargaHpId}', [GradeHpController::class, 'recalculateByHargaHp']);
    

    Route::post('gadai-wizard', [GadaiWizardController::class, 'store']);
    Route::post('gadai-emas', [GadaiEmasController::class, 'store']);
    Route::patch('detail-gadai/{id}/validasi-selesai', [DetailGadaiController::class, 'validasiSelesai']);
    Route::patch('detail-gadai/{id}/pelunasan', [DetailGadaiController::class, 'pelunasan']);
    Route::patch('perpanjangan-tempo/{id}/bayar', [PerpanjanganTempoController::class, 'bayarPerpanjangan']);


    // Approvals
    Route::get('/approvals', [ApprovalController::class, 'getAll']);
    Route::get('/approvals/checker/approved', [ApprovalController::class, 'getCheckerApproved']); // buat method di controller
    Route::get('/approvals/checker/rejected', [ApprovalController::class, 'getCheckerRejected']);
    Route::get('/approvals/hm/approved', [ApprovalController::class, 'getHmApproved']);
    Route::get('/approvals/hm/rejected', [ApprovalController::class, 'getHmRejected']);
    Route::get('/approvals/selesai', [ApprovalController::class, 'getFinished']);

    // Update status
    Route::post('/approvals/{detailGadaiId}', [ApprovalController::class, 'updateStatus']);
    Route::post('/approvals/{detailGadaiId}/update-detail', [ApprovalController::class, 'updateApprovalDetail']);
    Route::get('/approvals/{detailGadaiId}/full-detail', [ApprovalController::class, 'getApprovalDetail']);

      // dashboard
    Route::get('summary', [DashboardGadaiController::class, 'summary']);
    Route::get('pendapatan-bulanan', [DashboardGadaiController::class, 'pendapatanPerBulan']);
    Route::get('nasabah-bulanan', [DashboardGadaiController::class, 'nasabahPerBulan']);
    Route::get('total-semua', [DashboardGadaiController::class, 'totalSemua']);
    Route::get('/approvals/finished/filter', [ApprovalFilterController::class, 'filterByMonthYear']);
    Route::get('laporan', [AdminApprovalController::class, 'index']);
    Route::get('laporan/detail/{detailGadaiId}', [AdminApprovalController::class, 'detailAdmin']);
    Route::get('dashboard/pelelangan-stats', [DashboardGadaiController::class, 'pelelanganStats']);
    Route::get('dashboard/brankas-stats', [DashboardGadaiController::class, 'brankasDashboard']);
    Route::get('harian/cetak', [LaporanHarianCheckerController::class, 'cetakLaporanHarian']);
    Route::get('harian/cetak-serah-terima', [LaporanHarianCheckerController::class, 'cetakLaporanSerahTerima']);
    Route::get('/cetak-perpanjangan', [LaporanHarianCheckerController::class, 'cetakLaporanPerpanjangan']);
    Route::get('/cetak-brankas', [LaporanHarianCheckerController::class, 'cetakLaporanBrankasHarian']);
    Route::get('/cetak-lelang', [LaporanHarianCheckerController::class, 'cetakLaporanPelelangan']);

    Route::get('pelelangan/history', [PelelanganController::class, 'history']);
    Route::post('pelelangan/daftarkan', [PelelanganController::class, 'daftarkanLelang']);
    Route::post('pelelangan/{detail_gadai_id}/proses', [PelelanganController::class, 'prosesLelang']);
    Route::post('pelelangan/{detail_gadai_id}/lunasi', [PelelanganController::class, 'lunasi']);
    Route::get('pelelangan', [PelelanganController::class, 'index']);
    Route::get('pelelangan/{detail_gadai_id}', [PelelanganController::class, 'show']);
    Route::get('notifications/new', [NotificationController::class, 'getNew']); 
    Route::get('notifications', [NotificationController::class, 'getAll']);
    Route::post('notifications/mark-read', [NotificationController::class, 'markAsRead']);

        // Gadai Ulang (Nasabah Lama)
    Route::post('/gadai/ulang', [GadaiUlangController::class, 'store']);
    
    // Cek Nasabah by NIK (untuk validasi sebelum gadai ulang)
    Route::post('/gadai/ulang/check-nasabah', [GadaiUlangController::class, 'checkNasabah']);

    Route::post('/gadai/ulang-emas', [GadaiUlangEmasController::class, 'store']);
    Route::post('/gadai/ulang-emas/check-nasabah', [GadaiUlangEmasController::class, 'checkNasabah']);

    Route::get('brankas', [BrankasController::class, 'index']);
    Route::get('brankas/riwayat', [BrankasController::class, 'riwayat']);
    Route::post('brankas/transaksi', [BrankasController::class, 'store']);
    Route::patch('brankas/validasi/{id}', [BrankasController::class, 'validasiSetoran']);
    Route::get('dashboard/brankas-chart', [DashboardGadaiController::class, 'brankasYearlyChart']);
    Route::get('manager/approvals/reports', [LaporanHarianCheckerController::class, 'getReportHistory']);
    Route::post('manager/approvals/reports/{doc_id}/approve', [LaporanHarianCheckerController::class, 'approveReport']);
    Route::post('manager/approvals/reports/bulk-approve', [YourController::class, 'bulkApproveReports']);
    Route::post('manager/approve-sbg/{id}', [DetailGadaiController::class, 'approveSBG']);
    Route::get('manager/gadai/list-sbg', [DetailGadaiController::class, 'getListSBGForManager']);
    Route::get('/manager/acc-history', [DetailGadaiController::class, 'getAccHistory']);
    Route::get('/laporan/struk-awal-mingguan', [AdminLaporanMingguanController::class, 'strukAwalMingguan']);
    Route::get('/laporan/rekap-perpanjangan-mingguan', [AdminLaporanMingguanController::class, 'rekapPerpanjanganMingguan']);
    Route::get('/laporan/rekap-pelunasan-mingguan', [AdminLaporanMingguanController::class, 'rekapPelunasanMingguan']);
    Route::get('/rekap-bulanan-lelang', [AdminLaporanMingguanController::class, 'rekapBulananPelelangan']);
    Route::post('hm/gudang/scan', [LaporanGudangController::class, 'scanBarcode']);
    Route::get('hm/gudang/riwayat', [LaporanGudangController::class, 'index']);
    Route::get('hm/gudang/riwayat/{id}', [LaporanGudangController::class, 'show']);
    Route::delete('hm/gudang/riwayat/{id}', [LaporanGudangController::class, 'destroy']);
    Route::get('hm/gudang/report/export', [LaporanGudangController::class, 'exportReport']);
    Route::post('hm/gudang/verifikasi', [LaporanGudangController::class, 'storeVerifikasi']); 
    Route::get('hm/gudang/pending', [LaporanGudangController::class, 'getPendingItems']);

});


//CHECKER
Route::middleware(['auth:api', 'role:checker'])->group(function () {
    Route::apiResource('checker/data-nasabah', DataNasabahController::class);
    Route::apiResource('checker/type', TypeController::class);
    Route::apiResource('checker/detail-gadai', DetailGadaiController::class);
    Route::apiResource('checker/gadai-hp', GadaiHpController::class);
    Route::apiResource('checker/gadai-perhiasan', GadaiPerhiasanController::class);
    Route::apiResource('checker/gadai-logam-mulia', GadaiLogamMuliaController::class);
    Route::apiResource('checker/gadai-retro', GadaiRetroController::class);
    Route::apiResource('checker/perpanjangan-tempo', PerpanjanganTempoController::class);
    Route::get('checker/notifications/new', [NotificationController::class, 'getNew']); 
    Route::get('checker/notifications', [NotificationController::class, 'getAll']);
    Route::post('checker/notifications/mark-read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('checker/kelengkapan', KelengkapanController::class);
    Route::apiResource('checker/kelengkapan-emas', KelengkapanEmasController::class);
    Route::apiResource('checker/kerusakan', KerusakanController::class);
    Route::apiResource('checker/type-hp', TypeHpController::class);
    Route::apiResource('checker/merk-hp', MerkHpController::class);
    Route::apiResource('checker/harga-hp', HargaHpController::class);
    Route::get('checker/harga-hp/type/{typeHpId}', [HargaHpController::class, 'getByType']);
    Route::get('checker/type-hp/by-merk/{merkId}', [TypeHpController::class, 'getByMerk']);
    Route::get('checker/harga-hp/by-type/{typeHpId}', [HargaHpController::class, 'getByType']);
    Route::apiResource('checker/grade-hp', GradeHpController::class);
    Route::get('checker/grade-hp/harga/{hargaHpId}', [GradeHpController::class, 'getByHargaHp']);
    Route::get('checker/grade-hp/by-merk/{merkId}', [GradeHpController::class, 'getByMerk']);
    Route::get('checker/type-hp/{typeId}/grade', [HargaHpController::class, 'getGradeByType']);
    Route::post('checker/grade-hp/preview', [GradeHpController::class, 'previewCalculation']);
    Route::put('checker/grade-hp/{id}/manual', [GradeHpController::class, 'updateManual']);
    Route::post('checker/grade-hp/recalculate/{hargaHpId}', [GradeHpController::class, 'recalculateByHargaHp']);
    Route::post('checker/gadai-wizard', [GadaiWizardController::class, 'store']);
    Route::post('checker/gadai-emas', [GadaiEmasController::class, 'store']);
    Route::patch('checker/detail-gadai/{id}/validasi-selesai', [DetailGadaiController::class, 'validasiSelesai']);
    Route::patch('checker/detail-gadai/{id}/pelunasan', [DetailGadaiController::class, 'pelunasan']);
    Route::patch('checker/perpanjangan-tempo/{id}/bayar', [PerpanjanganTempoController::class, 'bayarPerpanjangan']);

    // Approvals
    Route::get('checker/approvals', [ApprovalController::class, 'getAll']);
    Route::get('checker/approvals/checker/approved', [ApprovalController::class, 'getCheckerApproved']); 
    Route::get('checker/approvals/checker/rejected', [ApprovalController::class, 'getCheckerRejected']);
    Route::get('checker/approvals/hm/approved', [ApprovalController::class, 'getHmApproved']);
    Route::get('checker/approvals/hm/rejected', [ApprovalController::class, 'getHmRejected']);
    Route::get('checker/approvals/selesai', [ApprovalController::class, 'getFinished']);

    // Update status
    Route::post('checker/approvals/{detailGadaiId}', [ApprovalController::class, 'updateStatus']);
    Route::post('checker/approvals/{detailGadaiId}/update-detail', [ApprovalController::class, 'updateApprovalDetailChecker']);
    Route::get('checker/approvals/{detailGadaiId}/full-detail', [ApprovalController::class, 'getApprovalDetail']);
    Route::get('checker/approvals/finished/filter', [ApprovalFilterController::class, 'filterByMonthYear']);
    Route::get('checker/pelelangan/history', [PelelanganController::class, 'history']);
    Route::post('checker/pelelangan/daftarkan', [PelelanganController::class, 'daftarkanLelang']);
    Route::post('checker/pelelangan/{detail_gadai_id}/proses', [PelelanganController::class, 'prosesLelang']);
    Route::post('checker/pelelangan/{detail_gadai_id}/lunasi', [PelelanganController::class, 'lunasi']);
    Route::get('checker/pelelangan', [PelelanganController::class, 'index']);
    Route::get('checker/pelelangan/{detail_gadai_id}', [PelelanganController::class, 'show']);
    Route::post('checker/gadai/ulang', [GadaiUlangController::class, 'store']);
    Route::post('checker/gadai/ulang/check-nasabah', [GadaiUlangController::class, 'checkNasabah']);

    Route::post('checker/gadai/ulang-emas', [GadaiUlangEmasController::class, 'store']);
    Route::post('checker/gadai/ulang-emas/check-nasabah', [GadaiUlangEmasController::class, 'checkNasabah']);
    Route::get('checker/brankas', [BrankasController::class, 'index']);
    // Route::get('checker/brankas/riwayat', [BrankasController::class, 'riwayat']);
    // Route::post('checker/brankas/transaksi', [BrankasController::class, 'store']);
    Route::get('checker/dashboard/brankas-stats', [DashboardGadaiController::class, 'brankasDashboard']);
    Route::get('checker/dashboard/brankas-chart', [DashboardGadaiController::class, 'brankasYearlyChart']);
    Route::get('checker/harian/cetak-serah-terima', [LaporanHarianCheckerController::class, 'cetakLaporanSerahTerima']);
    Route::get('checker/cetak-perpanjangan', [LaporanHarianCheckerController::class, 'cetakLaporanPerpanjangan']);
    Route::get('checker/cetak-brankas', [LaporanHarianCheckerController::class, 'cetakLaporanBrankasHarian']);
    Route::get('checker/cetak-lelang', [LaporanHarianCheckerController::class, 'cetakLaporanPelelangan']);
    Route::post('checker/report/submit', [LaporanHarianCheckerController::class, 'ajukanLaporanChecker']);
    Route::post('checker/detail-gadai/submit/{id}', [DetailGadaiController::class, 'ajukanSBG']);
    

});



Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::apiResource('admin/data-nasabah', DataNasabahController::class);
    Route::apiResource('admin/type', TypeController::class);
    Route::apiResource('admin/detail-gadai', DetailGadaiController::class);
    Route::apiResource('admin/gadai-hp', GadaiHpController::class);
    Route::apiResource('admin/gadai-perhiasan', GadaiPerhiasanController::class);
    Route::apiResource('admin/gadai-logam-mulia', GadaiLogamMuliaController::class);
    Route::apiResource('admin/gadai-retro', GadaiRetroController::class);
    Route::apiResource('admin/perpanjangan-tempo', PerpanjanganTempoController::class);
    Route::get('admin/notifications', [NotificationController::class, 'getNotifications']);
    Route::get('admin/laporan', [AdminApprovalController::class, 'index']);
    Route::get('admin/laporan/detail/{detailGadaiId}', [AdminApprovalController::class, 'detailAdmin']);
    Route::get('admin/notifications/new', [NotificationController::class, 'getNew']); 
    Route::get('admin/notifications', [NotificationController::class, 'getAll']);
    Route::post('admin/notifications/mark-read', [NotificationController::class, 'markAsRead']);

    Route::get('admin/pelelangan', [PelelanganController::class, 'index']);
    Route::get('admin/pelelangan/history', [PelelanganController::class, 'history']);
    Route::get('admin/pelelangan/{detail_gadai_id}', [PelelanganController::class, 'show']);
    Route::get('admin/brankas', [BrankasController::class, 'index']);
    Route::get('admin/brankas/riwayat', [BrankasController::class, 'riwayat']);
    Route::post('admin/brankas/transaksi', [BrankasController::class, 'store']);
    Route::post('admin/brankas/validasi/{id}', [BrankasController::class, 'validasiSetoran']);
    Route::get('admin/dashboard/brankas-stats', [DashboardGadaiController::class, 'brankasDashboard']);
    Route::get('admin/dashboard/brankas-chart', [DashboardGadaiController::class, 'brankasYearlyChart']);
    Route::get('admin/laporan-mingguan', [AdminLaporanMingguanController::class, 'cetakLaporanMingguan']);
    Route::get('admin/laporan/struk-awal-mingguan', [AdminLaporanMingguanController::class, 'strukAwalMingguan']);
    Route::get('admin/laporan/rekap-perpanjangan-mingguan', [AdminLaporanMingguanController::class, 'rekapPerpanjanganMingguan']);
    Route::get('admin/laporan/rekap-pelunasan-mingguan', [AdminLaporanMingguanController::class, 'rekapPelunasanMingguan']);
    Route::get('admin/rekap-bulanan-lelang', [AdminLaporanMingguanController::class, 'rekapBulananPelelangan']);

});

Route::middleware(['auth:api', 'role:gudang'])->group(function () {
    Route::post('gudang/scan', [LaporanGudangController::class, 'scanBarcode']);
    Route::post('gudang/verifikasi', [LaporanGudangController::class, 'storeVerifikasi']); 
    Route::get('gudang/riwayat', [LaporanGudangController::class, 'index']);
    Route::get('gudang/pending', [LaporanGudangController::class, 'getPendingItems']);
    Route::get('gudang/riwayat/{id}', [LaporanGudangController::class, 'show']);
    Route::delete('gudang/riwayat/{id}', [LaporanGudangController::class, 'destroy']);
    Route::get('gudang/report/export', [LaporanGudangController::class, 'exportReport']);
});


Route::middleware(['auth:api', 'role:kasir'])->group(function () {

    Route::get('kasir/brankas', [BrankasController::class, 'index']);
    Route::get('kasir/brankas/riwayat', [BrankasController::class, 'riwayat']);
    Route::post('kasir/brankas/transaksi', [BrankasController::class, 'store']);
    Route::get('kasir/dashboard/brankas-stats', [DashboardGadaiController::class, 'brankasDashboard']);
    Route::get('kasir/dashboard/brankas-chart', [DashboardGadaiController::class, 'brankasYearlyChart']);

});