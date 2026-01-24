<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\DetailGadai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApprovalController extends Controller
{
    public function getAll(Request $request)
    {
        $perPage = 10; 
        $user = Auth::user();

        $query = DetailGadai::with([
            'type', 'nasabah.user', 'approvals.user',
            'hp', 'perhiasan', 'logamMulia', 'retro', 'perpanjanganTempos'
        ])->orderBy('created_at', 'desc');

        if ($user->role === 'hm') {
            $query->whereHas('approvals', function ($q) {
                $q->whereIn('status', ['approved_checker','rejected_checker']);
            });
        } else {
            $query->where('status', 'Selesai')
                  ->whereDoesntHave('approvals', function ($q) {
                      $q->whereIn('status', ['approved_checker','rejected_checker','approved_hm','rejected_hm']);
                  });
        }

        $data = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => $user->role === 'hm'
                ? 'Data yang sudah diapprove/reject oleh checker.'
                : 'Data selesai yang belum diapprove/reject.',
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }

    public function getApprovalDetail($detailGadaiId, Request $request)
    {
        $detail = DetailGadai::with([
            'nasabah', 'type', 'approvals.user', 'perpanjanganTempos'
        ])->findOrFail($detailGadaiId);
        $hp = $detail->hp()->paginate($request->get('hp_per_page', 10));
        $perhiasan = $detail->perhiasan()->paginate($request->get('perhiasan_per_page', 10));
        $logamMulia = $detail->logamMulia()->paginate($request->get('logamMulia_per_page', 10));
        $retro = $detail->retro()->paginate($request->get('retro_per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Detail Approval lengkap (lazy loaded)',
            'data' => [
                'detail_gadai' => $detail,
                'hp' => $hp,
                'perhiasan' => $perhiasan,
                'logam_mulia' => $logamMulia,
                'retro' => $retro,
            ]
        ]);
    }

public function updateApprovalDetail(Request $request, $detailGadaiId)
{
    $user = Auth::user();
    if (!$user || $user->role !== 'hm') {
        return response()->json(['success' => false, 'message' => 'Hanya HM yang bisa edit'], 403);
    }

    $detail = DetailGadai::findOrFail($detailGadaiId);
    $request->validate([
        'detail_gadai.uang_pinjaman' => 'nullable|numeric',
        'detail_gadai.taksiran' => 'nullable|numeric',
        'detail_gadai.tanggal_gadai' => 'nullable|date',
        'detail_gadai.jatuh_tempo' => 'nullable|date',

        'perpanjangan_tempos' => 'nullable|array',
        'perpanjangan_tempos.*.id' => 'required|exists:perpanjangan_tempos,id',
        'perpanjangan_tempos.*.tanggal_perpanjangan' => 'nullable|date',
        'perpanjangan_tempos.*.jatuh_tempo_baru' => 'nullable|date',
    ]);
    $detailData = $request->input('detail_gadai', []);
    if (!empty($detailData)) {
        $updateData = array_filter($detailData, fn($v) => !is_null($v));

        if (!empty($updateData)) {
            $detail->update($updateData);
        }
    }
    $perpanjangan = $request->input('perpanjangan_tempos', []);
    foreach ($perpanjangan as $p) {
        $tempo = $detail->perpanjanganTempos()->find($p['id']);
        if ($tempo) {
            $updateTempo = array_filter([
                'tanggal_perpanjangan' => $p['tanggal_perpanjangan'] ?? null,
                'jatuh_tempo_baru' => $p['jatuh_tempo_baru'] ?? null,
            ], fn($v) => !is_null($v));

            if (!empty($updateTempo)) {
                $tempo->update($updateTempo);
            }
        }
    }
    $detail->load(['perpanjanganTempos', 'nasabah', 'type', 'hp', 'perhiasan', 'logamMulia', 'retro']);

    return response()->json([
        'success' => true,
        'message' => 'Detail Approval berhasil diperbarui',
        'data' => $detail,
    ]);
}



public function updateApprovalDetailChecker(Request $request, $detailGadaiId)
{
    $user = Auth::user();
    if (!$user || $user->role !== 'checker') {
        return response()->json(['success' => false, 'message' => 'Hanya Checker yang bisa edit'], 403);
    }

    $detail = DetailGadai::findOrFail($detailGadaiId);
    $request->validate([
        'detail_gadai.uang_pinjaman' => 'nullable|numeric',
        'detail_gadai.taksiran' => 'nullable|numeric',
        'detail_gadai.tanggal_gadai' => 'nullable|date',
        'detail_gadai.jatuh_tempo' => 'nullable|date',

        'perpanjangan_tempos' => 'nullable|array',
        'perpanjangan_tempos.*.id' => 'required|exists:perpanjangan_tempos,id',
        'perpanjangan_tempos.*.tanggal_perpanjangan' => 'nullable|date',
        'perpanjangan_tempos.*.jatuh_tempo_baru' => 'nullable|date',
    ]);
    $detailData = $request->input('detail_gadai', []);
    if (!empty($detailData)) {
        $updateData = array_filter($detailData, fn($v) => !is_null($v));
        if (!empty($updateData)) {
            $detail->update($updateData);
        }
    }
    $perpanjangan = $request->input('perpanjangan_tempos', []);
    foreach ($perpanjangan as $p) {
        $tempo = $detail->perpanjanganTempos()->find($p['id']);
        if ($tempo) {
            $updateTempo = array_filter([
                'tanggal_perpanjangan' => $p['tanggal_perpanjangan'] ?? null,
                'jatuh_tempo_baru' => $p['jatuh_tempo_baru'] ?? null,
            ], fn($v) => !is_null($v));

            if (!empty($updateTempo)) {
                $tempo->update($updateTempo);
            }
        }
    }
    $detail->load(['perpanjanganTempos', 'nasabah', 'type', 'hp', 'perhiasan', 'logamMulia', 'retro']);

    return response()->json([
        'success' => true,
        'message' => 'Detail Approval Checker berhasil diperbarui',
        'data' => $detail,
    ]);
}

    public function updateStatus(Request $request, $detailGadaiId)
    {
        $request->validate([
            'status' => 'required|in:approved_checker,rejected_checker,approved_hm,rejected_hm',
            'catatan' => 'nullable|string',
        ]);

        $user = Auth::user();
        if (!$user || !in_array($user->role, ['checker', 'hm'])) {
            return response()->json(['success' => false, 'message' => 'Tidak memiliki akses approval'], 403);
        }

        $detailGadai = DetailGadai::findOrFail($detailGadaiId);

        $existing = Approval::where('detail_gadai_id', $detailGadaiId)
            ->where('user_id', $user->id)
            ->where('role', $user->role)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan approve/reject untuk data ini sebagai ' . strtoupper($user->role)
            ], 400);
        }
        $approval = Approval::create([
            'detail_gadai_id' => $detailGadaiId,
            'user_id' => $user->id,
            'role' => $user->role,
            'status' => $request->status,
            'catatan' => $request->catatan,
        ]);
        if ($user->role === 'checker') {
            $detailGadai->update(['status_checker' => $request->status]);
        } elseif ($user->role === 'hm') {
            $detailGadai->update(['status_hm' => $request->status]);
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst($user->role) . ' berhasil melakukan ' . str_replace('_', ' ', $request->status),
            'data' => $approval,
        ]);
    }
    public function getCheckerApproved(Request $request) { return $this->filterByRoleStatus('checker','approved_checker','Data approved oleh Checker',$request); }
    public function getCheckerRejected(Request $request) { return $this->filterByRoleStatus('checker','rejected_checker','Data rejected oleh Checker',$request); }
    public function getHmApproved(Request $request) { return $this->filterByRoleStatus('hm','approved_hm','Data approved oleh HM',$request); }
    public function getHmRejected(Request $request) { return $this->filterByRoleStatus('hm','rejected_hm','Data rejected oleh HM',$request); }
    public function getFinished(Request $request) {
        return $this->filterByRoleStatus(
            ['checker','hm'],
            ['approved_checker','rejected_checker','approved_hm','rejected_hm'],
            'Data sudah selesai di-approve/reject Checker & HM',
            $request
        );
    }

    private function filterByRoleStatus($role, $status, $message, Request $request)
    {
        $perPage = 10;
        $query = DetailGadai::with([
            'type', 'nasabah.user', 'approvals.user',
            'hp', 'perhiasan', 'logamMulia', 'retro', 'perpanjanganTempos'
        ]);

        if(is_array($role) && is_array($status)){
            $query->whereHas('approvals', function($q) use($role,$status){
                $q->whereIn('role',$role)->whereIn('status',$status);
            });
        }else{
            $query->whereHas('approvals', function($q) use($role,$status){
                $q->where('role',$role)->where('status',$status);
            });
        }

        $data = $query->orderBy('created_at','desc')->paginate($perPage);

        return response()->json([
            'success'=>true,
            'message'=>$message,
            'data'=>$data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ]
        ]);
    }
}