<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\DetailGadai;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Helper: Cek apakah gadai termasuk limit kecil (tidak perlu approval HM)
     */
    private function isSmallLimit($detailGadai)
    {
        $nominal = (float) $detailGadai->uang_pinjaman;
        $namaType = strtolower($detailGadai->type->nama_type ?? '');

        // HP: <= 2 juta
        if (str_contains($namaType, 'hp') || str_contains($namaType, 'handphone')) {
            return $nominal <= 2000000;
        }

        // Emas/Perhiasan/LogamMulia/Retro: <= 4 juta
        if (
            str_contains($namaType, 'logam_mulia') || 
            str_contains($namaType, 'retro') || 
            str_contains($namaType, 'emas') || 
            str_contains($namaType, 'perhiasan')
        ) {
            return $nominal <= 4000000;
        }

        return false; // Default: perlu approval HM
    }

    public function getAll(Request $request)
    {
        $perPage = 10; 
        $user = Auth::user();

        $query = DetailGadai::with([
            'type', 'nasabah.user', 'approvals.user',
            'hp', 'perhiasan', 'logamMulia', 'retro', 'perpanjanganTempos'
        ])->orderBy('created_at', 'desc');

        if ($user->role === 'hm') {
            // HM: hanya tampilkan yang BUKAN limit kecil DAN sudah approved/rejected checker
            $query->whereHas('approvals', function ($q) {
                $q->whereIn('status', ['approved_checker','rejected_checker']);
            });

            // Filter out gadai dengan limit kecil
            $data = $query->paginate($perPage);
            $filtered = $data->getCollection()->filter(function ($item) {
                return !$this->isSmallLimit($item);
            });
            $data->setCollection($filtered->values());

        } else {
            // Checker: tampilkan semua yang status "Selesai" dan belum ada approval apapun
            $query->where('status', 'Selesai')
                  ->whereDoesntHave('approvals', function ($q) {
                      $q->whereIn('status', ['approved_checker','rejected_checker','approved_hm','rejected_hm']);
                  });
            $data = $query->paginate($perPage);
        }

        return response()->json([
            'success' => true,
            'message' => $user->role === 'hm'
                ? 'Data yang sudah diapprove/reject oleh checker (kecuali limit kecil).'
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

        $detailGadai = DetailGadai::with('type')->findOrFail($detailGadaiId);

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

        DB::beginTransaction();
        try {
            $approval = Approval::create([
                'detail_gadai_id' => $detailGadaiId,
                'user_id' => $user->id,
                'role' => $user->role,
                'status' => $request->status,
                'catatan' => $request->catatan,
            ]);

            if ($user->role === 'checker') {
                $detailGadai->update(['status_checker' => $request->status]);
                
                // CEK: Jika limit kecil dan checker approve → AUTO APPROVE HM
                if ($request->status === 'approved_checker' && $this->isSmallLimit($detailGadai)) {
                    // Buat approval HM otomatis oleh sistem
                    Approval::create([
                        'detail_gadai_id' => $detailGadaiId,
                        'user_id' => $user->id, // bisa diganti ke user ID sistem jika ada
                        'role' => 'hm',
                        'status' => 'approved_hm',
                        'catatan' => 'Auto-approved oleh sistem karena limit di bawah threshold (≤2jt untuk HP, ≤4jt untuk Emas/Perhiasan)',
                    ]);
                    $detailGadai->update(['status_hm' => 'approved_hm']);
                    
                    // Kirim notif approval final langsung
                    $this->notificationService->notifyApprovalStatus(
                        $detailGadai, 
                        'approved_hm', 
                        'Auto-approved (limit kecil)'
                    );
                } else {
                    // Limit besar: kirim notif ke HM seperti biasa
                    if (in_array($request->status, ['approved_checker', 'rejected_checker'])) {
                        $this->notificationService->notifyRequestApprovalToHM($detailGadai);
                    }
                }
                
            } elseif ($user->role === 'hm') {
                $detailGadai->update(['status_hm' => $request->status]);
                
                // Kirim notifikasi approval status dari HM
                $this->notificationService->notifyApprovalStatus(
                    $detailGadai, 
                    $request->status, 
                    $request->catatan
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => ucfirst($user->role) . ' berhasil melakukan ' . str_replace('_', ' ', $request->status),
                'data' => $approval,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan approval: ' . $e->getMessage()
            ], 500);
        }
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