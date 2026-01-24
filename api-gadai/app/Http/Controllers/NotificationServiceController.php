<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Carbon\Carbon;

class NotificationServiceController extends Controller
{
    private $notificationServiceUrl;
    private $jwtSecret;

    public function __construct()
    {
        $this->notificationServiceUrl = rtrim(env('NOTIFICATION_SERVICE_URL'), '/');
        $this->jwtSecret = env('JWT_SECRET');
    }

    private function generateToken()
    {
        $user = auth()->user();
        $payload = [
            'service' => 'laravel-pawns-apps',
            'sub'     => $user ? $user->id : 0,
            'role'    => $user ? strtolower($user->role) : 'petugas', 
            'iat'     => time(),
            'exp'     => time() + 300, 
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }


public function sendNotification(array $data)
    {
        Log::info('--- Notification Attempt Start ---', ['raw_input' => $data]);

        try {
            $token = $this->generateToken();
            
            // ✅ Base payload yang wajib ada
            $basePayload = [
                'token'        => (string)$token,
                'user_id'      => (int)($data['user_id'] ?? auth()->id() ?? 0),  
                'no_gadai'     => (string)($data['no_gadai'] ?? ''), 
                'nama_nasabah' => (string)($data['nama_nasabah'] ?? 'Tanpa Nama'),
            ];

            // ✅ Merge dengan data spesifik
            $payload = array_merge($basePayload, $data);

            // ✅ ROUTING BERDASARKAN TYPE
            $path = $this->getEndpointPath($data);
            $endpoint = $this->notificationServiceUrl . $path;

            Log::info('Sending Request to NestJS', [
                'url' => $endpoint, 
                'type' => $data['type'] ?? 'UNKNOWN',
                'payload' => $payload
            ]);

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ])
                ->post($endpoint, $payload);

            if ($response->successful()) {
                Log::info('✅ NestJS Response: SUCCESS', [
                    'endpoint' => $endpoint,
                    'body' => $response->json()
                ]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('❌ NestJS Response: FAILED', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return ['success' => false, 'message' => $response->body()];

        } catch (\Exception $e) {
            Log::emergency('🚨 Notification Service EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ✅ Menentukan endpoint berdasarkan tipe notifikasi
     */
    private function getEndpointPath(array $data): string
    {
        $type = $data['type'] ?? null;

        // ✅ Mapping type ke endpoint NestJS
        switch ($type) {
            case 'NEW_PAWN':
                return '/notifications/pawn-apps/new-pawn-application';
            
            case 'REPEAT_ORDER':
                return '/notifications/pawn-apps/repeat-pawn-application';
            
            case 'UNIT_VALIDATED':
                // ✅ Endpoint untuk validasi selesai (status jadi "selesai")
                return '/notifications/pawn-apps/new-pawn-application-status-after-check';
            
            case 'PAYMENT_SUCCESS':
                // ✅ Endpoint untuk pelunasan (status jadi "lunas")
                return '/notifications/pawn-apps/new-pawn-application-status-after-repayment';
            
            default:
                Log::warning('⚠️ Unknown notification type, using default endpoint', [
                    'type' => $type
                ]);
                return '/notifications/pawn-apps/new-pawn-application';
        }
    }

    public function notifyUser($userId, $title, $message, $type = 'info', $data = [])
    {
        return $this->sendNotification(array_merge([
            'user_id' => $userId,
            'title'   => $title,
            'message' => $message,
            'type'    => $type
        ], $data));
    }

    public function getUserNotifications($userId, $limit = 50)
    {
        try {
            $token = $this->generateToken();
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->get("{$this->notificationServiceUrl}/api/notifications/user/{$userId}", ['limit' => $limit]);
            return ['success' => $response->successful(), 'data' => $response->json()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function markAsRead($notificationId)
    {
        try {
            $token = $this->generateToken();
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->patch("{$this->notificationServiceUrl}/api/notifications/{$notificationId}/read");
            return ['success' => $response->successful()];
        } catch (\Exception $e) {
            return ['success' => false];
        }
    }
}