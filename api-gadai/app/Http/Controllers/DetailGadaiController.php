<?php

namespace App\Http\Controllers;

use App\Models\DetailGadai;
use App\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Services\PelunasanService;
use Illuminate\Support\Facades\DB; 
use Carbon\Carbon;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DetailGadaiController extends Controller
{
    protected $pelunasanService;

    public function __construct(PelunasanService $pelunasanService)
    {
        $this->pelunasanService = $pelunasanService;
    }

    


private function calculateLateDays($item)
{
    $lateDays = 0;
    $endDate = strtolower($item->status) === 'lunas' && !empty($item->tanggal_bayar)
        ? \Carbon\Carbon::parse($item->tanggal_bayar)
        : \Carbon\Carbon::now();

    if (!empty($item->jatuh_tempo)) {
        $jatuhTempo = \Carbon\Carbon::parse($item->jatuh_tempo);
        if ($endDate->gt($jatuhTempo)) {
            $lateDays = (int) $jatuhTempo->diffInDays($endDate, false);
            $lateDays = $lateDays > 0 ? $lateDays : 0;
        }
    }

    return $lateDays;
}

    public function index(Request $request)
{
    $perPage = $request->get('per_page', 10);
    $page = $request->get('page', 1);
    $search = $request->get('search');

    $query = DetailGadai::with([
        'type:id,nama_type',
        'nasabah:id,nama_lengkap,nik,user_id',
        'nasabah.user:id,name,role,email',
        'perpanjanganTempos',
        'hp', 'hp.merk', 'hp.type_hp', 'hp.kerusakanList', 'hp.kelengkapanList',
        'perhiasan', 'perhiasan.kelengkapan',   
        'logamMulia', 'logamMulia.kelengkapanEmas', 
        'retro', 'retro.kelengkapan', 
        'approvals.user:id,name,role',
    ])->orderBy('created_at', 'desc');

    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('no_gadai', 'LIKE', "%{$search}%")
              ->orWhere('no_nasabah', 'LIKE', "%{$search}%")
              ->orWhere('status', 'LIKE', "%{$search}%")
              ->orWhereHas('type', fn($typeQuery) => $typeQuery->where('nama_type', 'LIKE', "%{$search}%"))
              ->orWhereHas('nasabah', fn($nasabahQuery) => 
                  $nasabahQuery->where('nama_lengkap', 'LIKE', "%{$search}%")->orWhere('nik', 'LIKE', "%{$search}%")
              );
        });
    }

    $data = $query->paginate($perPage, ['*'], 'page', $page);
    $data->getCollection()->transform(function ($item) {
        $item->hari_keterlambatan = $this->calculateLateDays($item);
        $item->is_overdue = $item->hari_keterlambatan > 0;

        return $item;
    });

    return response()->json([
        'success' => true,
        'message' => 'Data detail gadai berhasil diambil.',
        'data' => $data->items(),
        'pagination' => [
            'total' => $data->total(),
            'per_page' => $data->perPage(),
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
        ]
    ]);
}


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tanggal_gadai' => 'required|date',
            'jatuh_tempo'   => 'required|date|after_or_equal:tanggal_gadai',
            'type_id'       => 'required|exists:types,id',
            'nasabah_id'    => 'required|exists:data_nasabah,id',
            'taksiran'      => 'required|numeric|min:0',
            'uang_pinjaman' => 'required|numeric|min:0',
            'status'        => 'nullable|in:proses,selesai,lunas',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tanggal = Carbon::parse($request->tanggal_gadai);
        $type = Type::find($request->type_id);

        $lastGadai = DetailGadai::orderBy('id', 'desc')->first();
        $noNasabahNumber = $lastGadai ? (int) substr($lastGadai->no_nasabah, -4) + 1 : 1;
        
        $noNasabah = $tanggal->format('m') . $tanggal->format('y') . str_pad($noNasabahNumber, 4, '0', STR_PAD_LEFT);
        $noGadai = "SGI-{$tanggal->format('d')}-{$tanggal->format('m')}-{$tanggal->format('Y')}-{$type->nomor_type}-" . str_pad($noNasabahNumber, 4, '0', STR_PAD_LEFT);

        $gadai = DetailGadai::create([
            'no_gadai'      => $noGadai,
            'no_nasabah'    => $noNasabah,
            'tanggal_gadai' => $request->tanggal_gadai,
            'jatuh_tempo'   => $request->jatuh_tempo,
            'taksiran'      => $request->taksiran,
            'uang_pinjaman' => $request->uang_pinjaman,
            'type_id'       => $request->type_id,
            'nasabah_id'    => $request->nasabah_id,
            'status'        => $request->status ?? 'proses',
        ]);

        return response()->json(['success' => true, 'data' => $gadai], 201);
    }


    public function validasiSelesai(Request $request, $id)
{
    $gadai = DetailGadai::with(['nasabah', 'user'])->find($id);

    if (!$gadai || $gadai->status !== 'proses') {
        return response()->json([
            'success' => false, 
            'message' => 'Hanya status PROSES yang bisa divalidasi.'
        ], 400);
    }

    $gadai->update(['status' => 'selesai']);

    try {
        $notifService = new \App\Services\NotificationService();
        $notifService->notifyUnitSelesai($gadai);
    } catch (\Exception $e) {
        \Log::error("Gagal kirim notif validasi: " . $e->getMessage());
    }

    return response()->json([
        'success' => true, 
        'message' => 'Unit divalidasi SELESAI. Notifikasi telah dikirim ke petugas.'
    ]);
}

public function pelunasan(Request $request, $id)
{
    $gadai = DetailGadai::with(['nasabah', 'type', 'perpanjangan_tempos'])->find($id);

    if (!$gadai) {
        return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
    }

    if ($gadai->status === 'proses') {
        return response()->json([
            'success' => false,
            'message' => 'Unit belum divalidasi. Status harus SELESAI sebelum pelunasan.'
        ], 400);
    }

    if ($gadai->status === 'lunas') {
        return response()->json(['success' => false, 'message' => 'Gadai sudah lunas sebelumnya'], 400);
    }

    $pelunasanService = new \App\Services\PelunasanService();
    $perhitungan = $pelunasanService->hitungPelunasan($gadai);
    $totalHarusDibayar = (float) $perhitungan['total_bayar'];

    $validator = Validator::make($request->all(), [
        'nominal_bayar'     => 'required|numeric|min:' . $totalHarusDibayar,
        'metode_pembayaran' => 'required|in:cash,transfer',
        'bukti_transfer'    => 'required_if:metode_pembayaran,transfer|nullable|image|max:2048',
    ], [
        'nominal_bayar.min' => 'Uang kurang! Minimal Rp. ' . number_format($totalHarusDibayar, 0, ',', '.'),
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();
    try {
        $updateData = [
            'status'            => 'lunas',
            'nominal_bayar'     => $totalHarusDibayar, 
            'metode_pembayaran' => $request->metode_pembayaran,
            'tanggal_bayar'     => now(),
        ];

        if ($request->metode_pembayaran === 'transfer' && $request->hasFile('bukti_transfer')) {
            $nasabah = $gadai->nasabah;
            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap ?? 'unknown');
            $tipeBarang = strtolower($gadai->type->nama_type ?? 'umum');
            $folderBase = "{$folderNasabah}/{$tipeBarang}/{$gadai->no_gadai}/pelunasan";
            
            $file = $request->file('bukti_transfer');
            $filename = "bukti-lunas-" . time() . "." . $file->getClientOriginalExtension();
            $path = $file->storeAs($folderBase, $filename, 'minio');
            $updateData['bukti_transfer'] = $path;
        }

        $gadai->update($updateData);

        DB::commit();

        try {
            $notifService = new \App\Services\NotificationService();
            $notifService->notifyPelunasan($gadai);
        } catch (\Exception $e) {
            \Log::error("Gagal kirim notif pelunasan: " . $e->getMessage());
        }

        $kembalian = (float)$request->nominal_bayar - $totalHarusDibayar;
        return response()->json([
            'success'   => true,
            'message'   => 'Pelunasan LUNAS berhasil diselesaikan.',
            'data' => [
                'perhitungan'     => $perhitungan,  
                'nominal_dibayar' => (float)$request->nominal_bayar,
                'kembalian'       => $kembalian > 0 ? $kembalian : 0,
                'detail_gadai'    => $gadai->fresh(['nasabah', 'type'])->toArray(),
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Error pelunasan: ' . $e->getMessage());
        return response()->json(['success' => false, 'message' => 'Sistem Error: ' . $e->getMessage()], 500);
    }
}


public function show($id)
{
    $gadai = DetailGadai::with([
        'type', 'nasabah.user', 'hp.merk', 'hp.type_hp', 
        'hp.kerusakanList', 'hp.kelengkapanList', 'hp.dokumenPendukungHp',      
        'perhiasan.kelengkapan', 'perhiasan.dokumenPendukung',    
        'logamMulia.kelengkapanEmas', 'logamMulia.dokumenPendukung',    
        'retro.kelengkapan', 'retro.dokumenPendukung',         
        'approvals.user', 'perpanjangan_tempos' 
    ])->find($id);

    if (!$gadai) {
        return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
    }

    $hariKeterlambatan = $this->calculateLateDays($gadai);
    $pelunasanService = new \App\Services\PelunasanService();
    
    if ($gadai->status === 'lunas') {
        $perhitungan = [
            'pokok'          => (float)$gadai->uang_pinjaman,
            'denda'          => (float)$gadai->nominal_bayar - (float)$gadai->uang_pinjaman - ($hariKeterlambatan > 15 ? 180000 : 0),
            'penalty'        => $hariKeterlambatan > 15 ? 180000 : 0,
            'hari_terlambat' => $hariKeterlambatan,
            'total_bayar'    => (float)$gadai->nominal_bayar,
            'jatuh_tempo'    => $gadai->jatuh_tempo,
            'status_final'   => true
        ];
    } else {
        $perhitungan = $pelunasanService->hitungPelunasan($gadai);
    }

    $isApproved = ($gadai->approval_status === 'approved');
    $qrCodeBase64 = null;
    $qrGudangBase64 = null; 

    if ($isApproved) {
        $verifyUrl = url("/api/v1/verify-sbg/" . $gadai->no_gadai);
        $qrCodeRaw = QrCode::format('png')->size(200)->margin(1)->generate($verifyUrl);
        $qrCodeBase64 = 'data:image/png;base64,' . base64_encode($qrCodeRaw);
        $qrGudangRaw = QrCode::format('png')->size(200)->margin(1)->generate($gadai->no_gadai); 
        $qrGudangBase64 = 'data:image/png;base64,' . base64_encode($qrGudangRaw);

    }

    $dataResponse = $gadai->toArray(); 
    $dataResponse['hari_keterlambatan'] = $hariKeterlambatan;
    $dataResponse['perhitungan'] = $perhitungan; 
    $dataResponse['is_overdue'] = $hariKeterlambatan > 0;
    $dataResponse['is_approved'] = $isApproved; 


    $dataResponse['metadata'] = [
        'qr_code'      => $qrCodeBase64,
        'qr_gudang'    => $qrGudangBase64, 
        'checker_name' => $isApproved ? ($gadai->approvals->where('role', 'checker')->first()->user->name ?? 'Checker SGI') : null,
        'acc_at'       => $isApproved ? $gadai->updated_at->format('d-m-Y H:i') : null,
    ];

    // Dokumen pendukung logic
    if ($gadai->hp && $gadai->hp->dokumenPendukungHp) {
        $dataResponse['dokumen_pendukung_hp'] = $gadai->hp->dokumenPendukungHp;
    }

    $dokumenEmas = null;
    if ($gadai->perhiasan && $gadai->perhiasan->dokumenPendukung) {
        $dokumenEmas = $gadai->perhiasan->dokumenPendukung;
    } elseif ($gadai->logamMulia && $gadai->logamMulia->dokumenPendukung) {
        $dokumenEmas = $gadai->logamMulia->dokumenPendukung;
    } elseif ($gadai->retro && $gadai->retro->dokumenPendukung) {
        $dokumenEmas = $gadai->retro->dokumenPendukung;
    }
    
    if ($dokumenEmas) {
        $dataResponse['dokumen_pendukung_emas'] = $dokumenEmas;
    }

    return response()->json([
        'success' => true, 
        'data' => $dataResponse
    ]);
}

    public function destroy($id)
    {
        $gadai = DetailGadai::find($id);
        if (!$gadai) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        $gadai->delete();
        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus.']);
    }

    public function update(Request $request, $id)
    {
        $gadai = DetailGadai::find($id);

        if (!$gadai) {
            return response()->json([
                'success' => false, 
                'message' => 'Data tidak ditemukan.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'tanggal_gadai' => 'sometimes|required|date',
            'jatuh_tempo'   => 'sometimes|required|date|after_or_equal:tanggal_gadai',
            'type_id'       => 'sometimes|required|exists:types,id',
            'nasabah_id'    => 'sometimes|required|exists:data_nasabah,id',
            'taksiran'      => 'sometimes|required|numeric|min:0',
            'uang_pinjaman' => 'sometimes|required|numeric|min:0',
            'status'        => 'sometimes|nullable|in:proses,selesai,lunas',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $dataUpdate = $request->only([
                'tanggal_gadai', 'jatuh_tempo', 'type_id', 
                'nasabah_id', 'taksiran', 'uang_pinjaman', 'status'
            ]);

            if ($request->has('tanggal_gadai') || $request->has('type_id')) {
                $tanggal = Carbon::parse($request->get('tanggal_gadai', $gadai->tanggal_gadai));
                $type = Type::find($request->get('type_id', $gadai->type_id));
                $suffix = substr($gadai->no_nasabah, -4); 
                $dataUpdate['no_nasabah'] = $tanggal->format('m') . $tanggal->format('y') . $suffix;
                $dataUpdate['no_gadai'] = "SGI-{$tanggal->format('d')}-{$tanggal->format('m')}-{$tanggal->format('Y')}-{$type->nomor_type}-{$suffix}";
            }

            $gadai->update($dataUpdate);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data gadai berhasil diperbarui.',
                'data' => $gadai->load('type', 'nasabah')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Gagal memperbarui data: ' . $e->getMessage()
            ], 500);
        }
    }


public function approveSBG(Request $request, $id)
{
    try {
        $gadai = DetailGadai::findOrFail($id);
        
        if ($gadai->approval_status !== 'pending') {
            return response()->json([
                'success' => false, 
                'message' => 'SBG belum diajukan atau sudah diproses sebelumnya.'
            ], 400);
        }
        
        $gadai->update([
            'approval_status' => 'approved'
        ]);

        return response()->json([
            'success' => true,
            'message' => "Surat Bukti Gadai [{$gadai->no_gadai}] Berhasil di-ACC."
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false, 
            'message' => "Terjadi kesalahan: " . $e->getMessage()
        ], 500);
    }
}

public function ajukanSBG(Request $request, $id)
{
    // Load detail gadai beserta tipenya
    $gadai = DetailGadai::with('type')->findOrFail($id);

    /**
     * SYARAT 1: STATUS HARUS 'selesai'
     * Artinya sudah divalidasi oleh Checker.
     * Jika masih 'proses', berarti Checker belum ACC fisik barangnya.
     */
    if ($gadai->status !== 'selesai') {
        return response()->json([
            'success' => false, 
            'message' => 'SBG belum bisa diajukan. Pastikan Checker sudah memvalidasi (Status harus SELESAI).'
        ], 400);
    }

    $nominal = (float) $gadai->uang_pinjaman;
    $namaType = strtolower($gadai->type->nama_type ?? ''); 
    $isAutoApprove = false;

    // --- LOGIKA AUTO APPROVAL BERDASARKAN NOMINAL ---
    
    // 1. Kategori HP/Handphone (Limit <= 2 Juta)
    if (str_contains($namaType, 'hp') || str_contains($namaType, 'handphone')) {
        if ($nominal <= 2000000) {
            $isAutoApprove = true;
        }
    } 
    // 2. Kategori EMAS (Logam Mulia, Perhiasan, Emas - Limit <= 4 Juta)
    else if (
        str_contains($namaType, 'logam_mulia') || 
        str_contains($namaType, 'retro') || 
        str_contains($namaType, 'emas') || 
        str_contains($namaType, 'perhiasan')
    ) {
        if ($nominal <= 4000000) {
            $isAutoApprove = true;
        }
    }

    // --- EKSEKUSI STATUS APPROVAL ---

    if ($isAutoApprove) {
        // Jika nominal kecil, langsung tembus ke 'approved'
        $gadai->update([
            'approval_status' => 'approved'
        ]);
        $msg = "SBG [{$gadai->no_gadai}] disetujui otomatis oleh sistem.";
    } else {
        // Jika nominal besar, status jadi 'pending' untuk divalidasi Manager (HM)
        $gadai->update([
            'approval_status' => 'pending'
        ]);
        $msg = "SBG [{$gadai->no_gadai}] telah diajukan ke Manager (Limit Besar).";
    }

    return response()->json([
        'success' => true,
        'message' => $msg,
        'is_auto' => $isAutoApprove
    ]);
}

public function getListSBGForManager(Request $request)
{
    $status = $request->get('status', 'pending');

    $query = DetailGadai::with(['nasabah', 'hp', 'type'])
        ->orderBy('updated_at', 'desc');

    if ($status === 'history') {
        $query->where('approval_status', 'approved');
    } else {
        $query->where('approval_status', 'pending');
    }

    $data = $query->get();
    return response()->json(['success' => true, 'data' => $data]);
}

public function getAccHistory(Request $request)
{
    try {
        $query = DetailGadai::with([
            'nasabah:id,nama_lengkap', 
            'type:id,nama_type',
            'hp', 'perhiasan', 'logamMulia', 'retro',
            'approvals.user:id,name'
        ])
        ->where('approval_status', 'approved');

        if ($request->has('tanggal') && !empty($request->tanggal)) {
            $query->whereDate('updated_at', $request->tanggal);
        }

        $history = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'History ACC berhasil diambil.',
            'data' => $history
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil history: ' . $e->getMessage()
        ], 500);
    }
}
public function publicVerifySBG(Request $request, $no_gadai)
{
    $gadai = DetailGadai::with(['nasabah', 'type', 'hp', 'approvals.user'])
        ->where('no_gadai', $no_gadai)
        ->first();

    if (!$gadai || $gadai->approval_status !== 'approved') {
        return "
        <div style='text-align:center; margin-top:50px; font-family:sans-serif; padding: 20px;'>
            <div style='font-size: 80px;'>❌</div>
            <h1 style='color:#c62828;'>DOKUMEN TIDAK VALID</h1>
            <p style='color:#555;'>Maaf, Surat Bukti Gadai dengan nomor <b>$no_gadai</b> tidak ditemukan dalam database resmi kami atau belum mendapatkan persetujuan digital.</p>
            <a href='#' onclick='window.close()' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#1a237e; color:white; text-decoration:none; border-radius:5px;'>Tutup Halaman</a>
        </div>";
    }

    $nasabah  = $gadai->nasabah->nama_lengkap ?? '-';
    $barang   = $gadai->hp ? $gadai->hp->nama_barang : ($gadai->type->nama_type ?? 'Barang Jaminan');
    $manager  = $gadai->approvals->first()->user->name ?? "Manager Operasional SGI"; 
    
    $waktuAcc = $gadai->updated_at->translatedFormat('d F Y H:i');
    $pinjaman = "Rp " . number_format($gadai->uang_pinjaman, 0, ',', '.');

    return "
    <html>
    <head>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>VERIFIKASI SBG | SGI</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #eef2f7; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .card { background: white; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); max-width: 400px; width: 90%; overflow: hidden; }
            .header { background: linear-gradient(135deg, #1a237e 0%, #0d47a1 100%); color: white; padding: 30px 20px; text-align: center; }
            .status-badge { background: #4caf50; color: white; padding: 6px 16px; border-radius: 50px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-top: 15px; display: inline-block; }
            .content { padding: 25px; }
            .info-group { margin-bottom: 18px; border-bottom: 1px solid #f0f0f0; padding-bottom: 10px; }
            .info-group:last-child { border: none; }
            .label { color: #90a4ae; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
            .value { color: #2c3e50; font-weight: 700; font-size: 15px; margin-top: 4px; }
            .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 11px; color: #7f8c8d; border-top: 1px solid #eee; }
            .highlight { color: #1a237e; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='header'>
                <div style='font-size: 45px; margin-bottom: 10px;'>✅</div>
                <h2 style='margin:0; font-size: 18px;'>SBG TERVERIFIKASI</h2>
                <div class='status-badge'>Original Document</div>
            </div>
            <div class='content'>
                <div class='info-group'>
                    <div class='label'>Nomor Surat Gadai</div>
                    <div class='value highlight'>$no_gadai</div>
                </div>
                <div class='info-group'>
                    <div class='label'>Nama Nasabah</div>
                    <div class='value'>$nasabah</div>
                </div>
                <div class='info-group'>
                    <div class='label'>Barang Jaminan</div>
                    <div class='value'>$barang</div>
                </div>
                <div class='info-group'>
                    <div class='label'>Nilai Pinjaman</div>
                    <div class='value' style='color: #d32f2f;'>$pinjaman</div>
                </div>
                <div class='info-group'>
                    <div class='label'>Disetujui Digital Oleh</div>
                    <div class='value'>$manager</div>
                </div>
                <div class='info-group'>
                    <div class='label'>Waktu Persetujuan</div>
                    <div class='value'>$waktuAcc WIB</div>
                </div>
            </div>
            <div class='footer'>
                <strong>PT SENTRA GADAI INDONESIA</strong><br>
                Dokumen ini dihasilkan secara otomatis oleh sistem dan sah secara hukum sebagai bukti transaksi.
            </div>
        </div>
    </body>
    </html>";
}
}