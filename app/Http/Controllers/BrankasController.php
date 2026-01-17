<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiBrankas; 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Exception;
use Carbon\Carbon;

class BrankasController extends Controller
{
 
public function index()
{
    // Ambil baris terakhir berdasarkan ID terbesar untuk mendapatkan saldo paling update
    $terakhir = TransaksiBrankas::orderBy('id', 'desc')->first();
    
    $saldoToko = (float) ($terakhir->saldo_akhir ?? 0);
    $saldoBank = (float) ($terakhir->saldo_akhir_rekening ?? 0);

    $stats = TransaksiBrankas::selectRaw("
        SUM(CASE WHEN kategori = 'topup_pusat' AND status_validasi = 'tervalidasi' THEN pemasukan ELSE 0 END) as modal_pusat,
        SUM(CASE WHEN kategori = 'setor_ke_admin' AND status_validasi = 'tervalidasi' THEN pengeluaran ELSE 0 END) as setoran_lunas,
        SUM(CASE WHEN status_validasi = 'pending' THEN 
            CASE WHEN pemasukan > 0 THEN pemasukan ELSE pengeluaran END 
        ELSE 0 END) as setoran_pending
    ")->first();

    return response()->json([
        'success' => true,
        'summary' => [
            'saldo_toko_saat_ini' => $saldoToko,
            'saldo_rekening_saat_ini' => $saldoBank, 
            'total_modal_dari_pusat' => (float) ($stats->modal_pusat ?? 0),
            'total_setoran_ke_admin' => (float) ($stats->setoran_lunas ?? 0),
            'total_setoran_pending' => (float) ($stats->setoran_pending ?? 0),
        ]
    ]);
}

    public function store(Request $request)
    {
        $request->validate([
            'kategori' => 'required|in:topup_pusat,operasional_toko,setor_ke_admin',
            'tipe_operasional' => 'required_if:kategori,operasional_toko|in:masuk,keluar',
            'metode' => 'required|in:cash,transfer',
            'nominal' => 'required|numeric|min:1',
            'deskripsi' => 'required|string|max:255',
            'bukti_transaksi' => 'required_if:metode,transfer|nullable|image|max:2048',
        ]);

        DB::beginTransaction();
        try {
            // Ambil saldo terakhir untuk dibawa ke transaksi baru
            $last = TransaksiBrankas::orderBy('id', 'desc')->lockForUpdate()->first();
            $saldoAwalTunai = $last ? (float)$last->saldo_akhir : 0;
            $saldoAwalBank = $last ? (float)$last->saldo_akhir_rekening : 0;

            $p = 0; $q = 0;
            $status = 'tervalidasi'; 

            if ($request->kategori === 'setor_ke_admin') {
                $q = $request->nominal; 
                if ($request->metode === 'transfer') $status = 'pending';
            } elseif ($request->kategori === 'topup_pusat') {
                $p = $request->nominal;
                if ($request->metode === 'transfer') $status = 'pending';
            } elseif ($request->kategori === 'operasional_toko') {
                if ($request->tipe_operasional === 'masuk') $p = $request->nominal;
                else $q = $request->nominal;
            }

            // HITUNG SALDO AKHIR
            $saldoAkhirTunai = $saldoAwalTunai;
            $saldoAkhirBank = $saldoAwalBank; // Bawa saldo bank sebelumnya agar tidak jadi 0

            // Jika setor ke admin, saldo cash HARUS langsung berkurang (karena uang sudah dilepas)
            if ($request->kategori === 'setor_ke_admin') {
                if ($q > $saldoAwalTunai) throw new Exception("Saldo TUNAI tidak mencukupi!");
                $saldoAkhirTunai = $saldoAwalTunai - $q;
            } 
            // Jika operasional cash lainnya
            elseif ($request->metode === 'cash') {
                $saldoAkhirTunai = $saldoAwalTunai + $p - $q;
                if ($q > $saldoAwalTunai) throw new Exception("Saldo TUNAI tidak mencukupi!");
            }

            $transaksi = TransaksiBrankas::create([
                'user_id' => Auth::id(),
                'deskripsi' => $request->deskripsi,
                'kategori' => $request->kategori,
                'metode' => $request->metode,
                'pemasukan' => $p,
                'pengeluaran' => $q,
                'saldo_awal' => $saldoAwalTunai,
                'saldo_akhir' => $saldoAkhirTunai,
                'saldo_awal_rekening' => $saldoAwalBank,
                'saldo_akhir_rekening' => $saldoAkhirBank, // Simpan saldo bank terakhir (masih tetap)
                'status_validasi' => $status,
                'bukti_transaksi' => $request->hasFile('bukti_transaksi') 
                    ? $request->file('bukti_transaksi')->store("brankas/{$request->kategori}/" . date('Y/m'), 'minio') 
                    : null,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'data' => $transaksi]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function validasiSetoran(Request $request, $id)
    {
        $request->validate([
            'deskripsi_validasi' => 'required|string',
            'bukti_mutasi' => 'required|image|max:2048' 
        ]);

        DB::beginTransaction();
        try {
            $transaksi = TransaksiBrankas::lockForUpdate()->findOrFail($id);
            if ($transaksi->status_validasi === 'tervalidasi') {
                throw new Exception("Transaksi ini sudah divalidasi sebelumnya!");
            }

            $pathMutasi = $request->file('bukti_mutasi')->store("brankas/mutasi/" . date('Y/m'), 'minio');

            if ($transaksi->metode === 'transfer') {
                // Nominal yang akan menambah saldo bank
                $nominalMasuk = ($transaksi->kategori === 'setor_ke_admin') ? $transaksi->pengeluaran : $transaksi->pemasukan;
                
                $saldoBaru = $transaksi->saldo_awal_rekening + $nominalMasuk;

                // 1. UPDATE BARIS INI
                $transaksi->update([
                    'status_validasi' => 'tervalidasi',
                    'validator_id' => Auth::id(),
                    'bukti_validasi' => $pathMutasi,
                    'catatan_admin' => $request->deskripsi_validasi,
                    'saldo_akhir_rekening' => $saldoBaru
                ]);

                // 2. UPDATE SEMUA BARIS SETELAHNYA (PENTING!)
                // Menggunakan DB::raw agar saldo mengalir sampai ke transaksi terbaru
                TransaksiBrankas::where('id', '>', $id)->update([
                    'saldo_awal_rekening' => DB::raw("saldo_awal_rekening + $nominalMasuk"),
                    'saldo_akhir_rekening' => DB::raw("saldo_akhir_rekening + $nominalMasuk")
                ]);

                DB::commit();
                return response()->json([
                    'success' => true, 
                    'message' => 'Lunas! Saldo bank diperbarui.',
                    'data' => ['saldo_sekarang' => $saldoBaru]
                ]);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


public function showFile($kategori, $year, $month, $filename)
{
    $path = "brankas/{$kategori}/{$year}/{$month}/{$filename}";

    if (!Storage::disk('minio')->exists($path)) {
        abort(404);
    }

    $file = Storage::disk('minio')->get($path);
    $type = Storage::disk('minio')->mimeType($path);

    return response($file)->header('Content-Type', $type);
}

public function riwayat(Request $request)
{
    try {
        // Ambil query dasar dengan Eager Loading
        $query = TransaksiBrankas::with(['user', 'validator']);

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [
                $request->start_date . ' 00:00:00', 
                $request->end_date . ' 23:59:59'
            ]);
        }
        
        $totalPemasukan = (float) (clone $query)->sum('pemasukan');
        $totalPengeluaran = (float) (clone $query)->sum('pengeluaran');
        $data = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'success' => true,
            'grand_total' => [
                'pemasukan_keseluruhan' => $totalPemasukan,
                'pengeluaran_keseluruhan' => $totalPengeluaran,
                'saldo_netto' => $totalPemasukan - $totalPengeluaran,
            ],
            'riwayat' => collect($data->items())->map(function($item) {
                return [
                    'id' => $item->id,
                    'waktu' => $item->created_at->format('d-m-Y H:i'),
                    'petugas' => $item->user->name ?? 'System',
                    'kategori' => $item->kategori,
                    'deskripsi' => $item->deskripsi,
                    'pemasukan' => (float) $item->pemasukan,
                    'pengeluaran' => (float) $item->pengeluaran,
                    'saldo_akhir_cash' => (float) $item->saldo_akhir,
                    'saldo_akhir_rekening' => (float) $item->saldo_akhir_rekening,
                    'metode' => $item->metode,
                    'status' => $item->status_validasi,
                    'bukti_toko' => $item->bukti_transaksi ? url('/api/files/' . $item->bukti_transaksi) : null,
                    'bukti_admin' => $item->bukti_validasi ? url('/api/files/' . $item->bukti_validasi) : null,
                    'catatan_admin' => $item->catatan_admin,
                    'validator_name' => $item->validator->name ?? null
                ];
            }),
            'pagination' => [
                'current_page' => $data->currentPage(), 
                'last_page' => $data->lastPage(),
                'total' => $data->total()
            ]
        ]);
    } catch (Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
}