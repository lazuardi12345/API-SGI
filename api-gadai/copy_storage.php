<?php
/**
 * Script ini menyalin isi storage Laravel (storage/app/public)
 * ke public/storage jika symlink tidak bisa dibuat.
 *
 * ⚠️ Hanya gunakan jika `php artisan storage:link` tidak bisa digunakan
 */

function copyFolder($src, $dst)
{
    if (!is_dir($src)) {
        echo "❌ Source folder does not exist: $src\n";
        return;
    }

    if (!is_dir($dst)) {
        echo "📁 Destination folder not found, creating: $dst\n";
        if (!mkdir($dst, 0775, true) && !is_dir($dst)) {
            echo "❌ Failed to create destination folder: $dst\n";
            return;
        }
    }

    $dir = opendir($src);
    if (!$dir) {
        echo "❌ Cannot open source folder: $src\n";
        return;
    }

    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') continue;

        $srcPath = rtrim($src, '/') . '/' . $file;
        $dstPath = rtrim($dst, '/') . '/' . $file;

        if (is_dir($srcPath)) {
            copyFolder($srcPath, $dstPath);
        } else {
            // Hanya salin jika file belum ada atau lebih baru
            if (!file_exists($dstPath) || filemtime($srcPath) > filemtime($dstPath)) {
                if (copy($srcPath, $dstPath)) {
                    echo "[" . date('Y-m-d H:i:s') . "] ✅ Copied: $dstPath\n";
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed to copy: $dstPath\n";
                }
            }
        }
    }

    closedir($dir);
}

// Root Laravel
$root = __DIR__;

// Path sumber storage/app/public
$source = $root . '/storage/app/public/';

// Tujuan harus ke public/storage agar URL: /storage/... bisa diakses
$destination = $root . '/public/storage/';

// Pastikan public/storage ada
if (!is_dir($destination)) {
    mkdir($destination, 0775, true);
}

// Cek apakah sudah ada symlink
$symlink = $root . '/public/storage';
if (is_link($symlink)) {
    echo "🔗 Symlink public/storage sudah ada — copy manual tidak diperlukan.\n";
} else {
    echo "🚀 Menyalin file dari: $source\n";
    echo "➡️  Ke: $destination\n";
    copyFolder($source, $destination);
    echo "✅ Storage copied successfully at " . date('Y-m-d H:i:s') . "\n";
}
