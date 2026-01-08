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
    /**
     * Dashboard Ringkas (Untuk Card di React)
     */
    public function index()
    {
        try {
            $terakhir = TransaksiBrankas::orderBy('id', 'desc')->first();
            $bulanSekarang = now()->month;
            $tahunSekarang = now()->year;

            return response()->json([
                'success' => true,
                'summary' => [
                    'saldo_toko_saat_ini' => (float) ($terakhir->saldo_akhir ?? 0),
                    'saldo_rekening_saat_ini' => (float) ($terakhir->saldo_akhir_rekening ?? 0),
                    'total_modal_dari_pusat' => (float) TransaksiBrankas::where('kategori', 'topup_pusat')->sum('pemasukan'),
                    'total_setoran_ke_admin' => (float) TransaksiBrankas::where('kategori', 'setor_ke_admin')->where('status_validasi', 'tervalidasi')->sum('pengeluaran'),
                    'total_setoran_pending' => (float) TransaksiBrankas::where('kategori', 'setor_ke_admin')->where('status_validasi', 'pending')->sum('pengeluaran'),
                ],
                'info' => [
                    'bulan' => now()->locale('id')->monthName,
                    'tahun' => $tahunSekarang
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Store Transaksi (HM/Toko Input)
     */
    public function store(Request $request)
    {
        $request->validate([
            'kategori' => 'required|in:topup_pusat,operasional_toko,setor_ke_admin,info_saldo',
            'tipe_operasional' => 'required_if:kategori,operasional_toko,info_saldo|in:masuk,keluar',
            'metode' => 'required|in:cash,transfer',
            'nominal' => 'required|numeric|min:1',
            'deskripsi' => 'required|string|max:255',
            'bukti_transaksi' => 'required_if:metode,transfer|nullable|image|max:2048',
        ]);

        $user = Auth::user();
        if (in_array($request->kategori, ['topup_pusat', 'info_saldo']) && !in_array($user->role, ['admin', 'hm'])) {
            return response()->json(['message' => 'Hanya Admin/HM yang bisa input kategori ini!'], 403);
        }

        DB::beginTransaction();
        try {
            $pathBukti = null;
            if ($request->hasFile('bukti_transaksi')) {
                $folderBase = "brankas/" . $request->kategori . "/" . date('Y/m');
                $pathBukti = $request->file('bukti_transaksi')->store($folderBase, 'minio');
            }

            $transaksiTerakhir = TransaksiBrankas::orderBy('id', 'desc')->lockForUpdate()->first();
            $saldoAwal = $transaksiTerakhir ? $transaksiTerakhir->saldo_akhir : 0;
            $saldoAwalRekening = $transaksiTerakhir ? $transaksiTerakhir->saldo_akhir_rekening : 0;

            $pemasukan = 0;
            $pengeluaran = 0;

            if ($request->kategori === 'topup_pusat') {
                $pemasukan = $request->nominal;
            } elseif ($request->kategori === 'setor_ke_admin') {
                $pengeluaran = $request->nominal;
            } else {
                if ($request->tipe_operasional === 'masuk') $pemasukan = $request->nominal; 
                else $pengeluaran = $request->nominal;
            }

            $saldoAkhir = $saldoAwal;
            $saldoAkhirRekening = $saldoAwalRekening;

            if ($request->metode === 'cash') {
                if ($pengeluaran > $saldoAwal) throw new Exception("Saldo tunai tidak mencukupi!");
                $saldoAkhir = $saldoAwal + $pemasukan - $pengeluaran;
            } else {
                if ($pengeluaran > $saldoAwalRekening) throw new Exception("Saldo rekening tidak mencukupi!");
                $saldoAkhirRekening = $saldoAwalRekening + $pemasukan - $pengeluaran;
            }

            $status = ($request->kategori === 'setor_ke_admin') ? 'pending' : 'tervalidasi';

            $transaksi = TransaksiBrankas::create([
                'user_id' => $user->id,
                'deskripsi' => $request->deskripsi,
                'kategori' => $request->kategori,
                'metode' => $request->metode,
                'saldo_awal' => $saldoAwal,
                'pemasukan' => $pemasukan,
                'pengeluaran' => $pengeluaran,
                'saldo_akhir' => $saldoAkhir,
                'saldo_awal_rekening' => $saldoAwalRekening, 
                'saldo_akhir_rekening' => $saldoAkhirRekening, 
                'bukti_transaksi' => $pathBukti, 
                'status_validasi' => $status,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'data' => $transaksi]);
        } catch (Exception $e) {
            DB::rollBack();
            if ($pathBukti) Storage::disk('minio')->delete($pathBukti);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Validasi oleh Admin (Proses dari Selesai -> Lunas)
     */
   public function validasiSetoran(Request $request, $id)
{
    $request->validate([
        'deskripsi_validasi' => 'required|string',
        'bukti_mutasi' => 'required|image|max:2048' 
    ]);

    $user = Auth::user();
    // Pastikan user adalah admin atau hm
    if (!in_array(strtolower($user->role), ['admin', 'hm'])) {
        return response()->json(['message' => 'Akses ditolak!'], 403);
    }

    // Gunakan lockForUpdate untuk mencegah race condition
    DB::beginTransaction();
    try {
        $transaksi = TransaksiBrankas::lockForUpdate()->findOrFail($id);
        
        if ($transaksi->status_validasi === 'tervalidasi') {
            return response()->json(['message' => 'Transaksi sudah berstatus LUNAS!'], 400);
        }

        // Simpan File ke Minio
        $pathMutasi = $request->file('bukti_mutasi')->store("brankas/validasi_admin/" . date('Y/m'), 'minio');

        // Update menggunakan assignment manual agar lebih presisi
        $transaksi->status_validasi = 'tervalidasi';
        $transaksi->validator_id = $user->id;
        $transaksi->bukti_validasi = $pathMutasi; 
        $transaksi->catatan_admin = $request->deskripsi_validasi;
        
        $transaksi->save(); // Simpan ke database
        
        DB::commit();

        // Refresh model untuk memastikan data terbaru yang ditarik
        $transaksi->refresh();

        return response()->json([
            'success' => true, 
            'message' => 'Verifikasi berhasil, status sekarang LUNAS!',
            'data' => [
                'id' => $transaksi->id,
                'status' => $transaksi->status_validasi,
                'bukti' => $transaksi->bukti_validasi,
                'catatan' => $transaksi->catatan_admin
            ]
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

    /**
     * Riwayat Transaksi (Menampilkan Bukti Toko & Bukti Admin)
     */
    public function riwayat(Request $request)
    {
        $query = TransaksiBrankas::with(['user', 'validator']);

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        // Hitung Grand Total khusus periode yang difilter
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
            'pagination' => ['current_page' => $data->currentPage(), 'last_page' => $data->lastPage()]
        ]);
    }
}