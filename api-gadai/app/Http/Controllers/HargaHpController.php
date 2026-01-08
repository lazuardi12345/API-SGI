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

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $hargaHp = HargaHp::with(['typeHp.merk', 'grades'])
            ->orderBy('id', 'DESC')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $hargaHp
        ]);
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

        return response()->json([
            'success' => true,
            'message' => 'Harga HP berhasil diperbarui' . ($gradeUpdated ? ' (Grade & Taksiran diperbarui)' : ''),
            'data' => $harga->load(['typeHp.merk', 'grades'])
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

    public function getByType($typeHpId)
    {
        $harga = HargaHp::with(['typeHp.merk', 'grades'])
            ->where('type_hp_id', $typeHpId)
            ->first();

        if (!$harga) {
            return response()->json([
                'success' => false, 
                'message' => 'Data harga untuk tipe ini belum di-set di Master Harga',
                'data' => null
            ]);
        }

        return response()->json(['success' => true, 'data' => $harga]);
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