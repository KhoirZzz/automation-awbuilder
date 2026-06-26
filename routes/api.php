<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LeadWebhookController;
use App\Http\Middleware\VerifyTelegramSignature;
use App\Http\Middleware\VerifyWhatsappSignature;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

use App\Http\Middleware\LogWebhookRequest;

Route::post('/webhook/telegram', [LeadWebhookController::class, 'telegram'])
    ->middleware([LogWebhookRequest::class, 'throttle:webhook', VerifyTelegramSignature::class]);

Route::get('/webhook/whatsapp', [LeadWebhookController::class, 'whatsapp']);
Route::post('/webhook/whatsapp', [LeadWebhookController::class, 'whatsapp'])
    ->middleware([LogWebhookRequest::class, 'throttle:webhook', VerifyWhatsappSignature::class]);

use App\Http\Controllers\Api\DashboardController;

use App\Http\Controllers\Api\ClientApiController;

Route::get('/public/templates', [DashboardController::class, 'publicTemplates']);
Route::post('/public/deploy', [DashboardController::class, 'publicDeploy']);
Route::get('/client/deployment/status', [ClientApiController::class, 'status']);

Route::prefix('/dashboard')->middleware(\App\Http\Middleware\VerifyAdminPasskey::class)->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::post('/optimize', [DashboardController::class, 'optimize']);
    Route::get('/deployments', [DashboardController::class, 'deployments']);
    Route::get('/templates', [DashboardController::class, 'templates']);
    Route::post('/templates', [DashboardController::class, 'storeTemplate']);
    Route::post('/templates/{id}/toggle', [DashboardController::class, 'toggleTemplate']);
    Route::delete('/templates/{id}', [DashboardController::class, 'destroyTemplate']);
    Route::put('/templates/{id}/price', [DashboardController::class, 'updatePrice']);
    Route::post('/deployments/{id}/teardown', [DashboardController::class, 'teardown']);
    Route::post('/deployments/{id}/extend', [DashboardController::class, 'extend']);
    Route::post('/deployments/{id}/retry', [DashboardController::class, 'retry']);
    Route::post('/deployments/{id}/approve', [DashboardController::class, 'approve']);
    Route::get('/logs', [DashboardController::class, 'logs']);
    Route::post('/sandbox/test', [DashboardController::class, 'sandboxTest']);
    Route::post('/sandbox/manual-deploy', [DashboardController::class, 'sandboxManualDeploy']);
    Route::get('/sandbox/status/{lead_reference}', [DashboardController::class, 'sandboxStatus']);
    Route::get('/sandbox/shopee-spam-deployments', [DashboardController::class, 'shopeeSpamDeployments']);
    Route::get('/agent/config', [DashboardController::class, 'getAgentConfig']);
    Route::post('/agent/chat', [DashboardController::class, 'agentChat']);
    Route::post('/agent/deploy', [DashboardController::class, 'agentDeploy']);
    Route::post('/agent/persist-history', [DashboardController::class, 'persistAgentChat']);
    Route::post('/templates/upload-zip', [DashboardController::class, 'uploadZip']);
    Route::post('/templates/extract-zip', [DashboardController::class, 'extractZip']);
    Route::get('/templates/zips', [DashboardController::class, 'listZips']);
    Route::delete('/templates/zips/{filename}', [DashboardController::class, 'destroyZip']);
    Route::get('/templates/files', [DashboardController::class, 'listFiles']);
    Route::get('/templates/file/content', [DashboardController::class, 'getFileContent']);
    Route::post('/templates/file', [DashboardController::class, 'createFileOrFolder']);
    Route::put('/templates/file', [DashboardController::class, 'updateFileContent']);
    Route::delete('/templates/file', [DashboardController::class, 'deleteFileOrFolder']);

    // Deployed Instances File Manager
    Route::get('/deployments/files', [DashboardController::class, 'listDeploymentFiles']);
    Route::get('/deployments/file/content', [DashboardController::class, 'getDeploymentFileContent']);
    Route::post('/deployments/file', [DashboardController::class, 'createDeploymentFileOrFolder']);
    Route::post('/deployments/file/rename', [DashboardController::class, 'renameDeploymentFileOrFolder']);
    Route::put('/deployments/file', [DashboardController::class, 'updateDeploymentFileContent']);
    Route::delete('/deployments/file', [DashboardController::class, 'deleteDeploymentFileOrFolder']);

    Route::delete('/deployments/{id}', [DashboardController::class, 'destroyDeployment']);
});

