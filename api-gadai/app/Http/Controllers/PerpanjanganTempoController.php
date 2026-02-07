<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerpanjanganTempo;
use App\Services\PerpanjanganService;
use App\Models\DetailGadai;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PerpanjanganTempoController extends Controller
{
    /**
     * Menampilkan list riwayat atau mencari nasabah aktif untuk input baru
     */
    public function index(Request $request)
    {
        $search = $request->get('search');
        $mode = $request->get('mode'); 

        // LOGIKA 1: Untuk Autocomplete di Frontend (Cari Master Data Gadai)
        if ($mode === 'search' && $search) {
            return response()->json([
                'success' => true,
                'data' => $this->searchSemuaNasabah($search)
            ]);
        }

        // LOGIKA 2: Untuk Tabel List Riwayat Perpanjangan
        $status = $request->get('status'); 
        $query = PerpanjanganTempo::with(['detailGadai.nasabah', 'detailGadai.type', 'detailGadai.hp'])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status_bayar', $status);
        }

        if ($search) {
            $query->whereHas('detailGadai', function ($q) use ($search) {
                $q->where('no_gadai', 'like', "%{$search}%")
                  ->orWhereHas('nasabah', function ($n) use ($search) {
                      $n->where('nama_lengkap', 'like', "%{$search}%");
                  });
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    /**
     * PROSES INPUT: Status awal adalah 'pending' (Selesai diinput)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'detail_gadai_id'      => 'required|exists:detail_gadai,id',
            'tanggal_perpanjangan' => 'required|date',
            'jatuh_tempo_baru'     => 'required|date|after:tanggal_perpanjangan',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $gadai = DetailGadai::with(['type', 'perpanjanganTempos'])->findOrFail($request->detail_gadai_id);

        // Pakai Service untuk hitung admin/bunga
        $perpanjanganService = new PerpanjanganService();
        $hasilHitung = $perpanjanganService->hitungPerpanjangan(
            $gadai, 
            $request->tanggal_perpanjangan, 
            $request->jatuh_tempo_baru
        );

        // Validasi Maksimal 90 Hari (Khusus HP/Elektronik)
        $typeNama = strtolower($gadai->type->nama_type ?? '');
        if (str_contains($typeNama, 'hp') || str_contains($typeNama, 'elektronik')) {
            // Hitung akumulasi hari dari perpanjangan yang sudah LUNAS sebelumnya
            $totalDurasiLama = $gadai->perpanjanganTempos->where('status_bayar', 'lunas')->sum(function($item) {
                return Carbon::parse($item->tanggal_perpanjangan)->diffInDays(Carbon::parse($item->jatuh_tempo_baru));
            });

            if (($totalDurasiLama + $hasilHitung['durasi_baru']) > 90) {
                return response()->json([
                    'success' => false, 
                    'message' => "Maksimal simpan unit 90 hari! Akumulasi saat ini: " . ($totalDurasiLama + $hasilHitung['durasi_baru']) . " hari."
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Flow: SELESAI diinput (Status Pending)
            $perpanjangan = PerpanjanganTempo::create([
                'detail_gadai_id'      => $request->detail_gadai_id,
                'tanggal_perpanjangan' => $request->tanggal_perpanjangan,
                'jatuh_tempo_baru'     => $request->jatuh_tempo_baru,
                'nominal_admin'        => $hasilHitung['total_bayar'],
                'status_bayar'         => 'pending', 
            ]);
            
            DB::commit();
            return response()->json([
                'success' => true, 
                'message' => 'Proses Selesai. Menunggu Pembayaran.', 
                'data' => $perpanjangan,
                'rincian_tampilan' => $hasilHitung 
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PROSES LUNAS: Update status perpanjangan dan update Jatuh Tempo di tabel induk
     */
    public function bayarPerpanjangan(Request $request, $id)
    {
        $perpanjangan = PerpanjanganTempo::findOrFail($id);
        
        if ($perpanjangan->status_bayar === 'lunas') {
            return response()->json(['success' => false, 'message' => 'Transaksi ini sudah lunas sebelumnya.'], 400);
        }

        DB::beginTransaction();
        try {
            // 1. Update status di tabel perpanjangan jadi LUNAS
            $perpanjangan->update([
                'status_bayar'      => 'lunas',
                'metode_pembayaran' => $request->metode_pembayaran ?? 'cash',
                'tanggal_bayar'     => now(),
            ]);

            // 2. Update tanggal jatuh tempo di tabel master (detail_gadai)
            $gadai = DetailGadai::find($perpanjangan->detail_gadai_id);
            $gadai->update([
                'jatuh_tempo'   => $perpanjangan->jatuh_tempo_baru,
                // nominal_admin di detail_gadai biasanya diisi biaya admin awal, 
                // tapi kalau brader mau update dengan biaya perpanjangan terbaru, ini sudah benar.
                'nominal_admin' => $perpanjangan->nominal_admin, 
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pembayaran Berhasil. Status: LUNAS. Data induk diperbarui.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Pencarian Master Data (Private)
     */
    private function searchSemuaNasabah($search)
{
    return DetailGadai::with(['nasabah', 'type', 'hp'])
        // GANTI INI: Jangan cari yang 'aktif', tapi cari yang BELUM 'lunas'
        ->where('status', '!=', 'lunas') 
        ->where(function ($q) use ($search) {
            $q->where('no_gadai', 'like', "%{$search}%")
              ->orWhereHas('nasabah', function ($n) use ($search) {
                  $n->where('nama_lengkap', 'like', "%{$search}%");
              });
        })
        ->limit(20)
        ->get();
}
}