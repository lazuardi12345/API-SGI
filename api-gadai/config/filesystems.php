<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Disk default yang digunakan aplikasi. Bisa diubah via .env
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Konfigurasi beberapa disk, bisa pakai local, s3, atau cloud lain.
    |
    */

   'disks' => [

    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
        'throw' => false,
    ],

    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL') . '/storage',
        'visibility' => 'public',
        'throw' => false,
    ],

    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'throw' => false,
    ],


   'minio' => [
    'driver' => 's3',
    'key' => env('MINIO_KEY'),
    'secret' => env('MINIO_SECRET'),
    'region' => env('MINIO_REGION', 'us-east-1'),
    'bucket' => env('MINIO_BUCKET'),
    'endpoint' => env('MINIO_ENDPOINT'),
    'use_path_style_endpoint' => env('MINIO_USE_PATH_STYLE', true),
    'url' => env('APP_URL'), 
    'throw' => false,
],


],


    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Link untuk akses file public via storage
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
