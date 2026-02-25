<?php

namespace App\Http\Controllers;

use App\Models\NotaKehilangan;
use App\Models\DetailGadai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class NotaKehilanganController extends Controller
{
    protected $fotoFields = ['foto_nasabah', 'foto_nota_ilang'];

    const MAP_JENIS_FOLDER = [
        1 => 'handphone',
        2 => 'logam_mulia',
        3 => 'retro',
        4 => 'perhiasan',
    ];

    /**
     * Index - List data dengan pagination
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $data = NotaKehilangan::with([
            'nasabah:id,nama_lengkap,nik,alamat,no_hp',
            'detailGadai:id,no_gadai,type_id,status',
            'detailGadai.type:id,nama_type',
        ])->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success'    => true,
            'data'       => $data->items(),
            'pagination' => [
                'total'        => $data->total(),
                'current_page' => $data->currentPage(),
            ],
        ]);
    }

    /**
     * Search Gadai - Cari data gadai untuk buat nota baru
     */
    public function searchGadai(Request $request)
    {
        $search = $request->get('q');
        $data = DetailGadai::with([
            'nasabah:id,nama_lengkap,nik,alamat,no_hp',
            'type:id,nama_type',
        ])
        ->where(function ($q) use ($search) {
            $q->where('no_gadai', 'LIKE', "%{$search}%")
              ->orWhereHas('nasabah', fn($q) => $q->where('nama_lengkap', 'LIKE', "%{$search}%"));
        })
        ->limit(10)
        ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Store - Simpan nota baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'detail_gadai_id'  => 'required|exists:detail_gadai,id',
            'foto_nasabah'     => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'foto_nota_ilang'  => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $gadai = DetailGadai::with('nasabah')->find($request->detail_gadai_id);
        
        // Cek duplikasi
        $existing = NotaKehilangan::where('detail_gadai_id', $gadai->id)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => "Nota kehilangan untuk {$gadai->no_gadai} sudah ada. No Nota: {$existing->no_nota}",
            ], 422);
        }

        try {
            DB::beginTransaction();

            $nota = NotaKehilangan::create([
                'no_nota'         => $this->generateNoNota(),
                'detail_gadai_id' => $gadai->id,
                'nasabah_id'      => $gadai->nasabah_id,
            ]);

            $this->handleFotoNota($request, $nota, $gadai);

            $nota->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Nota kehilangan berhasil disimpan.',
                'data'    => $nota->load(['nasabah', 'detailGadai.type']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Show - Detail nota berdasarkan ID atau ID Gadai
     */
    public function show($id)
    {
        $nota = NotaKehilangan::with(['nasabah', 'detailGadai.type'])
            ->where('id', $id)
            ->orWhere('detail_gadai_id', $id)
            ->first();

        if (!$nota) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $nota]);
    }

    /**
     * Update - Edit foto nota
     */
    public function update(Request $request, $id)
    {
        $nota = NotaKehilangan::with(['detailGadai.nasabah', 'detailGadai.type'])->find($id);
        if (!$nota) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        $request->validate([
            'foto_nasabah'    => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'foto_nota_ilang' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $gadai = $nota->detailGadai;
            $this->handleFotoNota($request, $nota, $gadai);

            $nota->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Nota kehilangan berhasil diperbarui.',
                'data'    => $nota->load(['nasabah', 'detailGadai.type']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Logic Generator Nomor Nota
     */
    private function generateNoNota(): string
    {
        $year = Carbon::now()->format('Y');
        $last = NotaKehilangan::whereYear('created_at', $year)
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        $next   = $last ? ((int) substr($last->no_nota, -4)) + 1 : 1;
        $suffix = str_pad($next, 4, '0', STR_PAD_LEFT);

        return "NOK-{$year}-{$suffix}";
    }

    /**
     * Handle Upload ke Minio
     */
    private function handleFotoNota($request, $nota, $gadai)
    {
        $nasabah = $gadai->nasabah;

        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap ?? 'nasabah');
        $jenisFolder   = self::MAP_JENIS_FOLDER[$gadai->type_id] ?? 'gadai';
        $noGadaiSlug   = preg_replace('/[^A-Za-z0-9\-]/', '_', $gadai->no_gadai ?? "gadai-{$gadai->id}");

        $folder = "{$folderNasabah}/{$jenisFolder}/{$noGadaiSlug}/nota-kehilangan";

        foreach ($this->fotoFields as $field) {
            if ($request->hasFile($field)) {
                // Delete lama jika ada
                if ($nota->getRawOriginal($field)) {
                    Storage::disk('minio')->delete($nota->getRawOriginal($field));
                }

                $file     = $request->file($field);
                $filename = "{$noGadaiSlug}-{$field}-" . time() . ".{$file->getClientOriginalExtension()}";

                $nota->$field = $file->storeAs($folder, $filename, 'minio');
            }
        }
    }
}