<?php

namespace App\Http\Controllers;

use App\Models\HargaHp;
use App\Models\GradeHp;
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
            'pasar_trend' => 'nullable|in:naik,turun',
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
        ]);

        $autoGenerate = $request->input('auto_generate_grade', true);
        $gradeData = null;

        if ($autoGenerate) {
            $pasarTrend = $request->input('pasar_trend', 'turun');
            $calculatedData = $this->gradeCalculator->calculateAllGrades(
                $request->harga_barang,
                $pasarTrend
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
        'pasar_trend' => 'nullable|in:naik,turun',
        'recalculate_grade' => 'nullable|boolean',
    ]);

    $oldHarga = $harga->harga_barang;
    $harga->update($request->only(['harga_barang']));

    $recalculate = $request->input('recalculate_grade', $oldHarga != $request->harga_barang);
    $gradeUpdated = false;

    if ($recalculate) {
        $grade = GradeHp::where('harga_hp_id', $harga->id)->first();
        if ($grade) {
            $pasarTrend = $request->input('pasar_trend', 'turun');
            $calculatedData = $this->gradeCalculator->calculateAllGrades(
                $request->harga_barang,
                $pasarTrend
            );
            $grade->update($calculatedData);
            $gradeUpdated = true;
        }
    }

    // Load data terbaru
    $harga->load(['typeHp.merk', 'grades']);

    return response()->json([
        'success' => true,
        'message' => 'Harga HP berhasil diperbarui',
        'data' => $harga,
        // Tambahkan info ini:
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

   public function getByMerk($merkId)
{
    $data = TypeHp::where('merk_hp_id', $merkId)
        ->select('type_hps.*', 'harga_hps.harga_barang', 'harga_hps.id as id_harga', 'harga_hps.updated_at as updated_at_harga')
        ->leftJoin('harga_hps', 'type_hps.id', '=', 'harga_hps.type_hp_id')
        ->orderBy('harga_hps.updated_at', 'desc') 
        ->get();

    return response()->json(['success' => true, 'data' => $data]);
}

    public function getGradeByType($typeHpId)
    {
        $harga = HargaHp::with(['typeHp.merk', 'grades'])
            ->where('type_hp_id', $typeHpId)
            ->first();

        if (!$harga || !$harga->grades) {
            return response()->json([
                'success' => false,
                'message' => 'Grade belum dibuat untuk type ini',
                'data' => null
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $harga->grades
        ]);
    }
}