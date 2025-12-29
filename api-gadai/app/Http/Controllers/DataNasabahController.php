<?php

namespace App\Http\Controllers;

use App\Models\DataNasabah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class DataNasabahController extends Controller
{
    /**
     * Ambil semua data nasabah dengan pagination
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page    = $request->get('page', 1);
        $search  = $request->get('search');

        $query = DataNasabah::with('user:id,name,role')
            ->orderBy('created_at', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'LIKE', "%{$search}%")
                  ->orWhere('nik', 'LIKE', "%{$search}%")
                  ->orWhere('no_rek', 'LIKE', "%{$search}%");
            });
        }

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success'    => true,
            'message'    => 'Data nasabah berhasil diambil.',
            'data'       => $data->items(),
            'pagination' => [
                'total'        => $data->total(),
                'per_page'     => $data->perPage(),
                'current_page' => $data->currentPage(),
                'last_page'    => $data->lastPage(),
                'from'         => $data->firstItem(),
                'to'           => $data->lastItem(),
            ],
        ]);
    }

    /**
     * Simpan data nasabah baru
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_lengkap' => 'required|string|max:255',
            'nik'          => 'required|string|max:20|unique:data_nasabah,nik',
            'alamat'       => 'required|string',
            'no_hp'        => 'required|string|max:15',
            'no_rek'       => 'nullable|string|max:30', 
            'foto_ktp'     => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User belum login.'
            ], 401);
        }

        // Folder nasabah
        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $request->nama_lengkap);

        $path = null;
        if ($request->hasFile('foto_ktp')) {
            $namaFile = 'ktp_' . $request->nik . '.' . $request->file('foto_ktp')->getClientOriginalExtension();
            $path = $request->file('foto_ktp')
                ->storeAs("pawned-items/{$folderNasabah}/ktp", $namaFile, 'minio');
        }

        $nasabah = DataNasabah::create([
            'user_id'      => $user->id,
            'nama_lengkap' => $request->nama_lengkap,
            'nik'          => $request->nik,
            'alamat'       => $request->alamat,
            'no_hp'        => $request->no_hp,
            'no_rek'       => $request->no_rek,
            'foto_ktp'     => $path, // path relatif
        ]);

        $nasabah->load('user:id,name,role');

        // Convert foto_ktp ke URL streaming
        $nasabah->foto_ktp = $this->convertPathToUrl($nasabah->foto_ktp);

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah berhasil ditambahkan.',
            'data'    => $nasabah,
        ], 201);
    }

    /**
     * Tampilkan data nasabah
     */
    public function show($id)
    {
        $nasabah = DataNasabah::with('user:id,name,role')->find($id);
        if (!$nasabah) {
            return response()->json([
                'success' => false,
                'message' => 'Data nasabah tidak ditemukan.'
            ], 404);
        }

        $nasabah->foto_ktp = $this->convertPathToUrl($nasabah->foto_ktp);

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah ditemukan.',
            'data'    => $nasabah,
        ], 200);
    }

    /**
     * Update data nasabah
     */
public function update(Request $request, $id)
{
    $nasabah = DataNasabah::find($id);
    if (!$nasabah) {
        return response()->json([
            'success' => false,
            'message' => 'Data nasabah tidak ditemukan.'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'nama_lengkap' => 'sometimes|required|string|max:255',
        'nik'          => 'sometimes|required|string|max:20|unique:data_nasabah,nik,' . $nasabah->id,
        'alamat'       => 'sometimes|required|string',
        'no_hp'        => 'sometimes|required|string|max:15',
        'no_rek'       => 'nullable|string|max:30', 
        'foto_ktp'     => 'sometimes|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors'  => $validator->errors()
        ], 422);
    }

    foreach (['nama_lengkap', 'nik', 'alamat', 'no_hp', 'no_rek'] as $field) { 
        if ($request->has($field)) {
            $nasabah->$field = $request->$field;
        }
    }

    if ($request->hasFile('foto_ktp')) {

        if ($nasabah->foto_ktp && Storage::disk('minio')->exists($nasabah->foto_ktp)) {
            Storage::disk('minio')->delete($nasabah->foto_ktp);
        }

        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
        $namaFile = 'ktp_' . $nasabah->nik . '.' . $request->file('foto_ktp')->getClientOriginalExtension();

        $path = $request->file('foto_ktp')
            ->storeAs("{$folderNasabah}", $namaFile, 'minio');

        $nasabah->foto_ktp = $path;
    }

    $nasabah->save();
    $nasabah->load('user:id,name,role');

    return response()->json([
        'success' => true,
        'message' => 'Data nasabah berhasil diperbarui.',
        'data'    => $nasabah,
    ], 200);
}

    /**
     * Soft delete data nasabah
     */
    public function destroy($id)
    {
        $nasabah = DataNasabah::find($id);
        if (!$nasabah) {
            return response()->json([
                'success' => false,
                'message' => 'Data nasabah tidak ditemukan.'
            ], 404);
        }

        $nasabah->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah berhasil dihapus (soft delete).'
        ], 200);
    }

    /**
     * Helper convert path MinIO jadi URL streaming
     */
    private function convertPathToUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;

        // path lengkap termasuk bucket
        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);

        return url("files/{$path}");
    }
}
