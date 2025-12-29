<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiBrankas; 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use Carbon\Carbon;

class BrankasController extends Controller
{
    /**
     * GET: Ambil Saldo Akhir & Ringkasan Berdasarkan Kategori
     */
  public function index()
    {
        $terakhir = TransaksiBrankas::orderBy('id', 'desc')->first();
        
        return response()->json([
            'success' => true,
            'summary' => [
                // Saldo ini adalah uang fisik yang seharusnya ada di tangan Checker/Toko
                'saldo_toko_saat_ini' => (float) ($terakhir->saldo_akhir ?? 0),
                'total_modal_dari_pusat' => (float) TransaksiBrankas::where('kategori', 'topup_pusat')->sum('pemasukan'),
                'total_setoran_ke_admin' => (float) TransaksiBrankas::where('kategori', 'setor_ke_admin')->where('status_validasi', 'tervalidasi')->sum('pengeluaran'),
                'total_setoran_pending' => (float) TransaksiBrankas::where('status_validasi', 'pending')->sum('pengeluaran'),
            ]
        ]);
    }

    /**
     * POST: Input Transaksi (Logika Checker & HM)
     */
public function store(Request $request)
    {

        $now = Carbon::now('Asia/Jakarta');
$folderBase = "brankas/" . $request->kategori . "/" . $now->format('Y/m');
        $request->validate([
            'kategori' => 'required|in:topup_pusat,operasional_toko,setor_ke_admin',
            'tipe_operasional' => 'required_if:kategori,operasional_toko|in:masuk,keluar',
            'metode' => 'required|in:cash,transfer',
            'nominal' => 'required|numeric|min:1',
            'deskripsi' => 'required|string|max:255',
            'bukti_transaksi' => 'required_if:metode,transfer|nullable|image|max:2048',
        ]);

        $user = Auth::user();

        if ($request->kategori === 'topup_pusat' && !in_array($user->role, ['admin', 'hm'])) {
            return response()->json(['message' => 'Hanya Admin/HM yang bisa input modal awal!'], 403);
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

            $pemasukan = 0;
            $pengeluaran = 0;

            // LOGIKA SIRKULASI
            if ($request->kategori === 'topup_pusat') {
                $pemasukan = $request->nominal;
            } 
            elseif ($request->kategori === 'setor_ke_admin') {
                $pengeluaran = $request->nominal;
            } 
            elseif ($request->kategori === 'operasional_toko') {
                if ($request->tipe_operasional === 'masuk') {
                    $pemasukan = $request->nominal; 
                } else {
                    $pengeluaran = $request->nominal; 
                }
            }

            if ($pengeluaran > $saldoAwal) {
                throw new Exception("Saldo di toko tidak mencukupi untuk transaksi ini!");
            }

            $saldoAkhir = $saldoAwal + $pemasukan - $pengeluaran;
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
     * POST: Validasi Setoran (Hanya Admin & HM)
     */
    public function validasiSetoran(Request $request, $id)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'hm'])) {
            return response()->json(['message' => 'Anda tidak memiliki akses validasi!'], 403);
        }

        $transaksi = TransaksiBrankas::findOrFail($id);
        
        if ($transaksi->kategori !== 'setor_ke_admin') {
            return response()->json(['message' => 'Transaksi ini bukan kategori setoran!'], 400);
        }

        $transaksi->update([
            'status_validasi' => 'tervalidasi',
            'validator_id' => $user->id
        ]);

        return response()->json(['success' => true, 'message' => 'Setoran berhasil divalidasi!']);
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
        $query = TransaksiBrankas::with('user')->orderBy('created_at', 'desc');

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        $data = $query->paginate(10);

        return response()->json([
            'success' => true,
            'riwayat' => collect($data->items())->map(function($item) {
                $urlBukti = null;
                if ($item->bukti_transaksi) {
                    $urlBukti = url('/api/files/' . $item->bukti_transaksi);
                }

                return [
                    'id' => $item->id,
                    'waktu' => $item->created_at->format('d-m-Y H:i'),
                    'petugas' => $item->user->name ?? 'System',
                    'kategori' => $item->kategori,
                    'deskripsi' => $item->deskripsi,
                    'pemasukan' => (float) $item->pemasukan,
                    'pengeluaran' => (float) $item->pengeluaran,
                    'saldo_akhir' => (float) $item->saldo_akhir,
                    'metode' => $item->metode,
                    'status_validasi' => $item->status_validasi,
                    'bukti_transaksi' => $urlBukti 
                ];
            }),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'total' => $data->total(),
            ]
        ]);
    } catch (Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
}