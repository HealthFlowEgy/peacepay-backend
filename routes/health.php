<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
|
| These routes are used for monitoring and health checks by load balancers,
| container orchestrators, and monitoring systems.
|
*/

Route::get('/api/health', function () {
    $status = 'healthy';
    $checks = [];

    // Check database connection
    try {
        DB::connection()->getPdo();
        $checks['database'] = [
            'status' => 'ok',
            'connection' => config('database.default'),
        ];
    } catch (\Exception $e) {
        $status = 'unhealthy';
        $checks['database'] = [
            'status' => 'error',
            'message' => 'Database connection failed',
        ];
    }

    // Check Redis connection
    try {
        Redis::ping();
        $checks['redis'] = [
            'status' => 'ok',
        ];
    } catch (\Exception $e) {
        $status = 'degraded';
        $checks['redis'] = [
            'status' => 'error',
            'message' => 'Redis connection failed',
        ];
    }

    // Check cache
    try {
        Cache::put('health_check', true, 10);
        $cacheWorks = Cache::get('health_check') === true;
        $checks['cache'] = [
            'status' => $cacheWorks ? 'ok' : 'error',
        ];
    } catch (\Exception $e) {
        $checks['cache'] = [
            'status' => 'error',
            'message' => 'Cache check failed',
        ];
    }

    // Check storage
    $storagePath = storage_path('logs');
    $checks['storage'] = [
        'status' => is_writable($storagePath) ? 'ok' : 'error',
    ];

    $httpStatus = $status === 'healthy' ? 200 : ($status === 'degraded' ? 200 : 503);

    return response()->json([
        'status' => $status,
        'timestamp' => now()->toIso8601String(),
        'version' => config('app.version', '2.0.0'),
        'environment' => config('app.env'),
        'checks' => $checks,
    ], $httpStatus);
});

Route::get('/api/ready', function () {
    // Simple readiness check
    try {
        DB::connection()->getPdo();
        return response()->json(['ready' => true], 200);
    } catch (\Exception $e) {
        return response()->json(['ready' => false], 503);
    }
});

Route::get('/api/live', function () {
    // Simple liveness check
    return response()->json(['alive' => true], 200);
});
