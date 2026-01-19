<?php

namespace App\Http\Controllers;

use App\Models\DataNasabah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class DataNasabahController extends Controller
{
    // List bank untuk validasi (sama dengan Migration)
    protected $bankList = [
        'BCA', 'BRI', 'BNI', 'MANDIRI', 'BTN', 'SEABANK', 'BANK_JAGO', 'NEO_COMMERCE', 
        'ALOO_BANK', 'BLU', 'LINE_BANK', 'DIGIBANK', 'TMRW', 'BANK_RAYA', 'HIBANK',
        'CIMB_NIAGA', 'PERMATA', 'DANAMON', 'PANIN', 'OCBC_NISP', 'MAYBANK', 
        'COMMONWEALTH', 'DBS', 'UOB', 'HSBC', 'STANDARD_CHARTERED', 'ARTHA_GRAHA', 
        'MEGA', 'BUKOPIN', 'BTPN', 'SINARMAS', 'MESTIKA', 'BSI', 'MUAMALAT', 
        'BCA_SYARIAH', 'MEGA_SYARIAH', 'PANIN_SYARIAH', 'BUKOPIN_SYARIAH', 
        'BTPN_SYARIAH', 'VICTORIA_SYARIAH', 'BANK_DKI', 'BANK_JABAR', 'BANK_JATENG', 
        'BANK_JATIM', 'BANK_DIY', 'BANK_JAMBI', 'BANK_SUMUT', 'BANK_RIAU_KEPRI', 
        'BANK_SUMSEL_BABEL', 'BANK_LAMPUNG', 'BANK_KALBAR', 'BANK_KALSEL', 
        'BANK_KALTIMTARA', 'BANK_KALTENG', 'BANK_SULSELBAR', 'BANK_SULUTGO', 
        'BANK_NTB', 'BANK_NTT', 'BANK_BALI', 'BANK_PAPUA', 'BANK_BENGKULU', 'BANK_SULTRA'
    ];

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
                  ->orWhere('no_rek', 'LIKE', "%{$search}%") // Sesuaikan field
                  ->orWhere('bank', 'LIKE', "%{$search}%");     // Tambah search bank
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
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_lengkap' => 'required|string|max:255',
            'nik'          => 'required|string|max:20|unique:data_nasabah,nik',
            'alamat'       => 'required|string',
            'no_hp'        => 'required|string|max:15',
            'bank'         => 'required|string|in:' . implode(',', $this->bankList), // Validasi Enum
            'no_rek'  => 'required|string|max:30', 
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
            'bank'         => $request->bank,
            'no_rek'  => $request->no_rek,
            'foto_ktp'     => $path,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah berhasil ditambahkan.',
            'data'    => $nasabah->load('user:id,name,role'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $nasabah = DataNasabah::find($id);
        if (!$nasabah) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);

        $validator = Validator::make($request->all(), [
            'nama_lengkap' => 'sometimes|required|string|max:255',
            'nik'          => 'sometimes|required|string|max:20|unique:data_nasabah,nik,' . $id,
            'bank'         => 'sometimes|required|string|in:' . implode(',', $this->bankList),
            'no_rek'  => 'sometimes|required|string|max:30',
            'foto_ktp'     => 'sometimes|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Mass Update untuk field teks
        $nasabah->update($request->only(['nama_lengkap', 'nik', 'alamat', 'no_hp', 'bank', 'no_rek']));

        if ($request->hasFile('foto_ktp')) {
            if ($nasabah->foto_ktp) {
                // Hapus file lama di minio berdasarkan path asli (bukan URL)
                $oldPath = str_replace(url("api/files/"), "", $nasabah->getRawOriginal('foto_ktp'));
                Storage::disk('minio')->delete($oldPath);
            }

            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
            $namaFile = 'ktp_' . $nasabah->nik . '_' . time() . '.' . $request->file('foto_ktp')->getClientOriginalExtension();
            $path = $request->file('foto_ktp')->storeAs("pawned-items/{$folderNasabah}/ktp", $namaFile, 'minio');
            
            $nasabah->foto_ktp = $path;
            $nasabah->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah berhasil diperbarui.',
            'data'    => $nasabah->load('user:id,name,role'),
        ], 200);
    }

    public function destroy($id)
    {
        $nasabah = DataNasabah::find($id);
        if (!$nasabah) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        
        $nasabah->delete();
        return response()->json(['success' => true, 'message' => 'Data nasabah berhasil dihapus.'], 200);
    }

    public function show($id)
    {
        $nasabah = DataNasabah::with('user:id,name,role')->find($id);

        if (!$nasabah) {
            return response()->json([
                'success' => false,
                'message' => 'Data nasabah tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data nasabah ditemukan.',
            'data'    => $nasabah,
        ], 200);
    }
}