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

    public function index(Request $request)
{
    $perPage = $request->get('per_page', 10);
    $search = $request->get('search');
    $mode = $request->get('mode');

    // 1. Logic untuk mode search nasabah
    if ($mode === 'search' && $search) {
        // Karena searchSemuaNasabah biasanya custom collection, 
        // pastikan di dalamnya juga mendukung pagination atau kita bungkus di sini.
        $nasabahData = $this->searchSemuaNasabah($search); 
        
        return response()->json([
            'success' => true,
            'data'    => $nasabahData,
            'page'    => 1, // Untuk search biasanya single result atau custom handling
            'pageSize'=> count($nasabahData),
            'total'   => count($nasabahData),
        ]);
    }

    // 2. Logic Utama dengan Pagination
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

    $paginatedData = $query->paginate($perPage);

    return response()->json([
        'success'  => true,
        'data'     => $paginatedData->items(),
        'page'     => $paginatedData->currentPage(),
        'pageSize' => $paginatedData->perPage(),
        'total'    => $paginatedData->total(),
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

    $gadai = DetailGadai::with(['type', 'perpanjanganTempos'])->findOrFail($request->detail_gadai_id);

    $perpanjanganService = new PerpanjanganService();
    $hasilHitung = $perpanjanganService->hitungPerpanjangan(
        $gadai, 
        $request->tanggal_perpanjangan, 
        $request->jatuh_tempo_baru
    );

    $typeNama = strtolower($gadai->type->nama_type ?? '');
    if (str_contains($typeNama, 'hp') || str_contains($typeNama, 'elektronik')) {
        $totalDurasiLama = $gadai->perpanjanganTempos->where('status_bayar', 'lunas')->sum(function($item) {
            return Carbon::parse($item->tanggal_perpanjangan)->diffInDays(Carbon::parse($item->jatuh_tempo_baru));
        });

        if (($totalDurasiLama + $hasilHitung['durasi_baru']) > 90) {
            return response()->json([
                'success' => false, 
                'message' => "Maksimal simpan unit 90 hari! Akumulasi: " . ($totalDurasiLama + $hasilHitung['durasi_baru']) . " hari."
            ], 422);
        }
    }

    DB::beginTransaction();
    try {
        $perpanjangan = PerpanjanganTempo::create([
            'detail_gadai_id'      => $request->detail_gadai_id,
            'tanggal_perpanjangan' => $request->tanggal_perpanjangan,
            'jatuh_tempo_baru'     => $request->jatuh_tempo_baru,
            'nominal_jasa'         => $hasilHitung['jasa_perpanjangan'],
            'nominal_denda'        => $hasilHitung['denda_telat'],
            'nominal_penalty'      => $hasilHitung['penalty'],
            'nominal_admin'        => $hasilHitung['nominal_admin'],
            'total_bayar'          => $hasilHitung['total_bayar'],
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

public function bayarPerpanjangan(Request $request, $id)
{
    $perpanjangan = PerpanjanganTempo::with('detailGadai')->findOrFail($id);
    
    if ($perpanjangan->status_bayar === 'lunas') {
        return response()->json(['success' => false, 'message' => 'Sudah lunas.'], 400);
    }

    DB::beginTransaction();
    try {
        $perpanjangan->update([
            'status_bayar'      => 'lunas',
            'metode_pembayaran' => $request->metode_pembayaran ?? 'cash',
            'tanggal_bayar'     => now(),
        ]);

        $perpanjangan->detailGadai->update([
            'jatuh_tempo' => $perpanjangan->jatuh_tempo_baru,
        ]);

        DB::commit();
        return response()->json(['success' => true, 'message' => 'Pembayaran Berhasil!']);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

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