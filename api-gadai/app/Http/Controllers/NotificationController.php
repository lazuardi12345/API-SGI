<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function getNew(Request $request)
    {
        $user = Auth::user();
        $role = strtolower($user->role);

        $query = Approval::with(['detailGadai.nasabah.user']);

        switch ($role) {
            case 'petugas':
                $query->whereIn('role', ['checker', 'hm']);
                break;
            case 'checker':
                $query->where('role', 'hm');
                break;
            case 'hm':
                $query->where('role', 'checker');
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Role tidak memiliki notifikasi'], 403);
        }

        $approvals = $query->where('is_read', 0) 
                           ->orderBy('created_at', 'desc')
                           ->get();

        $data = $approvals->map(fn($item) => [
            'id' => $item->id,
            'status' => $item->status ?? '-',
            'catatan' => $item->catatan ?? '-',
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            'nasabah' => $item->detailGadai?->nasabah?->nama_lengkap ?? '-',
            'marketing' => $item->detailGadai?->nasabah?->user?->name ?? '-',
        ]);

        return response()->json(['success' => true, 'message' => 'Notifikasi baru', 'data' => $data]);
    }
    public function getAll(Request $request)
    {
        $user = Auth::user();
        $role = strtolower($user->role);

        $query = Approval::with(['detailGadai.nasabah.user']);

        switch ($role) {
            case 'petugas':
                $query->whereIn('role', ['checker', 'hm']);
                break;
            case 'checker':
                $query->where('role', 'hm');
                break;
            case 'hm':
                $query->where('role', 'checker');
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Role tidak memiliki notifikasi'], 403);
        }

        $approvals = $query->orderBy('created_at', 'desc')->get();

        $data = $approvals->map(fn($item) => [
            'id' => $item->id,
            'status' => $item->status ?? '-',
            'catatan' => $item->catatan ?? '-',
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            'nasabah' => $item->detailGadai?->nasabah?->nama_lengkap ?? '-',
            'marketing' => $item->detailGadai?->nasabah?->user?->name ?? '-',
            'is_read' => (bool) $item->is_read,
        ]);

        return response()->json(['success' => true, 'message' => 'Riwayat notifikasi', 'data' => $data]);
    }
    public function markAsRead(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:approvals,id',
        ]);

        Approval::whereIn('id', $request->ids)->update(['is_read' => 1]);

        return response()->json(['success' => true, 'message' => 'Notifikasi telah dibaca']);
    }
}
