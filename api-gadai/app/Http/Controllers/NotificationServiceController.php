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
            'service'         => 'laravel-pawn-apps',
            'applicationType' => 'pawn-apps',
            'sub'             => $user ? (string)$user->id : "0",
            'role'            => $user ? strtolower($user->role) : 'petugas',
            'iat'             => time(),
            'exp'             => time() + 3600, 
        ];

        $token = JWT::encode($payload, $this->jwtSecret, 'HS256');

        Log::info('🔑 JWT Token Generated', [
            'payload' => $payload,
            'token_preview' => substr($token, 0, 50) . '...',
            'secret_length' => strlen($this->jwtSecret)
        ]);

        return $token;
    }

    public function sendNotification(array $data)
    {
        try {
            $token = $this->generateToken();

            $payload = [
                'token'           => (string)$token,
                'application'     => 'pawn-apps',
                'applicationType' => 'pawn-apps',
                'type'            => (string)($data['type'] ?? 'GENERAL'),
                'notificationType' => (string)($data['type'] ?? 'GENERAL'),
                'user_id'         => (int)($data['user_id'] ?? auth()->id() ?? 0),
                'userId'          => (int)($data['user_id'] ?? auth()->id() ?? 0),
                'no_gadai'        => (string)($data['no_gadai'] ?? $data['noGadai'] ?? ''),
                'noGadai'         => (string)($data['no_gadai'] ?? $data['noGadai'] ?? ''),
                'nama_nasabah'    => (string)($data['nama_nasabah'] ?? $data['nasabah'] ?? 'Tanpa Nama'),
                'nasabah'         => (string)($data['nama_nasabah'] ?? $data['nasabah'] ?? 'Tanpa Nama'),
                'title'           => (string)($data['title'] ?? 'Notifikasi Baru'),
                'message'         => (string)($data['message'] ?? ''),
                'body'            => (string)($data['message'] ?? ''),
                'url'             => (string)($data['url'] ?? ''),
                'status_transaksi' => (string)($data['status_transaksi'] ?? ''),
                'nominal_cair'    => isset($data['nominal_cair']) ? (int)$data['nominal_cair'] : null,
                'nominal_masuk'   => isset($data['nominal_masuk']) ? (int)$data['nominal_masuk'] : null,
                'total_gadai'     => isset($data['total_gadai']) ? (int)$data['total_gadai'] : null,
                'is_repeat'       => isset($data['is_repeat']) ? (bool)$data['is_repeat'] : false,
                'metadata' => [
                    'sent_at' => now()->toIso8601String(),
                    'source' => 'laravel-pawn-apps',
                    'additional_message' => $data['message'] ?? ''
                ],
                'timestamp'       => now()->timestamp,
                'created_at'      => now()->toIso8601String(),
            ];

            $payload = array_filter($payload, function($value) {
                return $value !== null;
            });

            $path = $this->getEndpointPath($data);
            $endpoint = $this->notificationServiceUrl . $path;

            Log::info('🚀 SENDING TO NESTJS', [
                'url'     => $endpoint,
                'type'    => $payload['type'],
                'user_id' => $payload['user_id'],
                'no_gadai' => $payload['no_gadai'],
            ]);

            $maxRetries = 2;
            $attempt = 0;
            $lastError = null;

            while ($attempt < $maxRetries) {
                $attempt++;
                
                Log::info("📡 Attempt #{$attempt} to NestJS");

                try {
                    $response = Http::timeout(20)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $token,
                            'Accept'        => 'application/json',
                            'Content-Type'  => 'application/json',
                        ])
                        ->post($endpoint, $payload);

                    Log::info('📥 NestJS Response Received', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'reason' => $response->reason(),
                        'headers' => $response->headers(),
                        'body_preview' => substr($response->body(), 0, 500),
                    ]);

                    if ($response->successful()) {
                        Log::info('✅ NestJS Success', [
                            'status' => $response->status(),
                            'body' => $response->json()
                        ]);
                        return ['success' => true, 'data' => $response->json()];
                    }

                    Log::error('❌ NestJS Failed Response', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'reason' => $response->reason(),
                        'body' => $response->body(),
                        'headers' => $response->headers(),
                    ]);
                    if ($response->status() >= 400 && $response->status() < 500) {
                        Log::error('🚫 Client Error (4xx) - Not retrying', [
                            'status' => $response->status(),
                            'error' => $response->body()
                        ]);
                        break;
                    }

                    $lastError = [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ];

                    if ($attempt < $maxRetries) {
                        sleep(1); 
                    }

                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    Log::error('🔌 Connection Error to NestJS', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'url' => $endpoint
                    ]);
                    
                    $lastError = [
                        'type' => 'connection_error',
                        'message' => $e->getMessage()
                    ];

                    if ($attempt < $maxRetries) {
                        sleep(2); 
                    }
                } catch (\Exception $e) {
                    Log::error('💥 Unexpected Error', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    $lastError = [
                        'type' => 'unexpected_error',
                        'message' => $e->getMessage()
                    ];
                    break; 
                }
            }

            // All retries failed
            Log::error('❌ ALL RETRIES FAILED', [
                'total_attempts' => $attempt,
                'last_error' => $lastError,
                'endpoint' => $endpoint,
                'payload_type' => $payload['type'],
            ]);

            return [
                'success' => false, 
                'message' => 'Failed after ' . $attempt . ' attempts',
                'last_error' => $lastError
            ];

        } catch (\Exception $e) {
            Log::error('🚨 CRITICAL ERROR in sendNotification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            
            return [
                'success' => false, 
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    private function getEndpointPath(array $data): string
    {
        $type = $data['type'] ?? null;

        switch ($type) {
            case 'NEW_PAWN':
                return '/notifications/pawn-apps/new-pawn-application';
            case 'REPEAT_ORDER':
                return '/notifications/pawn-apps/repeat-pawn-application';
            case 'UNIT_VALIDATED':
                return '/notifications/pawn-apps/new-pawn-application-status-after-check';
            case 'PAYMENT_SUCCESS':
                return '/notifications/pawn-apps/new-pawn-application-status-after-repayment';
            case 'ITEM_AUCTIONED':
                return '/notifications/pawn-apps/auction-alert';
            default:
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
            Log::error('Error getting user notifications', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
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
            Log::error('Error marking notification as read', [
                'error' => $e->getMessage(),
                'notification_id' => $notificationId
            ]);
            return ['success' => false];
        }
    }

    /**
     * ✅ TEST ENDPOINT - Manual trigger untuk debug
     */
    public function testConnection()
    {
        Log::info('🧪 Testing NestJS Connection');

        try {
            $token = $this->generateToken();
            $testPayload = [
                'type' => 'TEST',
                'message' => 'Test connection from Laravel',
                'timestamp' => now()->toIso8601String(),
            ];

            Log::info('🧪 Test Payload', $testPayload);

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->notificationServiceUrl . '/notifications/pawn-apps/test-broadcast', $testPayload);

            Log::info('🧪 Test Response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->json(),
            ];

        } catch (\Exception $e) {
            Log::error('🧪 Test Failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}