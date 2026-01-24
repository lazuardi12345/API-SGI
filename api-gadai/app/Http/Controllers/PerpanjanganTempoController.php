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

    $gadai = DetailGadai::with('type')->findOrFail($request->detail_gadai_id);

    $pokok = (float) $gadai->uang_pinjaman;
    $typeNama = strtolower($gadai->type->nama_type ?? '');
    
    $tglExtend = Carbon::parse($request->tanggal_perpanjangan);
    $jtLama = Carbon::parse($gadai->jatuh_tempo); 
    $jtBaru = Carbon::parse($request->jatuh_tempo_baru);
    $totalTelat = max(0, $jtLama->diffInDays($tglExtend, false));
    $periodeBaruHari = max(0, $tglExtend->diffInDays($jtBaru, false));

    $isHandphoneElektronik = in_array($typeNama, ['handphone', 'hp', 'elektronik']);
    $jasa = 0;
    if ($isHandphoneElektronik) {
        $rateJasa = ($periodeBaruHari <= 15) ? 0.045 : 0.095;
        $jasa = $pokok * $rateJasa;
    } else {
        $rateJasa = ($periodeBaruHari <= 15) ? 0.015 : 0.025;
        $jasa = $pokok * $rateJasa;
    }

    $rateDenda = 0.001; 
    $denda = $pokok * $rateDenda * $totalTelat;

    $penalty = ($totalTelat > 15) ? 180000 : 0;

    $adminFinal = 0;
    if (!$isHandphoneElektronik) {
        $adminBase = $pokok * 0.01; 
        $adminMin = 10000;
        $adminFinal = max($adminBase, $adminMin);
    }
    $totalSemua = ceil(($jasa + $denda + $penalty + $adminFinal) / 1000) * 1000;

    $perpanjangan = PerpanjanganTempo::create([
        'detail_gadai_id'      => $request->detail_gadai_id,
        'tanggal_perpanjangan' => $request->tanggal_perpanjangan,
        'jatuh_tempo_baru'     => $request->jatuh_tempo_baru,
        'nominal_admin'        => $totalSemua, 
        'status_bayar'         => 'pending',
    ]);

    return response()->json(['success' => true, 'data' => $perpanjangan], 201);
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