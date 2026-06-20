<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LeadWebhookController;
use App\Http\Middleware\VerifyTelegramSignature;
use App\Http\Middleware\VerifyWhatsappSignature;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhook/telegram', [LeadWebhookController::class, 'telegram'])
    ->middleware(['throttle:webhook', VerifyTelegramSignature::class]);

Route::get('/webhook/whatsapp', [LeadWebhookController::class, 'whatsapp']);
Route::post('/webhook/whatsapp', [LeadWebhookController::class, 'whatsapp'])
    ->middleware(['throttle:webhook', VerifyWhatsappSignature::class]);

use App\Http\Controllers\Api\DashboardController;

Route::prefix('/dashboard')->middleware(\App\Http\Middleware\VerifyAdminPasskey::class)->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/deployments', [DashboardController::class, 'deployments']);
    Route::get('/templates', [DashboardController::class, 'templates']);
    Route::post('/templates', [DashboardController::class, 'storeTemplate']);
    Route::post('/templates/{id}/toggle', [DashboardController::class, 'toggleTemplate']);
    Route::post('/deployments/{id}/teardown', [DashboardController::class, 'teardown']);
    Route::post('/deployments/{id}/extend', [DashboardController::class, 'extend']);
    Route::post('/deployments/{id}/retry', [DashboardController::class, 'retry']);
    Route::post('/deployments/{id}/approve', [DashboardController::class, 'approve']);
    Route::get('/logs', [DashboardController::class, 'logs']);
    Route::post('/sandbox/test', [DashboardController::class, 'sandboxTest']);
    Route::get('/sandbox/status/{lead_reference}', [DashboardController::class, 'sandboxStatus']);
    Route::get('/agent/config', [DashboardController::class, 'getAgentConfig']);
    Route::post('/agent/chat', [DashboardController::class, 'agentChat']);
    Route::post('/agent/deploy', [DashboardController::class, 'agentDeploy']);
    Route::post('/templates/upload-zip', [DashboardController::class, 'uploadZip']);
    Route::post('/templates/extract-zip', [DashboardController::class, 'extractZip']);
    Route::get('/templates/zips', [DashboardController::class, 'listZips']);
});

