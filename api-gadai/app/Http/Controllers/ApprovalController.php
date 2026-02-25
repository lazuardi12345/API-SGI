<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\DetailGadai;
use App\Services\ApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    protected $service;
    protected $notificationService;

    public function __construct(ApprovalService $service, NotificationService $notificationService)
    {   
        $this->service = $service;
        $this->notificationService = $notificationService;
    }

    public function getAll(Request $request)
    {
        $user = Auth::user();
        $query = $this->service->getPendingQuery($user);
        
        $data = $query->orderBy('created_at', 'desc')->paginate(10);
        if ($user->role === 'hm') {
            $filtered = $data->getCollection()->filter(fn($item) => !$this->service->isSmallLimit($item));
            $data->setCollection($filtered->values());
        }

        $transformed = $data->getCollection()->map(fn($item) => $this->service->formatItem($item));

        return response()->json([
            'payload' => [
                'error' => false,
                'message' => 'Data approval berhasil dimuat',
                'reference' => 'APPROVAL_FETCH_SUCCESS',
                'data' => [
                    'items' => $transformed,
                    'pagination' => [
                        'current_page' => $data->currentPage(),
                        'last_page' => $data->lastPage(),
                        'total' => $data->total(),
                    ]
                ]
            ]
        ]);
    }


    public function getApprovalDetail($id, Request $request)
    {
        $detail = DetailGadai::with([
            'nasabah', 'type', 'approvals.user', 'perpanjanganTempos'
        ])->findOrFail($id);

        return response()->json([
            'payload' => [
                'error' => false,
                'message' => 'Detail retrieved',
                'reference' => 'DETAIL_OK',
                'data' => [
                    'detail_gadai' => $detail,
                    'hp' => $detail->hp()->paginate($request->get('hp_per_page', 10)),
                    'perhiasan' => $detail->perhiasan()->paginate($request->get('per_page', 10)),
                    'logam_mulia' => $detail->logamMulia()->paginate($request->get('per_page', 10)),
                    'retro' => $detail->retro()->paginate($request->get('per_page', 10)),
                ]
            ]
        ]);
    }

    public function updateApprovalDetail(Request $request, $id)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['hm', 'checker'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $detail = DetailGadai::findOrFail($id);
        
        $request->validate([
            'detail_gadai.uang_pinjaman' => 'nullable|numeric',
            'detail_gadai.taksiran'      => 'nullable|numeric',
            'perpanjangan_tempos'        => 'nullable|array',
        ]);

        DB::transaction(function () use ($request, $detail) {
            if ($data = $request->input('detail_gadai')) {
                $detail->update(array_filter($data));
            }
            if ($tempos = $request->input('perpanjangan_tempos')) {
                foreach ($tempos as $p) {
                    $detail->perpanjanganTempos()->where('id', $p['id'])->update(array_filter($p));
                }
            }
        });

        return response()->json([
            'payload' => [
                'error' => false,
                'message' => 'Data berhasil diperbarui',
                'data' => $detail->load('perpanjanganTempos')
            ]
        ]);
    }


public function updateStatus(Request $request, $id)
{
    $request->validate([
        'status' => 'required|in:approved_checker,rejected_checker,approved_hm,rejected_hm',
        'catatan' => 'nullable|string',
    ]);

    $user = Auth::user();
    $detailGadai = DetailGadai::with('type')->findOrFail($id);

    if (Approval::where(['detail_gadai_id' => $id, 'user_id' => $user->id, 'role' => $user->role])->exists()) {
        return response()->json(['error' => 'Anda sudah memproses data ini'], 400);
    }

    DB::beginTransaction();
    try {
        $catatanInput = $request->catatan ?? '-';
        $approval = Approval::create([
            'detail_gadai_id' => $id,
            'user_id' => $user->id,
            'role' => $user->role,
            'status' => $request->status,
            'catatan' => $catatanInput,
        ]);

        if ($user->role === 'checker') {
            $updateData = ['status' => 'Selesai']; 

            if ($request->status === 'rejected_checker') {
                $updateData['status_checker'] = 'rejected_checker';
                $updateData['status_hm'] = 'rejected_hm';
            } else {
                $updateData['status_checker'] = 'approved_checker';
                
                // --- AUTO APPROVE SBG (Limit Kecil) ---
                if ($this->service->isSmallLimit($detailGadai)) {
                    $updateData['status_hm'] = 'approved_hm';
                    // Di sini kita otomatiskan approval_status buat SBG
                    $updateData['approval_status'] = 'approved'; 
                }
            }
            $detailGadai->update($updateData);

            if ($request->status === 'approved_checker' && !$this->service->isSmallLimit($detailGadai)) {
                $this->notificationService->notifyRequestApprovalToHM($detailGadai);
            }
        } 

        elseif ($user->role === 'hm') {
            $updateData = [
                'status_hm' => $request->status,
                'status' => 'Selesai'
            ];

            // --- AUTO APPROVE SBG (Pas HM Klik Approve) ---
            if ($request->status === 'approved_hm') {
                $updateData['approval_status'] = 'approved';
            }

            $detailGadai->update($updateData);
        }

        DB::commit();
        return response()->json(['payload' => ['error' => false, 'message' => 'Berhasil', 'data' => $approval]]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    public function getApprovalStats()
    {
        $user = Auth::user();
        $pendingQuery = $this->service->getPendingQuery($user);
        
        $count = ($user->role === 'hm') 
            ? $pendingQuery->get()->filter(fn($i) => !$this->service->isSmallLimit($i))->count()
            : $pendingQuery->count();

        $unread = 0;
        try {
            $nestRes = app(NotificationServiceController::class)->getUserNotifications($user->id, 1);
            $unread = $nestRes['data']['totalUnread'] ?? 0;
        } catch (\Exception $e) {}

        return response()->json([
            'payload' => [
                'error' => false,
                'message' => 'Stats retrieved',
                'reference' => 'APPROVAL_NOTIFICATION_OK',
                'data' => [
                    'total' => $count,
                    'unread_notifications' => $unread,
                    'role' => $user->role
                ]
            ]
        ]);
    }

    public function getHistory(Request $request)
    {
        $user = Auth::user();
        $data = DetailGadai::whereHas('approvals', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with(['type', 'nasabah', 'approvals'])->orderBy('updated_at', 'desc')->paginate(10);

        $transformed = $data->getCollection()->map(function($item) use ($user) {
            $myAction = $item->approvals->where('user_id', $user->id)->first();
            $res = $this->service->formatItem($item);
            $res['keputusan_saya'] = $myAction->status ?? '-';
            $res['tanggal_proses'] = $myAction->created_at->format('Y-m-d H:i');
            return $res;
        });

        return response()->json([
            'payload' => [
                'error' => false,
                'data' => ['items' => $transformed, 'pagination' => ['total' => $data->total()]]
            ]
        ]);
    }


    public function getCheckerApproved(Request $request) { 
    return $this->filterByRoleStatus('checker', 'approved_checker', 'Data approved oleh Checker', $request); 
}

public function getCheckerRejected(Request $request) { 
    return $this->filterByRoleStatus('checker', 'rejected_checker', 'Data rejected oleh Checker', $request); 
}

public function getHmApproved(Request $request) { 
    return $this->filterByRoleStatus('hm', 'approved_hm', 'Data approved oleh HM', $request); 
}

public function getHmRejected(Request $request) { 
    return $this->filterByRoleStatus('hm', 'rejected_hm', 'Data rejected oleh HM', $request); 
}

public function getFinished(Request $request) {
    return $this->filterByRoleStatus(
        ['checker', 'hm'],
        ['approved_checker', 'rejected_checker', 'approved_hm', 'rejected_hm'],
        'Data sudah selesai di-approve/reject oleh Checker & HM',
        $request
    );
}


private function filterByRoleStatus($role, $status, $message, Request $request)
{
    $query = DetailGadai::with(['type', 'nasabah', 'approvals.user', 'hp', 'perhiasan', 'logamMulia', 'retro']);

    $query->whereHas('approvals', function($q) use ($role, $status) {
        if (is_array($role)) $q->whereIn('role', $role);
        else $q->where('role', $role);

        if (is_array($status)) $q->whereIn('status', $status);
        else $q->where('status', $status);
    });

    $data = $query->orderBy('created_at', 'desc')->paginate(10);
    $items = $data->getCollection()->map(fn($item) => $this->service->formatItem($item));

    return response()->json([
        'payload' => [
            'error' => false,
            'message' => $message,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'total' => $data->total()
                ]
            ]
        ]
    ]);
}
}