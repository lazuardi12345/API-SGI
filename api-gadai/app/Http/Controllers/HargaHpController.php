<?php

namespace App\Http\Controllers;

use App\Models\HargaHp;
use App\Models\GradeHp;
use App\Models\TypeHp;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;

class HargaHpController extends Controller
{
    protected $gradeCalculator;

    public function __construct(GradeCalculatorService $gradeCalculator)
    {
        $this->gradeCalculator = $gradeCalculator;
    }

    public function store(Request $request)
    {
        $request->validate([
            'type_hp_id' => 'required|exists:type_hp,id',
            'harga_barang' => 'required|integer|min:0',
            'harga_pasar' => 'required|integer|min:0',
            'auto_generate_grade' => 'nullable|boolean',
        ]);

        if (HargaHp::where('type_hp_id', $request->type_hp_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Harga HP untuk type ini sudah ada'
            ], 422);
        }

        $harga = HargaHp::create([
            'type_hp_id' => $request->type_hp_id,
            'harga_barang' => $request->harga_barang,
            'harga_pasar' => $request->harga_pasar,
        ]);

        $autoGenerate = $request->input('auto_generate_grade', true);
        $gradeData = null;

        if ($autoGenerate) {
            $calculatedData = $this->gradeCalculator->calculateAllGrades(
                $request->harga_barang,
                $request->harga_pasar
            );
            $grade = GradeHp::create(array_merge(
                ['harga_hp_id' => $harga->id],
                $calculatedData
            ));

            $gradeData = $grade;
        }

        return response()->json([
            'success' => true,
            'message' => 'Harga HP berhasil ditambahkan' . ($autoGenerate ? ' dengan grade & taksiran otomatis' : ''),
            'data' => [
                'harga_hp' => $harga->load('typeHp.merk'),
                'grade' => $gradeData,
            ]
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $harga = HargaHp::findOrFail($id);

        $request->validate([
            'harga_barang' => 'required|integer|min:0',
            'harga_pasar' => 'required|integer|min:0',
            'recalculate_grade' => 'nullable|boolean',
        ]);

        $oldHargaBarang = $harga->harga_barang;
        $oldHargaPasar = $harga->harga_pasar;
        
        $harga->update([
            'harga_barang' => $request->harga_barang,
            'harga_pasar' => $request->harga_pasar,
        ]);

        $recalculate = $request->input('recalculate_grade', 
            $oldHargaBarang != $request->harga_barang || $oldHargaPasar != $request->harga_pasar
        );
        $gradeUpdated = false;

        if ($recalculate) {
            $grade = GradeHp::where('harga_hp_id', $harga->id)->first();
            if ($grade) {
                $calculatedData = $this->gradeCalculator->calculateAllGrades(
                    $request->harga_barang,
                    $request->harga_pasar
                );
                $grade->update($calculatedData);
                $gradeUpdated = true;
            }
        }

        $harga->load(['typeHp.merk', 'grades']);

        return response()->json([
            'success' => true,
            'message' => 'Harga HP berhasil diperbarui' . ($gradeUpdated ? ' dan grade telah dikalkulasi ulang' : ''),
            'data' => $harga,
            'last_edit' => [
                'raw' => $harga->updated_at, 
                'formatted' => $harga->updated_at->translatedFormat('d F Y, H:i'), 
                'human' => $harga->updated_at->diffForHumans(), 
            ]
        ]);
    }

    public function destroy($id)
    {
        $harga = HargaHp::findOrFail($id);
        GradeHp::where('harga_hp_id', $id)->delete();
        $harga->delete();

        return response()->json([
            'success' => true,
            'message' => 'Harga HP dan data Grade/Taksiran berhasil dihapus'
        ]);
    }

    public function show($id)
    {
        $harga = HargaHp::with(['typeHp.merk', 'grades'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $harga]);
    }

 public function getByMerk(Request $request, $merkId)
{
    $perPage = $request->get('per_page', 10); 

    $data = TypeHp::where('merk_hp_id', $merkId)
        ->select(
            'type_hps.*', 
            'harga_hps.harga_barang', 
            'harga_hps.harga_pasar', 
            'harga_hps.id as id_harga', 
            'harga_hps.updated_at as updated_at_harga'
        )
        ->leftJoin('harga_hps', 'type_hps.id', '=', 'harga_hps.type_hp_id')
        ->orderBy('harga_hps.updated_at', 'desc') 
        ->paginate($perPage); 

    return response()->json(['success' => true, 'data' => $data]);
}

   public function getGradeByType(Request $request, $typeHpId)
{
    $perPage = $request->get('per_page', 10);
    $grades = Grade::whereHas('hargaHp', function($q) use ($typeHpId) {
            $q->where('type_hp_id', $typeHpId);
        })
        ->paginate($perPage);

    if ($grades->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'Grade belum dibuat atau kosong untuk type ini',
            'data' => null
        ]);
    }

    return response()->json([
        'success' => true,
        'data' => $grades
    ]);
}
    public function getByType(Request $request, $type_hp_id)
{
    try {
        $perPage = $request->get('per_page', 10);

        $harga = HargaHp::with('grades')
            ->where('type_hp_id', $type_hp_id)
            ->paginate($perPage); 

        return response()->json([
            'success' => true,
            'data' => $harga
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function getGradeDetailByType($type_hp_id)
{
    try {
        $harga = HargaHp::with(['typeHp.merk', 'grades'])
            ->where('type_hp_id', $type_hp_id)
            ->first(); 

        if (!$harga) {
            return response()->json([
                'success' => false,
                'message' => 'Data harga atau grade untuk type ini belum disetting',
                'data' => null
            ], 404);
        }
        return response()->json([
            'success' => true,
            'data' => [
                'type_info' => [
                    'id' => $harga->typeHp->id,
                    'nama_type' => $harga->typeHp->nama_type,
                    'merk' => $harga->typeHp->merk->nama_merk,
                ],
                'harga_dasar' => [
                    'harga_barang' => $harga->harga_barang,
                    'harga_pasar' => $harga->harga_pasar,
                ],
                'grades' => $harga->grades->first() 
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal ambil data: ' . $e->getMessage()
        ], 500);
    }
}
}