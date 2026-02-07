<?php

namespace App\Http\Controllers;

use App\Models\LaporanGudang;
use App\Models\DetailGadai;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\ReportPrint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LaporanGudangController extends Controller
{

    use \App\Traits\ReportHelper; 

   public function index(Request $request)
    {
        $tanggal = $request->tanggal ?? Carbon::today()->toDateString();
        
        // 1. Cek Status Approval dari Tabel ReportPrint
        $existing = ReportPrint::where('report_type', 'gudang')
            ->where('report_date', $tanggal)
            ->first();

        $isApproved = $existing ? (bool)$existing->is_approved : false;
        $namaManager = $existing ? $existing->approved_by : null;
        $docId = $existing ? $existing->doc_id : null;
        
        $qrCode = ($isApproved && $namaManager && $docId) 
            ? $this->generateReportQr("Laporan Mutasi Gudang", $tanggal, $docId, $namaManager) : null;

        $query = LaporanGudang::with([
            'petugasGudang:id,name', 
            'penerima:id,name',
            'detailGadai.nasabah:id,nama_lengkap',
            'detailGadai.hp.merk', 'detailGadai.hp.type_hp', 
            'detailGadai.perhiasan', 'detailGadai.logamMulia'
        ])->whereDate('created_at', $tanggal)->orderBy('created_at', 'desc');

        $history = $query->get();

        $formattedData = $history->map(fn($log) => [
            'id'               => $log->id,
            'no_gadai'         => $log->detailGadai->no_gadai ?? '-',
            'nasabah'          => $log->detailGadai->nasabah->nama_lengkap ?? '-',
            'barang'           => $this->getNamaBarang($log->detailGadai), 
            'jenis_pergerakan' => strtoupper($log->jenis_pergerakan),
            'penyerah'         => $log->petugasGudang->name ?? '-',
            'penerima'         => $log->penerima->name ?? '-',
            'waktu'            => $log->created_at->translatedFormat('H:i'),
        ]);

        return response()->json([
            'success' => true,
            'metadata' => [
                'halaman' => 6, // Halaman baru setelah Brankas
                'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                'is_approved' => $isApproved,
                'approved_by' => $namaManager,
                'doc_id' => $docId,
                'qr_code' => $qrCode,
                'total_mutasi' => $formattedData->count()
            ],
            'data' => $formattedData
        ]);
    }


    public function ajukanLaporanGudang(Request $request)
    {
        try {
            $tanggal = $request->report_date ?? date('Y-m-d');
            
            DB::beginTransaction();
            ReportPrint::updateOrCreate(
                ['report_type' => 'gudang', 'report_date' => $tanggal],
                [
                    'doc_id'      => 'REP-GDG-' . date('Ymd') . '-' . strtoupper(Str::random(5)),
                    'printed_by'  => auth()->user()->name,
                    'is_approved' => false,
                    'printed_at'  => now(),
                    'ip_address'  => $request->ip(), 
                ]
            );
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Laporan Mutasi Gudang berhasil diajukan!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function scanBarcode(Request $request)
    {
        $request->validate(['no_gadai' => 'required|string']);
        $input = trim($request->no_gadai);
        if (str_contains($input, 'http')) {
            $input = basename(parse_url($input, PHP_URL_PATH));
        }

        $gadai = DetailGadai::with(['nasabah', 'hp.merk', 'hp.type_hp', 'perhiasan', 'logamMulia'])
            ->where('no_gadai', $input)
            ->first();

        if (!$gadai) {
            return response()->json(['success' => false, 'message' => "Nomor Gadai [$input] tidak ditemukan!"], 404);
        }

        $status = strtolower($gadai->status);
        if ($status === 'selesai') {
            $aksi = 'masuk';
        } elseif ($status === 'lunas') {
            $aksi = 'keluar';
        } else {
            return response()->json(['success' => false, 'message' => "Status unit [$status]. Belum bisa diproses gudang!"], 400);
        }

        $exists = LaporanGudang::where('detail_gadai_id', $gadai->id)->where('jenis_pergerakan', $aksi)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => "Barang ini sudah tercatat ".strtoupper($aksi)." di gudang!"], 400);
        }

        $userLogin = Auth::user();
        $daftarUsers = $this->getListUsers();

        return response()->json([
            'success' => true,
            'data' => [
                'detail_gadai_id' => $gadai->id,
                'no_gadai'        => $gadai->no_gadai,
                'nasabah'         => $gadai->nasabah->nama_lengkap ?? '-',
                'barang'          => $this->getNamaBarang($gadai),
                'detail'          => $this->getDetailSpesifik($gadai),
                'status_gadai'    => strtoupper($gadai->status),
                'aksi'            => $aksi,
                'penyerah'        => [
                    'id'   => $userLogin->id,
                    'name' => $userLogin->name,
                    'role' => $userLogin->role_name ?? ucfirst($userLogin->role)
                ],
                'list_users'      => $daftarUsers  // ← LIST USER LANGSUNG DI SINI!
            ]
        ]);
    }

    /**
     * Simpan Verifikasi Akhir
     */
    public function storeVerifikasi(Request $request)
    {
        $request->validate([
            'detail_gadai_id'  => 'required|exists:detail_gadai,id',
            'penerima_id'      => 'required|exists:users,id',
            'jenis_pergerakan' => 'required|in:masuk,keluar',
            'keterangan'       => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $log = LaporanGudang::create([
                'detail_gadai_id'  => $request->detail_gadai_id,
                'user_id'          => Auth::id(), // PENYERAH (Yang melakukan scan)
                'penerima_id'      => $request->penerima_id, // PENERIMA (Yang dipilih dari dropdown)
                'jenis_pergerakan' => $request->jenis_pergerakan,
                'keterangan'       => $request->keterangan ?? 'Verifikasi via Scan Barcode',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Barang berhasil dicatat sebagai ".strtoupper($request->jenis_pergerakan),
                'data'    => $log
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()], 500);
        }
    }

    // --- Private Helpers ---

    /**
     * PRIVATE FUNCTION - Ambil daftar user untuk dropdown
     */
    private function getListUsers()
    {
        try {
            Log::info('🔍 Mengambil daftar user dari database...');
            
            $users = User::select('id', 'name', 'role')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role_label' => $user->role_name ?? ucfirst($user->role)
                    ];
                })
                ->toArray();
            
            Log::info('✅ Berhasil ambil ' . count($users) . ' user');
            return $users;
            
        } catch (\Exception $e) {
            Log::error('❌ Error saat ambil list users: ' . $e->getMessage());
            return []; // Return array kosong jika error
        }
    }

    private function getNamaBarang($gadai) {
        if (!$gadai) return '-';
        if ($gadai->hp) return ($gadai->hp->merk->nama_merk ?? '') . " " . ($gadai->hp->type_hp->nama_type ?? '');
        if ($gadai->perhiasan) return $gadai->perhiasan->nama_barang ?? 'Perhiasan';
        if ($gadai->logamMulia) return $gadai->logamMulia->nama_barang ?? 'Logam Mulia';
        return 'Barang Umum';
    }

    private function getDetailSpesifik($gadai) {
        if (!$gadai) return '-';
        if ($gadai->hp) return "IMEI: " . ($gadai->hp->imei ?? '-');
        if ($gadai->perhiasan) return "Berat: {$gadai->perhiasan->berat}gr, Kadar: {$gadai->perhiasan->kadar}%";
        if ($gadai->logamMulia) return "Berat: {$gadai->logamMulia->berat}gr, Brand: {$gadai->logamMulia->brand}";
        return "-";
    }

    public function show($id)
    {
        $log = LaporanGudang::with([
            'petugasGudang:id,name,role', 
            'penerima:id,name,role',
            'detailGadai.nasabah:id,nama_lengkap',
            'detailGadai.hp.merk', 
            'detailGadai.hp.type_hp',
            'detailGadai.perhiasan',
            'detailGadai.logamMulia'
        ])->find($id);

        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan!'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $log->id,
                'jenis_pergerakan' => strtoupper($log->jenis_pergerakan),
                'waktu'            => $log->created_at->format('d-m-Y H:i:s'),
                'penyerah'         => [
                    'name' => $log->petugasGudang->name ?? '-',
                    'role' => $log->petugasGudang->role_name ?? '-'
                ],
                'penerima'         => [
                    'name' => $log->penerima->name ?? '-',
                    'role' => $log->penerima->role_name ?? '-'
                ],
                'gadai' => [
                    'no_gadai'       => $log->detailGadai->no_gadai,
                    'status_nasabah' => strtoupper($log->detailGadai->status),
                    'nasabah'        => $log->detailGadai->nasabah->nama_lengkap,
                    'info_barang'    => $this->getNamaBarang($log->detailGadai),
                    'detail_spesifik'=> $this->getDetailSpesifik($log->detailGadai)
                ],
                'keterangan' => $log->keterangan
            ]
        ]);
    }

    public function destroy($id)
    {
        try {
            $log = LaporanGudang::findOrFail($id);
            $log->delete();

            return response()->json([   
                'success' => true, 
                'message' => 'Riwayat gudang berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Gagal menghapus data atau data tidak ditemukan.'
            ], 404);
        }
    }
}