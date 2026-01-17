<?php

namespace App\Http\Controllers;

use App\Models\DetailGadai;
use App\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; 
use Carbon\Carbon;

class DetailGadaiController extends Controller
{
    /**
     * 🔹 Ambil semua data detail gadai dengan pagination
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);
        $search = $request->get('search');

        $query = DetailGadai::with([
            'type:id,nama_type',
            'nasabah:id,nama_lengkap,nik,user_id',
            'nasabah.user:id,name,role,email',
            'perpanjanganTempos',
            'hp', 'hp.merk', 'hp.type_hp', 'hp.kerusakanList', 'hp.kelengkapanList',
            'perhiasan', 'perhiasan.kelengkapan',   
            'logamMulia', 'logamMulia.kelengkapanEmas', 
            'retro', 'retro.kelengkapan', 
            'approvals.user:id,name,role',
        ])->orderBy('created_at', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('no_gadai', 'LIKE', "%{$search}%")
                  ->orWhere('no_nasabah', 'LIKE', "%{$search}%")
                  ->orWhere('status', 'LIKE', "%{$search}%")
                  ->orWhereHas('type', fn($typeQuery) => $typeQuery->where('nama_type', 'LIKE', "%{$search}%"))
                  ->orWhereHas('nasabah', fn($nasabahQuery) => 
                      $nasabahQuery->where('nama_lengkap', 'LIKE', "%{$search}%")->orWhere('nik', 'LIKE', "%{$search}%")
                  );
            });
        }

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'message' => 'Data detail gadai berhasil diambil.',
            'data' => $data->items(),
            'pagination' => [
                'total' => $data->total(),
                'per_page' => $data->perPage(),
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
            ]
        ]);
    }

    /**
     * 🔹 Simpan data detail gadai baru
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tanggal_gadai' => 'required|date',
            'jatuh_tempo'   => 'required|date|after_or_equal:tanggal_gadai',
            'type_id'       => 'required|exists:types,id',
            'nasabah_id'    => 'required|exists:data_nasabah,id',
            'taksiran'      => 'required|numeric|min:0',
            'uang_pinjaman' => 'required|numeric|min:0',
            'status'        => 'nullable|in:proses,selesai,lunas',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tanggal = Carbon::parse($request->tanggal_gadai);
        $type = Type::find($request->type_id);

        $lastGadai = DetailGadai::orderBy('id', 'desc')->first();
        $noNasabahNumber = $lastGadai ? (int) substr($lastGadai->no_nasabah, -4) + 1 : 1;
        
        $noNasabah = $tanggal->format('m') . $tanggal->format('y') . str_pad($noNasabahNumber, 4, '0', STR_PAD_LEFT);
        $noGadai = "SGI-{$tanggal->format('d')}-{$tanggal->format('m')}-{$tanggal->format('Y')}-{$type->nomor_type}-" . str_pad($noNasabahNumber, 4, '0', STR_PAD_LEFT);

        $gadai = DetailGadai::create([
            'no_gadai'      => $noGadai,
            'no_nasabah'    => $noNasabah,
            'tanggal_gadai' => $request->tanggal_gadai,
            'jatuh_tempo'   => $request->jatuh_tempo,
            'taksiran'      => $request->taksiran,
            'uang_pinjaman' => $request->uang_pinjaman,
            'type_id'       => $request->type_id,
            'nasabah_id'    => $request->nasabah_id,
            'status'        => $request->status ?? 'proses',
        ]);

        return response()->json(['success' => true, 'data' => $gadai], 201);
    }

    /**
     * 🔹 Validasi Selesai Cek (Proses -> Selesai)
     */
    public function validasiSelesai(Request $request, $id)
    {
        $gadai = DetailGadai::find($id);
        if (!$gadai || $gadai->status !== 'proses') {
            return response()->json(['success' => false, 'message' => 'Status tidak valid.'], 400);
        }

        $gadai->update(['status' => 'selesai']);
        return response()->json(['success' => true, 'message' => 'Unit siap dilunasi.']);
    }

    /**
     * 🔹 Proses Pelunasan Unit (Selesai -> Lunas)
     */
    public function pelunasan(Request $request, $id)
    {
        $gadai = DetailGadai::with(['nasabah', 'type'])->find($id);

        if (!$gadai || $gadai->status !== 'selesai') {
            return response()->json(['success' => false, 'message' => 'Unit tidak ditemukan atau status bukan Selesai.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'nominal_bayar'     => 'required|numeric|min:' . $gadai->uang_pinjaman,
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer'    => 'required_if:metode_pembayaran,transfer|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'status'            => 'lunas',
                'nominal_bayar'     => $request->nominal_bayar,
                'metode_pembayaran' => $request->metode_pembayaran,
                'tanggal_bayar'     => now(),
            ];

            // Logic Simpan Gambar ke Minio jika Transfer
            if ($request->metode_pembayaran === 'transfer' && $request->hasFile('bukti_transfer')) {
                $nasabah = $gadai->nasabah;
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
                $tipeBarang = strtolower($gadai->type->nama_type ?? 'umum');
                
                // Path: Nama_Nasabah/tipe/no_gadai/pelunasan/
                $folderBase = "{$folderNasabah}/{$tipeBarang}/{$gadai->no_gadai}/pelunasan";
                
                $file = $request->file('bukti_transfer');
                $filename = "bukti-lunas-" . ($nasabah->nik ?? 'no-nik') . "-" . time() . "." . $file->getClientOriginalExtension();

                // Store ke disk minio
                $path = $file->storeAs($folderBase, $filename, 'minio');
                $updateData['bukti_transfer'] = $path;
            }

            $gadai->update($updateData);

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Unit berhasil dilunasi.',
                'kembalian' => $request->nominal_bayar - $gadai->uang_pinjaman
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 🔹 Tampilkan data detail gadai berdasarkan ID
     */
    public function show($id)
    {
        $gadai = DetailGadai::with([
            'type', 'nasabah.user', 'perpanjanganTempos', 'hp', 'hp.merk', 'hp.type_hp',
            'perhiasan', 'logamMulia', 'retro'
        ])->find($id);

        if (!$gadai) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);

        return response()->json(['success' => true, 'data' => $gadai]);
    }

    /**
     * 🔹 Hapus data
     */
    public function destroy($id)
    {
        $gadai = DetailGadai::find($id);
        if (!$gadai) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        $gadai->delete();
        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus.']);
    }


    /**
     * 🔹 Update data detail gadai
     */
    public function update(Request $request, $id)
    {
        $gadai = DetailGadai::find($id);

        if (!$gadai) {
            return response()->json([
                'success' => false, 
                'message' => 'Data tidak ditemukan.'
            ], 404);
        }

        // 1. Validasi Input
        $validator = Validator::make($request->all(), [
            'tanggal_gadai' => 'sometimes|required|date',
            'jatuh_tempo'   => 'sometimes|required|date|after_or_equal:tanggal_gadai',
            'type_id'       => 'sometimes|required|exists:types,id',
            'nasabah_id'    => 'sometimes|required|exists:data_nasabah,id',
            'taksiran'      => 'sometimes|required|numeric|min:0',
            'uang_pinjaman' => 'sometimes|required|numeric|min:0',
            'status'        => 'sometimes|nullable|in:proses,selesai,lunas',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $dataUpdate = $request->only([
                'tanggal_gadai', 'jatuh_tempo', 'type_id', 
                'nasabah_id', 'taksiran', 'uang_pinjaman', 'status'
            ]);

            // 2. Logika jika No Gadai harus berubah (Opsional)
            // Jika tanggal_gadai atau type_id berubah, nomor gadai biasanya harus regenerasi
            if ($request->has('tanggal_gadai') || $request->has('type_id')) {
                $tanggal = Carbon::parse($request->get('tanggal_gadai', $gadai->tanggal_gadai));
                $type = Type::find($request->get('type_id', $gadai->type_id));
                
                // Mengambil 4 digit terakhir dari no_nasabah yang sudah ada
                $suffix = substr($gadai->no_nasabah, -4); 
                
                // Update No Gadai & No Nasabah sesuai format store
                $dataUpdate['no_nasabah'] = $tanggal->format('m') . $tanggal->format('y') . $suffix;
                $dataUpdate['no_gadai'] = "SGI-{$tanggal->format('d')}-{$tanggal->format('m')}-{$tanggal->format('Y')}-{$type->nomor_type}-{$suffix}";
            }

            $gadai->update($dataUpdate);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data gadai berhasil diperbarui.',
                'data' => $gadai->load('type', 'nasabah')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Gagal memperbarui data: ' . $e->getMessage()
            ], 500);
        }
    }
}