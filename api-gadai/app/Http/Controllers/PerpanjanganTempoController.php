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

        if ($mode === 'search' && $search) {
            $nasabahData = $this->searchSemuaNasabah($search); 
            
            return response()->json([
                'success' => true,
                'data'    => $nasabahData,
                'page'    => 1,
                'pageSize'=> count($nasabahData),
                'total'   => count($nasabahData),
            ]);
        }

        $status = $request->get('status'); 
        $query = PerpanjanganTempo::with([
            'detailGadai:id,no_gadai,nasabah_id,type_id,uang_pinjaman,status,jatuh_tempo',
            'detailGadai.nasabah:id,nama_lengkap,nik',
            'detailGadai.type:id,nama_type',
            'detailGadai.hp:id,detail_gadai_id,nama_barang'
        ])->orderBy('created_at', 'desc');

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

        $gadai = DetailGadai::with([
            'type:id,nama_type',
            'perpanjanganTempos' => function($q) {
                $q->where('status_bayar', 'lunas')
                  ->select('id', 'detail_gadai_id', 'tanggal_perpanjangan', 'jatuh_tempo_baru');
            }
        ])->findOrFail($request->detail_gadai_id);
        $perpanjanganService = new PerpanjanganService();
        $hasilHitung = $perpanjanganService->hitungPerpanjangan(
            $gadai, 
            $request->tanggal_perpanjangan, 
            $request->jatuh_tempo_baru
        );

        $typeNama = strtolower($gadai->type->nama_type ?? '');
        if (str_contains($typeNama, 'hp') || str_contains($typeNama, 'elektronik')) {
            $totalDurasiLama = $gadai->perpanjanganTempos->sum(function($item) {
                return Carbon::parse($item->tanggal_perpanjangan)
                    ->diffInDays(Carbon::parse($item->jatuh_tempo_baru));
            });

            $totalAkumulasi = $totalDurasiLama + $hasilHitung['durasi_baru'];
            
            if ($totalAkumulasi > 90) {
                return response()->json([
                    'success' => false, 
                    'message' => "Maksimal simpan unit 90 hari! Akumulasi: {$totalAkumulasi} hari."
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
                'data' => $perpanjangan->load('detailGadai:id,no_gadai,nasabah_id', 'detailGadai.nasabah:id,nama_lengkap'),
                'rincian_tampilan' => $hasilHitung 
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error store perpanjangan: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Gagal menyimpan perpanjangan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bayarPerpanjangan(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer'    => 'required_if:metode_pembayaran,transfer|nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $perpanjangan = PerpanjanganTempo::with('detailGadai:id,no_gadai,jatuh_tempo')->findOrFail($id);
        
        if ($perpanjangan->isLunas()) {
            return response()->json([
                'success' => false, 
                'message' => 'Perpanjangan sudah lunas sebelumnya.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $pathBukti = null;
            if ($request->metode_pembayaran === 'transfer' && $request->hasFile('bukti_transfer')) {
                $file = $request->file('bukti_transfer');
                $filename = "perpanjangan-{$perpanjangan->id}-" . time() . "." . $file->getClientOriginalExtension();

                $pathBukti = $file->storeAs(
                    "perpanjangan/{$perpanjangan->detail_gadai_id}", 
                    $filename, 
                    'minio' 
                );
            }

            $perpanjangan->update([
                'status_bayar'      => 'lunas',
                'metode_pembayaran' => $request->metode_pembayaran,
                'bukti_transfer'    => $pathBukti,
                'tanggal_bayar'     => now(),
            ]);

            $perpanjangan->detailGadai->update([
                'jatuh_tempo' => $perpanjangan->jatuh_tempo_baru,
            ]);

            DB::commit();
            
            return response()->json([
                'success' => true, 
                'message' => 'Pembayaran perpanjangan berhasil!',
                'data' => [
                    'no_gadai' => $perpanjangan->detailGadai->no_gadai,
                    'jatuh_tempo_baru' => $perpanjangan->jatuh_tempo_baru->format('d/m/Y'),
                    'total_bayar' => $perpanjangan->total_bayar_formatted,
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error bayar perpanjangan: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Gagal memproses pembayaran: ' . $e->getMessage()
            ], 500);
        }
    }


    private function searchSemuaNasabah($search)
    {
        return DetailGadai::with([
            'nasabah:id,nama_lengkap,nik',
            'type:id,nama_type',
            'hp:id,detail_gadai_id,nama_barang'
        ])
        ->select('id', 'no_gadai', 'nasabah_id', 'type_id', 'status', 'uang_pinjaman', 'jatuh_tempo')
        ->where('status', '!=', 'lunas') 
        ->where(function ($q) use ($search) {
            $q->where('no_gadai', 'like', "%{$search}%")
              ->orWhereHas('nasabah', function ($n) use ($search) {
                  $n->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%");
              });
        })
        ->limit(20)
        ->get();
    }


    public function show($id)
    {
        $perpanjangan = PerpanjanganTempo::with([
            'detailGadai:id,no_gadai,nasabah_id,type_id,uang_pinjaman,jatuh_tempo',
            'detailGadai.nasabah:id,nama_lengkap,nik',
            'detailGadai.type:id,nama_type',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $perpanjangan
        ]);
    }

    public function update(Request $request, $id)
    {
        $perpanjangan = PerpanjanganTempo::findOrFail($id);

        if ($perpanjangan->isLunas()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa update perpanjangan yang sudah lunas.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'tanggal_perpanjangan' => 'sometimes|date',
            'jatuh_tempo_baru'     => 'sometimes|date|after:tanggal_perpanjangan',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {

            if ($request->has('tanggal_perpanjangan') || $request->has('jatuh_tempo_baru')) {
                $gadai = $perpanjangan->detailGadai()->with('type')->first();
                
                $perpanjanganService = new PerpanjanganService();
                $hasilHitung = $perpanjanganService->hitungPerpanjangan(
                    $gadai,
                    $request->get('tanggal_perpanjangan', $perpanjangan->tanggal_perpanjangan),
                    $request->get('jatuh_tempo_baru', $perpanjangan->jatuh_tempo_baru)
                );

                $perpanjangan->update([
                    'tanggal_perpanjangan' => $request->get('tanggal_perpanjangan', $perpanjangan->tanggal_perpanjangan),
                    'jatuh_tempo_baru'     => $request->get('jatuh_tempo_baru', $perpanjangan->jatuh_tempo_baru),
                    'nominal_jasa'         => $hasilHitung['jasa_perpanjangan'],
                    'nominal_denda'        => $hasilHitung['denda_telat'],
                    'nominal_penalty'      => $hasilHitung['penalty'],
                    'nominal_admin'        => $hasilHitung['nominal_admin'],
                    'total_bayar'          => $hasilHitung['total_bayar'],
                ]);
            }

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Perpanjangan berhasil diupdate.',
                'data' => $perpanjangan->fresh()
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal update: ' . $e->getMessage()
            ], 500);
        }
    }


    public function destroy($id)
    {
        $perpanjangan = PerpanjanganTempo::findOrFail($id);

        if ($perpanjangan->isLunas()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus perpanjangan yang sudah lunas.'
            ], 400);
        }

        $perpanjangan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Perpanjangan berhasil dihapus.'
        ]);
    }
}