<?php

namespace App\Http\Controllers;

use App\Models\DetailGadai;
use Illuminate\Http\Request;

class ApprovalFilterController extends Controller
{
    /**
     * Filter data "selesai" berdasarkan bulan & tahun
     * 
     * @param Request $request
     *  - bulan: integer (1-12)
     *  - tahun: integer (ex: 2025)
     */
    public function filterByMonthYear(Request $request)
    {
        $request->validate([
            'bulan' => 'required|integer|min:1|max:12',
            'tahun' => 'required|integer|min:2000|max:2100',
        ]);

        $bulan = $request->bulan;
        $tahun = $request->tahun;

        $perPage = 10;
        $query = DetailGadai::with([
            'type', 
            'nasabah.user', 
            'approvals.user',
            'hp', 
            'perhiasan', 
            'logamMulia', 
            'retro', 
            'perpanjanganTempos'
        ])
        // Hanya yang sudah di-approve/reject oleh kedua role
        ->whereHas('approvals', function($q){
            $q->whereIn('role', ['checker','hm'])
              ->whereIn('status', ['approved_checker','rejected_checker','approved_hm','rejected_hm']);
        })
        // Filter bulan & tahun dari tanggal dibuatnya record
        ->whereYear('created_at', $tahun)
        ->whereMonth('created_at', $bulan)
        ->orderBy('created_at','desc');

        $data = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => "Data selesai untuk bulan {$bulan} tahun {$tahun}",
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }
}
