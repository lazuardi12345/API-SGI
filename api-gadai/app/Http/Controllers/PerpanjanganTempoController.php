<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerpanjanganTempo;
use App\Models\DetailGadai;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PerpanjanganTempoController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status'); 
        $search = $request->get('search');

        $query = PerpanjanganTempo::with(['detailGadai.nasabah', 'detailGadai.type'])
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

    // 1. PERBAIKAN: Gunakan 'perpanjanganTempo' (sesuai Model terbaru lo)
    // Atau pakai 'perpanjangan_tempo' jika lo sudah tambah alias di Model.
    $gadai = DetailGadai::with(['type', 'perpanjanganTempo'])->findOrFail($request->detail_gadai_id);

    $typeNama = strtolower($gadai->type->nama_type ?? '');
    $isHandphoneElektronik = in_array($typeNama, ['handphone', 'hp', 'elektronik']);

    $tglExtend = Carbon::parse($request->tanggal_perpanjangan);
    $jtLama = Carbon::parse($gadai->jatuh_tempo); 
    $jtBaru = Carbon::parse($request->jatuh_tempo_baru);

    // --- VALIDASI AKUMULASI MAX 90 HARI (HANYA UNTUK HP/ELEKTRONIK) ---
    if ($isHandphoneElektronik) {
        $durasiBaru = $tglExtend->diffInDays($jtBaru);

        // 2. PERBAIKAN: Sesuaikan nama relasi di sini juga
        $totalDurasiLama = $gadai->perpanjanganTempo->sum(function($item) {
            return Carbon::parse($item->tanggal_perpanjangan)->diffInDays(Carbon::parse($item->jatuh_tempo_baru));
        });

        if (($totalDurasiLama + $durasiBaru) > 90) {
            return response()->json([
                'success' => false, 
                'message' => 'Perpanjangan sudah melebihi kapasitas maksimal 90 hari untuk unit Handphone/Elektronik.'
            ], 422);
        }
    }

    $pokok = (float) $gadai->uang_pinjaman;
    
    $totalTelat = max(0, $jtLama->diffInDays($tglExtend, false));
    $periodeBaruHari = max(0, $tglExtend->diffInDays($jtBaru, false));

    // Hitung Jasa
    $jasa = 0;
    if ($isHandphoneElektronik) {
        $rateJasa = ($periodeBaruHari <= 15) ? 0.045 : 0.095;
        $jasa = $pokok * $rateJasa;
    } else {
        $rateJasa = ($periodeBaruHari <= 15) ? 0.015 : 0.025;
        $jasa = $pokok * $rateJasa;
    }

    // Hitung Denda & Penalty
    $rateDenda = 0.001; 
    $denda = $pokok * $rateDenda * $totalTelat;
    $penalty = ($totalTelat > 15) ? 180000 : 0;

    // Biaya Admin (Non-HP)
    $adminFinal = 0;
    if (!$isHandphoneElektronik) {
        $adminBase = $pokok * 0.01; 
        $adminMin = 10000;
        $adminFinal = max($adminBase, $adminMin);
    }
    
    $totalSemua = ceil(($jasa + $denda + $penalty + $adminFinal) / 1000) * 1000;

    // 3. GUNAKAN TRANSACTION: Biar kalau salah satu gagal, semua batal.
    DB::beginTransaction();
    try {
        $perpanjangan = PerpanjanganTempo::create([
            'detail_gadai_id'      => $request->detail_gadai_id,
            'tanggal_perpanjangan' => $request->tanggal_perpanjangan,
            'jatuh_tempo_baru'     => $request->jatuh_tempo_baru,
            'nominal_admin'        => $totalSemua, 
            'status_bayar'         => 'pending',
        ]);

        // JANGAN UPDATE jatuh_tempo DI SINI! 
        // Logikanya: Jatuh tempo di master gadai hanya update kalau sudah LUNAS pembayarannya.
        // Tapi kalau lo mau update pas ajuin (pending) ya silakan, cuma berisiko kalau gak jadi bayar.
        
        DB::commit();

        return response()->json([
            'success' => true, 
            'message' => 'Perpanjangan berhasil diajukan. Silakan lakukan pembayaran.',
            'data' => $perpanjangan
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}


public function bayarPerpanjangan(Request $request, $id)
    {
        $perpanjangan = PerpanjanganTempo::with(['detailGadai.nasabah', 'detailGadai.type'])->findOrFail($id);
        
        if ($perpanjangan->status_bayar === 'lunas') {
            return response()->json(['success' => false, 'message' => 'Sudah lunas.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer'    => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        DB::beginTransaction();
        try {
            $gadai = $perpanjangan->detailGadai;
            $nasabah = $gadai->nasabah;
            $typeNama = strtolower($gadai->type->nama_type ?? 'umum');
            $path = $perpanjangan->bukti_transfer;
            if ($request->hasFile('bukti_transfer')) {
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
                $folderBase = "{$folderNasabah}/{$typeNama}/{$gadai->no_gadai}/bayar_perpanjangan";
                $file = $request->file('bukti_transfer');
                $filename = "bukti-transfer-" . ($nasabah->nik ?? '000000') . "-" . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs($folderBase, $filename, 'minio');
            }
            $perpanjangan->update([
                'status_bayar'      => 'lunas',
                'metode_pembayaran' => $request->metode_pembayaran,
                'bukti_transfer'    => $path,
            ]);
            $gadai->update(['jatuh_tempo' => $perpanjangan->jatuh_tempo_baru]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pembayaran Berhasil']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}