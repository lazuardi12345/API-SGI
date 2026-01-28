<?php

namespace App\Http\Controllers;

use App\Models\LaporanGudang;
use App\Models\DetailGadai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LaporanGudangController extends Controller
{
    public function index(Request $request)
    {
        $tanggal = $request->get('tanggal'); 
        $search  = $request->get('search');

        $query = LaporanGudang::with([
            'user:id,name', 
            'detailGadai.nasabah:id,nama_lengkap',
            'detailGadai.hp.merk', 
            'detailGadai.hp.type_hp', 
            'detailGadai.perhiasan', 
            'detailGadai.logamMulia', 
            'detailGadai.retro'
        ])->orderBy('created_at', 'desc');

        if ($tanggal) {
            $query->whereDate('created_at', $tanggal);
        }

        if ($search) {
            $query->whereHas('detailGadai', function($q) use ($search) {
                $q->where('no_gadai', 'LIKE', "%{$search}%")
                  ->orWhereHas('nasabah', function($n) use ($search) {
                      $n->where('nama_lengkap', 'LIKE', "%{$search}%");
                  });
            });
        }

        $history = $query->paginate($request->get('per_page', 15));

        $formattedData = $history->getCollection()->map(function($log) {
            return [
                'id'               => $log->id,
                'no_gadai'         => $log->detailGadai->no_gadai ?? '-',
                'nasabah'          => $log->detailGadai->nasabah->nama_lengkap ?? '-',
                'barang'           => $this->getNamaBarang($log->detailGadai), 
                'jenis_pergerakan' => $log->jenis_pergerakan,
                'petugas'          => $log->user->name ?? '-',
                'waktu'            => $log->created_at->translatedFormat('d F Y, H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $formattedData,
            'pagination' => [
                'total' => $history->total(),
                'current_page' => $history->currentPage(),
                'last_page'    => $history->lastPage(),
            ]
        ]);
    }

public function scanBarcode(Request $request)
{
    // 1. Validasi input
    $request->validate(['no_gadai' => 'required|string']);

    $input = $request->no_gadai;

    // --- LOGIC PEMBERSIH URL ---
    // Jika input mengandung "http", kita ambil bagian terakhirnya saja
    if (str_contains($input, 'http')) {
        $parts = explode('/', rtrim($input, '/'));
        $input = end($parts);
    }
    // Hapus spasi jika ada
    $input = trim($input);

    // 2. Cari data gadai dengan Nomor Gadai yang sudah bersih
    $gadai = DetailGadai::with(['nasabah', 'hp.merk', 'hp.type_hp', 'perhiasan', 'logamMulia', 'retro'])
        ->where('no_gadai', $input)
        ->first();

    if (!$gadai) {
        return response()->json([
            'success' => false, 
            'message' => "Nomor Gadai [$input] tidak ditemukan!"
        ], 404);
    }

    // 3. Logic Auto-Detect Sesuai Flow: Selesai=Masuk, Lunas=Keluar
    // Sesuai catatan: Flow proses -> selesai -> lunas
    $status = strtolower($gadai->status);
    $aksiOtomatis = '';

    if ($status === 'selesai') {
        $aksiOtomatis = 'masuk';
    } elseif ($status === 'lunas') {
        $aksiOtomatis = 'keluar';
    } else {
        return response()->json([
            'success' => false, 
            'message' => "Unit statusnya masih [$status]. Belum bisa diproses gudang!"
        ], 400);
    }

    // 4. Cek double scan (Mencegah barang masuk/keluar 2x)
    $sudahExist = LaporanGudang::where('detail_gadai_id', $gadai->id)
                    ->where('jenis_pergerakan', $aksiOtomatis)
                    ->exists();

    if ($sudahExist) {
        return response()->json([
            'success' => false, 
            'message' => "Barang ini sudah tercatat " . strtoupper($aksiOtomatis) . " di gudang!"
        ], 400);
    }

    // 5. Return data untuk "Inputisasi" otomatis ke Front-End
    return response()->json([
        'success' => true,
        'message' => 'Data ditemukan. Siap verifikasi ' . strtoupper($aksiOtomatis),
        'data' => [
            'detail_gadai_id' => $gadai->id,
            'no_gadai'        => $gadai->no_gadai,
            'nasabah'         => $gadai->nasabah->nama_lengkap ?? '-',
            'barang'          => $this->getNamaBarang($gadai),
            'detail'          => $this->getDetailSpesifik($gadai),
            'status_gadai'    => strtoupper($gadai->status),
            'aksi'            => $aksiOtomatis // Ini yang menentukan tombol 'Masuk' atau 'Keluar' di React
        ]
    ]);
}

    /**
     * FUNGSI BARU: Eksekusi Simpan ke Tabel Laporan Gudang
     */
public function storeVerifikasi(Request $request)
{


    $request->validate([
        'detail_gadai_id'  => 'required|exists:detail_gadai,id',
        'jenis_pergerakan' => 'required|in:masuk,keluar',
        'keterangan'       => 'nullable|string'
    ]);

    DB::beginTransaction();
    try {
        // CEK APAKAH AUTH TERDETEKSI
        $userId = Auth::id();
        if (!$userId) {
            \Log::error("Verifikasi Gagal: User tidak terautentikasi (Token mungkin hilang/expired)");
            return response()->json(['success' => false, 'message' => 'Sesi login habis, silakan login ulang.'], 401);
        }

        $log = LaporanGudang::create([
            'detail_gadai_id'  => $request->detail_gadai_id,
            'user_id'          => $userId,
            'jenis_pergerakan' => $request->jenis_pergerakan,
            'keterangan'       => $request->keterangan ?? 'Verifikasi via Scan Barcode',
        ]);

        DB::commit();
        \Log::info("Verifikasi Berhasil ID: " . $log->id);

        return response()->json([
            'success' => true,
            'message' => "Berhasil mencatat barang {$request->jenis_pergerakan} gudang.",
            'data'    => $log
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        // LOG ERROR DETAILNYA
        \Log::error("Gagal Simpan Gudang: " . $e->getMessage());
        return response()->json([
            'success' => false, 
            'message' => 'Gagal menyimpan data.',
            'debug'   => $e->getMessage() // Tampilkan pesan error sementara untuk testing
        ], 500);
    }
}

public function getPendingItems(Request $request)
{
    // Kita pake Query Builder biar lebih "galak" dan gak salah nama tabel
    $pending = \App\Models\DetailGadai::with(['nasabah', 'hp', 'perhiasan', 'logamMulia', 'retro'])
        // Kita cari status 'selesai' atau 'lunas' tanpa peduli Huruf Besar/Kecil
        ->whereIn(DB::raw('LOWER(status)'), ['selesai', 'lunas']) 
        ->where(function($query) {
            $query->where(function($q) {
                $q->where(DB::raw('LOWER(status)'), 'selesai')
                  ->whereDoesntHave('laporanGudang', function($sub) {
                      $sub->where('jenis_pergerakan', 'masuk');
                  });
            })
            // ATAU: Tampilkan yang statusnya 'lunas' tapi BELUM keluar gudang
            ->orWhere(function($q) {
                $q->where(DB::raw('LOWER(status)'), 'lunas')
                  ->whereDoesntHave('laporanGudang', function($sub) {
                      $sub->where('jenis_pergerakan', 'keluar');
                  });
            });
        })
        ->orderBy('updated_at', 'desc')
        ->paginate($request->get('per_page', 15));

    $formattedData = $pending->getCollection()->map(function($item) {
        // Cek apakah statusnya lunas atau selesai buat label di React
        $isLunas = strtolower($item->status) === 'lunas';
        
        return [
            'id'       => $item->id,
            'no_gadai' => $item->no_gadai,
            'nasabah'  => $item->nasabah->nama_lengkap ?? '-',
            'barang'   => $this->getNamaBarang($item),
            'status'   => $isLunas ? 'MENUNGGU KELUAR (LUNAS)' : 'MENUNGGU MASUK (SELESAI)', 
            'waktu'    => $item->updated_at->format('d M Y, H:i'),
        ];
    });

    return response()->json([
        'success' => true,
        'data'    => $formattedData,
        'pagination' => [
            'total' => $pending->total(),
            'current_page' => $pending->currentPage(),
            'last_page'    => $pending->lastPage(),
        ]
    ]);
}

    public function show($id)
    {
        $log = LaporanGudang::with([
            'user', 
            'detailGadai.nasabah',
            'detailGadai.hp.merk', 
            'detailGadai.hp.type_hp',
            'detailGadai.perhiasan',
            'detailGadai.logamMulia'
        ])->find($id);

        if (!$log) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan!'], 404);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $log->id,
                'jenis_pergerakan' => $log->jenis_pergerakan,
                'waktu'            => $log->created_at->format('d-m-Y H:i:s'),
                'petugas'          => $log->user->name,
                'gadai' => [
                    'no_gadai' => $log->detailGadai->no_gadai,
                    'status_nasabah' => $log->detailGadai->status,
                    'nasabah' => $log->detailGadai->nasabah->nama_lengkap,
                    'info_barang' => $this->getNamaBarang($log->detailGadai),
                    'detail_spesifik' => $this->getDetailSpesifik($log->detailGadai)
                ],
                'keterangan' => $log->keterangan
            ]
        ]);
    }

    public function destroy($id)
    {
        $log = LaporanGudang::find($id);
        if (!$log) return response()->json(['success' => false, 'message' => 'Data sudah tidak ada.'], 404);
        $log->delete();
        return response()->json(['success' => true, 'message' => 'Riwayat berhasil dihapus.']);
    }

    private function getNamaBarang($gadai) {
        if (!$gadai) return '-';
        if ($gadai->hp) return ($gadai->hp->merk->nama_merk ?? '') . " " . ($gadai->hp->type_hp->nama_type ?? '');
        if ($gadai->perhiasan) return $gadai->perhiasan->nama_barang ?? 'Emas/Perhiasan';
        if ($gadai->logamMulia) return $gadai->logamMulia->nama_barang ?? 'Logam Mulia';
        if ($gadai->retro) return "Barang Retro";
        return 'Barang Umum';
    }

    private function getDetailSpesifik($gadai) {
        if (!$gadai) return '-';
        if ($gadai->hp) return "IMEI: " . ($gadai->hp->imei ?? '-');
        if ($gadai->perhiasan) return "Berat: " . ($gadai->perhiasan->berat ?? 0) . "gr, Kadar: " . ($gadai->perhiasan->kadar ?? 0) . "%";
        if ($gadai->logamMulia) return "Berat: " . ($gadai->logamMulia->berat ?? 0) . "gr, Brand: " . ($gadai->logamMulia->brand ?? '-');
        return "-";
    }

    public function exportReport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $data = LaporanGudang::with(['user', 'detailGadai.nasabah'])
            ->whereBetween('created_at', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay()
            ])
            ->get();

        if ($data->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Tidak ada data.'], 404);
        }

        $report = $data->map(fn($item) => [
            'Waktu'      => $item->created_at->format('d-m-Y H:i'),
            'No Gadai'   => $item->detailGadai->no_gadai ?? '-',
            'Nasabah'    => $item->detailGadai->nasabah->nama_lengkap ?? '-',
            'Barang'     => $this->getNamaBarang($item->detailGadai),
            'Pergerakan' => strtoupper($item->jenis_pergerakan),
            'Petugas'    => $item->user->name ?? '-',
        ]);

        return response()->json(['success' => true, 'data' => $report]);
    }
}