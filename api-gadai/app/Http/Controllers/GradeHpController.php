<?php

namespace App\Http\Controllers;

use App\Models\GradeHp;
use App\Models\HargaHp;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;

class GradeHpController extends Controller
{
    protected $gradeCalculator;

    public function __construct(GradeCalculatorService $gradeCalculator)
    {
        $this->gradeCalculator = $gradeCalculator;
    }


    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $grades = GradeHp::with('hargaHp.typeHp.merk')
            ->orderBy('id', 'DESC')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $grades
        ]);
    }


    public function store(Request $request)
    {
        $request->validate([
            'harga_hp_id' => 'required|exists:harga_hp,id',
            'pasar_trend' => 'nullable|in:naik,turun',
        ]);

        if (GradeHp::where('harga_hp_id', $request->harga_hp_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Grade untuk harga HP ini sudah ada'
            ], 422);
        }

        $hargaHp = HargaHp::findOrFail($request->harga_hp_id);
        $pasarTrend = $request->input('pasar_trend', 'turun');
        $calculatedData = $this->gradeCalculator->calculateAllGrades(
            $hargaHp->harga_barang,
            $pasarTrend
        );
        $grade = GradeHp::create(array_merge(
            ['harga_hp_id' => $request->harga_hp_id],
            $calculatedData
        ));

        return response()->json([
            'success' => true,
            'message' => 'Grade & Taksiran berhasil ditambahkan',
            'data' => $grade
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $grade = GradeHp::findOrFail($id);
        $request->validate(['pasar_trend' => 'nullable|in:naik,turun']);

        $hargaHp = $grade->hargaHp;
        $pasarTrend = $request->input('pasar_trend', 'turun');

        $calculatedData = $this->gradeCalculator->calculateAllGrades(
            $hargaHp->harga_barang,
            $pasarTrend
        );

        $grade->update($calculatedData);

        return response()->json([
            'success' => true,
            'message' => 'Kalkulasi ulang Grade & Taksiran berhasil',
            'data' => $grade
        ]);
    }

    public function updateManual(Request $request, $id)
    {
        $grade = GradeHp::findOrFail($id);

        $request->validate([
            'grade_a_dus' => 'required|integer|min:0',
            'grade_a_tanpa_dus' => 'required|integer|min:0',
            'grade_b_dus' => 'required|integer|min:0',
            'grade_b_tanpa_dus' => 'required|integer|min:0',
            'grade_c_dus' => 'required|integer|min:0',
            'grade_c_tanpa_dus' => 'required|integer|min:0',
            'taksiran_a_dus' => 'required|integer|min:0',
            'taksiran_a_tanpa_dus' => 'required|integer|min:0',
            'taksiran_b_dus' => 'required|integer|min:0',
            'taksiran_b_tanpa_dus' => 'required|integer|min:0',
            'taksiran_c_dus' => 'required|integer|min:0',
            'taksiran_c_tanpa_dus' => 'required|integer|min:0',
        ]);

        $grade->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diupdate secara manual',
            'data' => $grade
        ]);
    }


    public function previewCalculation(Request $request)
    {
        $request->validate([
            'harga_barang' => 'required|integer|min:0',
            'pasar_trend' => 'nullable|in:naik,turun',
        ]);

        $hasil = $this->gradeCalculator->calculateAllGrades(
            $request->harga_barang, 
            $request->input('pasar_trend', 'turun')
        );

        return response()->json([
            'success' => true,
            'hasil_kalkulasi' => $hasil
        ]);
    }

    public function destroy($id)
    {
        $grade = GradeHp::findOrFail($id);
        $grade->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil dihapus'
        ]);
    }

public function getByMerk($merkId)
{
    $perPage = request()->get('per_page', 10);

    $grades = GradeHp::with(['hargaHp.typeHp.merk'])
        ->whereHas('hargaHp.typeHp', function ($q) use ($merkId) {
            $q->where('merk_hp_id', $merkId);
        })

        ->whereHas('hargaHp', function ($q) {
            $q->where('harga_barang', '>', 0);
        })
        ->orderBy('id', 'DESC')
        ->paginate($perPage);

    return response()->json([
        'success' => true,


        'data' => $grades->items(),

        'meta' => [
            'current_page' => $grades->currentPage(),
            'last_page'    => $grades->lastPage(),
            'per_page'     => $grades->perPage(),
            'total'        => $grades->total(),
        ]
    ]);
}

}