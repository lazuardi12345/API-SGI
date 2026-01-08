<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageController extends Controller
{

    public function get(string $path)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('minio');

        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);

        Log::info('StorageController: Accessing file', [
            'path' => $path,
            'exists' => $disk->exists($path)
        ]);

        if (!$disk->exists($path)) {
            Log::error('StorageController: File not found', ['path' => $path]);
            return response()->json([
                'message' => 'File not found',
                'path' => $path
            ], 404);
        }

        $stream = $disk->readStream($path);
        if (!$stream) {
            Log::error('StorageController: Cannot read stream', ['path' => $path]);
            return response()->json(['message' => 'Cannot read file'], 500);
        }
        try {
            $mime = $disk->mimeType($path);
        } catch (\Exception $e) {
            Log::warning('StorageController: Cannot detect mime type, using fallback', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeTypes = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'svg'  => 'image/svg+xml',
                'pdf'  => 'application/pdf',
                'txt'  => 'text/plain',
                'json' => 'application/json',
                'xml'  => 'application/xml',
                'zip'  => 'application/zip',
                'mp4'  => 'video/mp4',
                'mp3'  => 'audio/mpeg',
            ];
            $mime = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        Log::info('StorageController: Streaming file', [
            'path' => $path,
            'mime' => $mime
        ]);

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}