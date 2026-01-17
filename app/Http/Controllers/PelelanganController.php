<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetailGadai;
use App\Models\Pelelangan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PelelanganController extends Controller
{
    /**
     * Daftar barang yang siap dilelang (terlambat >15 hari)
     */
    public function index()
    {
        $today = Carbon::today()->startOfDay();
        // Sesuai aturan: minimal terlambat 15 hari dari jatuh tempo
        $batasMinimalLelang = $today->copy()->subDays(15);

        $lelangables = DetailGadai::with(['nasabah', 'type', 'pelelangan'])
            ->whereDate('jatuh_tempo', '<=', $batasMinimalLelang)
            ->where(function($q) {
                // Tampilkan yang belum ada di tabel lelang ATAU yang sudah ada tapi statusnya masih 'siap'
                $q->whereHas('pelelangan', function($query) {
                    $query->where('status_lelang', 'siap');
                })
                ->orWhereDoesntHave('pelelangan');
            })
            ->get()
            ->map(function ($d) {
                // ✅ PERBAIKAN: Hapus parameter $today, biarkan otomatis
                $kalkulasi = $this->hitungKalkulasi($d);

                return [
                    'id' => $d->id,
                    'pelelangan_id' => $d->pelelangan->id ?? null,
                    'no_gadai' => $d->no_gadai,
                    'nama_nasabah' => $d->nasabah->nama_lengkap ?? '-',
                    'type' => $d->type->nama_type ?? '-',
                    'tanggal_gadai' => $d->tanggal_gadai,
                    'jatuh_tempo' => $d->jatuh_tempo,
                    'hari_terlambat' => (int) $kalkulasi['hari_terlambat'],
                    'uang_pinjaman' => (float) $d->uang_pinjaman,
                    'bunga' => (float) $kalkulasi['bunga'],
                    'penalty' => (float) $kalkulasi['penalty'],
                    'denda' => (float) $kalkulasi['denda'],
                    'total_hutang' => (float) $kalkulasi['total_hutang'],
                    'status_lelang' => $d->pelelangan ? $d->pelelangan->status_lelang : 'belum_terdaftar',
                ];
            });

        return response()->json([
            'success' => true,
            'total_data' => $lelangables->count(),
            'data' => $lelangables
        ]);
    }

    /**
     * Daftarkan barang ke lelang
     */
    public function daftarkanLelang(Request $request)
    {
        $request->validate([
            'detail_gadai_id' => 'required|exists:detail_gadai,id',
        ]);

        $detail = DetailGadai::find($request->detail_gadai_id);

        if ($detail->pelelangan) {
            return response()->json([
                'success' => false,
                'message' => 'Barang sudah terdaftar di lelang'
            ], 400);
        }

        $pelelangan = Pelelangan::create([
            'detail_gadai_id' => $detail->id,
            'status_lelang' => 'siap',
            'keterangan' => 'Barang terdaftar untuk dilelang',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Barang berhasil masuk daftar lelang',
            'data' => $pelelangan
        ]);
    }

    /**
     * Proses lelang (Terjual ke Pihak Ketiga)
     */
    public function prosesLelang(Request $request, $detailGadaiId)
    {
        $request->validate([
            'nominal_diterima' => 'required|numeric|min:1',
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer' => 'required_if:metode_pembayaran,transfer|image|max:2048',
            'keterangan' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $pelelangan = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type'])
                ->where('detail_gadai_id', $detailGadaiId)
                ->firstOrFail();

            if ($pelelangan->status_lelang !== 'siap') {
                return response()->json(['success' => false, 'message' => 'Hanya status "siap" yang bisa diproses'], 400);
            }

            $gadai = $pelelangan->detailGadai;
            $kalkulasi = $this->hitungKalkulasi($gadai, Carbon::today());
            $nominalDiterima = (float) $request->nominal_diterima;
            $keuntungan = $nominalDiterima - $kalkulasi['total_hutang'];

            $pathMinio = null;
            if ($request->metode_pembayaran === 'transfer' && $request->hasFile('bukti_transfer')) {
                $nasabah = $gadai->nasabah;
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
                $tipeBarang = strtolower($gadai->type->nama_type ?? 'umum');
                
                // Path: Nama_Nasabah/tipe/no_gadai/pelelangan/
                $folderBase = "{$folderNasabah}/{$tipeBarang}/{$gadai->no_gadai}/pelelangan";
                
                $file = $request->file('bukti_transfer');
                $filename = "bukti-lelang-" . ($nasabah->nik ?? 'no-nik') . "-" . time() . "." . $file->getClientOriginalExtension();

                // Store ke disk MINIO
                $pathMinio = $file->storeAs($folderBase, $filename, 'minio');
            }

            $pelelangan->update([
                'status_lelang' => 'terlelang',
                'nominal_diterima' => $nominalDiterima,
                'keuntungan_lelang' => $keuntungan,
                'metode_pembayaran' => $request->metode_pembayaran,
                'waktu_bayar' => now(),
                'bukti_transfer' => $pathMinio,
                'keterangan' => $request->keterangan ?? 'Barang terjual lelang',
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Barang berhasil terlelang', 'data' => $pelelangan]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Lunasi (Ditebus oleh Nasabah sendiri)
     */
    public function lunasi(Request $request, $detailGadaiId)
    {
        $request->validate([
            'nominal_diterima' => 'required|numeric|min:1',
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer' => 'required_if:metode_pembayaran,transfer|image|max:2048',
            'catatan' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            // Tambahkan eager loading 'detailGadai.type' agar bisa mengambil nama_type
            $pelelangan = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type'])
                ->where('detail_gadai_id', $detailGadaiId)
                ->firstOrFail();

            if ($pelelangan->status_lelang !== 'siap') {
                return response()->json(['success' => false, 'message' => 'Hanya status "siap" yang bisa dilunasi'], 400);
            }

            $gadai = $pelelangan->detailGadai;
            $pathMinio = null;

            if ($request->metode_pembayaran === 'transfer' && $request->hasFile('bukti_transfer')) {
                $nasabah = $gadai->nasabah;
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap ?? 'unknown');
                
                $tipeBarang = strtolower($gadai->type->nama_type ?? 'umum');
                $folderBase = "{$folderNasabah}/{$tipeBarang}/{$gadai->no_gadai}/pelunasan";
                
                $file = $request->file('bukti_transfer');
                $filename = "bukti-pelunasan-" . ($nasabah->nik ?? 'no-nik') . "-" . time() . "." . $file->getClientOriginalExtension();
                $pathMinio = $file->storeAs($folderBase, $filename, 'minio');
            }

            $pelelangan->update([
                'status_lelang' => 'lunas',
                'nominal_diterima' => (float) $request->nominal_diterima,
                'keuntungan_lelang' => 0,
                'metode_pembayaran' => $request->metode_pembayaran,
                'waktu_bayar' => now(),
                'bukti_transfer' => $pathMinio,
                'keterangan' => $request->catatan ?? 'Ditebus nasabah (lelang dibatalkan)',
            ]);

            // Update status di tabel utama detail_gadai menjadi lunas
            $gadai->update(['status' => 'lunas']);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pelunasan berhasil', 'data' => $pelelangan]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Riwayat Transaksi
     */
public function history()
{
    $history = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type'])
        ->whereIn('status_lelang', ['terlelang', 'lunas'])
        ->orderBy('waktu_bayar', 'desc')
        ->get()
        ->map(function ($p) {

            if (!$p->detailGadai) {
                return null;
            }

            $urlBukti = $p->bukti_transfer ? url('/api/files/' . $p->bukti_transfer) : null;


            $kalkulasi = $this->hitungKalkulasi($p->detailGadai, $p->waktu_bayar);

            return [
                'id' => $p->id,
                'no_gadai' => $p->detailGadai->no_gadai ?? '-',
                'nama_nasabah' => $p->detailGadai->nasabah->nama_lengkap ?? '-',
                'type' => $p->detailGadai->type->nama_type ?? '-',
                'jatuh_tempo' => $p->detailGadai->jatuh_tempo,
                'hari_terlambat' => (int) $kalkulasi['hari_terlambat'],
                'uang_pinjaman' => (float) $p->detailGadai->uang_pinjaman,
                'bunga' => (float) $kalkulasi['bunga'],
                'penalty' => (float) $kalkulasi['penalty'],
                'denda' => (float) $kalkulasi['denda'],
                'hutang' => (float) $kalkulasi['total_hutang'], 
                'nominal_masuk' => (float) $p->nominal_diterima,
                'keuntungan' => (float) $p->keuntungan_lelang,
                'status' => $p->status_lelang,
                'metode' => $p->metode_pembayaran,
                'tanggal' => $p->waktu_bayar 
                    ? $p->waktu_bayar->timezone('Asia/Jakarta')->format('d-m-Y H:i') 
                    : '-',
                'keterangan' => $p->keterangan ?? '-',
                'bukti' => $urlBukti, 
            ];
        })
        ->filter() 
        ->values(); 

    return response()->json(['success' => true, 'data' => $history]);
}

public function show($detailGadaiId)
{
    // 1. Ambil data lelang
    $pelelangan = Pelelangan::with([
        'detailGadai.nasabah', 
        'detailGadai.type', 
        'detailGadai.hp', 
        'detailGadai.perhiasan'
    ])->where('detail_gadai_id', $detailGadaiId)->firstOrFail();

    // 2. VALIDASI KRUSIAL: Pastikan relasi detailGadai ada
    $gadai = $pelelangan->detailGadai;
    
    if (!$gadai) {
        return response()->json([
            'success' => false,
            'message' => 'Data detail gadai terkait sudah tidak ditemukan atau dihapus.'
        ], 404);
    }

    // 3. Kalkulasi (Gunakan variabel $gadai agar lebih bersih)
    $kalkulasi = $this->hitungKalkulasi($gadai, $pelelangan->waktu_bayar ?? now());

    // 4. Ambil Foto Barang dengan Null Coalescing yang aman
    $fotoBarang = null;
    if ($gadai->hp) {
        $fotoBarang = $gadai->hp->foto_unit;
    } elseif ($gadai->perhiasan) {
        $fotoBarang = $gadai->perhiasan->foto_barang;
    }

    return response()->json([
        'success' => true,
        'data' => [
            'pelelangan' => $pelelangan, 
            'kalkulasi' => $kalkulasi,
            'foto_barang' => $fotoBarang
        ]
    ]);
}

    /**
     * Fungsi Internal Kalkulasi Hutang
     * ✅ SINKRONISASI DENGAN AdminApprovalController
     */
    public function hitungKalkulasi($detail, $tanggalAcuan = null)
    {
        // 1. Parsing Tanggal & Reset Jam ke 00:00:00 agar SINKRON
        $tglGadai = Carbon::parse($detail->tanggal_gadai)->startOfDay();
        $tglJatuhTempo = Carbon::parse($detail->jatuh_tempo)->startOfDay();

        // 2. LOGIKA TENOR PAKET (Proteksi data agar tetap 15 atau 30 hari)
        $selisihHariAsli = (int) $tglGadai->diffInDays($tglJatuhTempo);
        $tenorPilihan = ($selisihHariAsli <= 20) ? 15 : 30;

        // 3. LOGIKA KUNCI TANGGAL ACUAN (SINKRON DENGAN AdminApprovalController)
        if (!$tanggalAcuan) {
            $status = strtolower($detail->status);
            // Jika status sudah final, kunci di tanggal bayar
            if (in_array($status, ['lunas', 'terlelang', 'selesai'])) {
                $tanggalAcuan = $detail->tanggal_bayar 
                    ? Carbon::parse($detail->tanggal_bayar)->startOfDay() 
                    : Carbon::parse($detail->updated_at)->startOfDay();
            } else {
                // Jika masih aktif/siap lelang, hitung sampai HARI INI
                $tanggalAcuan = Carbon::today()->startOfDay();
            }
        } else {
            $tanggalAcuan = Carbon::parse($tanggalAcuan)->startOfDay();
        }

        // 4. Hitung Hari Terlambat
        $hariTerlambat = 0;
        if ($tanggalAcuan->gt($tglJatuhTempo)) {
            $hariTerlambat = (int) $tglJatuhTempo->diffInDays($tanggalAcuan);
        }
        
        // 5. Biaya & Bunga
        $penalty = 180000;
        $diffBulan = (int) $tglGadai->diffInMonths($tanggalAcuan);
        $bulanGadai = max($diffBulan, 1);
        $bunga = $detail->uang_pinjaman * 0.01 * $bulanGadai;
        
        // 6. Hitung Denda (HP: 0.3%, Lainnya: 0.15%)
        $denda = 0;
        if ($hariTerlambat > 0) {
            $jenisBarang = strtolower($detail->type->nama_type ?? '');
            $rateDenda = (str_contains($jenisBarang, 'hp') || str_contains($jenisBarang, 'handphone')) ? 0.003 : 0.0015;
            $denda = $detail->uang_pinjaman * $rateDenda * $hariTerlambat;
        }

        $totalRaw = $detail->uang_pinjaman + $bunga + $penalty + $denda;

        return [
            'tenor_pilihan' => $tenorPilihan . " Hari",
            'hari_terlambat' => $hariTerlambat,
            'bunga' => (int) round($bunga),
            'penalty' => (int) $penalty,
            'denda' => (float) round($denda, 2),
            'total_hutang' => (int) (ceil($totalRaw / 1000) * 1000), // Pembulatan ribuan ke atas
            'jatuh_tempo' => $tglJatuhTempo->format('Y-m-d'),
            'tanggal_hitung' => $tanggalAcuan->format('Y-m-d')
        ];
    }
}