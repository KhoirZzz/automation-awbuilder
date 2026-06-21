<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\ServiceTemplate;
use App\Models\AgentChat;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceDuration;
use App\Jobs\ProcessLeadJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get system stats.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total_active' => Deployment::where('status', DeploymentStatus::ACTIVE)->count(),
            'total_pending' => Deployment::where('status', DeploymentStatus::PENDING)->count(),
            'total_expired' => Deployment::where('status', DeploymentStatus::EXPIRED)->count(),
            'total_failed' => Deployment::where('status', DeploymentStatus::FAILED)->count(),
            'total_templates' => ServiceTemplate::count(),
            'total_revenue' => Deployment::whereIn('status', [DeploymentStatus::ACTIVE, DeploymentStatus::EXPIRED])->sum('price'),
        ]);
    }

    /**
     * Get list of all deployments with service template details.
     */
    public function deployments(): JsonResponse
    {
        $deployments = Deployment::with('serviceTemplate')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($deployments);
    }

    /**
     * Get list of templates.
     */
    public function templates(): JsonResponse
    {
        return response()->json(ServiceTemplate::all());
    }

    /**
     * Toggle a service template is_active status.
     */
    public function toggleTemplate($id): JsonResponse
    {
        $template = ServiceTemplate::findOrFail($id);
        $template->update(['is_active' => !$template->is_active]);

        return response()->json([
            'success' => true,
            'template' => $template
        ]);
    }

    /**
     * Delete a service template and its files.
     */
    public function destroyTemplate($id): JsonResponse
    {
        $template = ServiceTemplate::findOrFail($id);

        if ($template->deployments()->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Template tidak dapat dihapus karena memiliki riwayat/aktif deployment.'
            ], 400);
        }

        $templateBasePath = config('deploy.template_base_path');
        if (!empty($template->template_path)) {
            $dirToDelete = $templateBasePath . '/' . $template->template_path;
            // Security check: must be inside templateBasePath, cannot be empty or root templateBasePath
            if ($template->template_path !== '.' && $template->template_path !== '/' && File::isDirectory($dirToDelete)) {
                $realDir = realpath($dirToDelete);
                $realBase = realpath($templateBasePath);
                if ($realDir && $realBase && str_starts_with($realDir, $realBase) && $realDir !== $realBase) {
                    File::deleteDirectory($dirToDelete);
                }
            }
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil dihapus.'
        ]);
    }

    /**
     * Create a new template.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:service_templates,key',
            'name' => 'required|string',
            'category' => 'nullable|string',
            'template_path' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $template = ServiceTemplate::create($validated);

        return response()->json([
            'success' => true,
            'template' => $template
        ], 201);
    }

    /**
     * Trigger a teardown for an active deployment.
     */
    public function teardown($id): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);

        if ($deployment->status !== DeploymentStatus::ACTIVE) {
            return response()->json(['error' => 'Only active deployments can be torn down.'], 400);
        }

        $instancePath = $deployment->instance_path;

        try {
            if (File::isDirectory($instancePath)) {
                $teardownScript = $instancePath . '/teardown.sh';
                if (File::exists($teardownScript)) {
                    $processResult = Process::path($instancePath)
                        ->timeout(60)
                        ->run(['bash', 'teardown.sh', $deployment->client_slug]);

                    if (!$processResult->successful()) {
                        return response()->json([
                            'error' => 'teardown.sh script failed.',
                            'stdout' => $processResult->output(),
                            'stderr' => $processResult->errorOutput()
                        ], 500);
                    }
                }

                // Move to archive folder
                $archiveBase = config('deploy.archive_path');
                if (!File::isDirectory($archiveBase)) {
                    File::makeDirectory($archiveBase, 0755, true);
                }
                $archivePath = $archiveBase . '/' . $deployment->client_slug . '_' . now()->format('Ymd_His');
                File::moveDirectory($instancePath, $archivePath);
            }

            $deployment->update(['status' => DeploymentStatus::EXPIRED]);

            return response()->json(['success' => true, 'message' => 'Deployment torn down and archived.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Manually extend active deployment duration.
     */
    public function extend(Request $request, $id): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);

        if ($deployment->status !== DeploymentStatus::ACTIVE) {
            return response()->json(['error' => 'Only active deployments can be extended.'], 400);
        }

        $validated = $request->validate([
            'duration' => 'required|string'
        ]);

        $durationEnum = ServiceDuration::tryFrom($validated['duration']);
        if (!$durationEnum) {
            return response()->json(['error' => 'Invalid duration value.'], 400);
        }

        // Calculate new expiry date from current expires_at
        $currentExpiry = Carbon::parse($deployment->expires_at);
        $newExpiry = match ($durationEnum) {
            ServiceDuration::ONE_WEEK => $currentExpiry->addWeek(),
            ServiceDuration::ONE_MONTH => $currentExpiry->addMonth(),
            ServiceDuration::THREE_MONTHS => $currentExpiry->addMonths(3),
            ServiceDuration::SIX_MONTHS => $currentExpiry->addMonths(6),
            ServiceDuration::ONE_YEAR => $currentExpiry->addYear(),
        };

        $deployment->update(['expires_at' => $newExpiry]);

        return response()->json([
            'success' => true,
            'message' => 'Deployment extended successfully.',
            'new_expires_at' => $newExpiry
        ]);
    }

    /**
     * Retry a failed deployment by re-dispatching ProcessLeadJob.
     */
    public function retry($id): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);

        if ($deployment->status !== DeploymentStatus::FAILED) {
            return response()->json(['error' => 'Only failed deployments can be retried.'], 400);
        }

        $rawResponse = json_decode($deployment->raw_llm_response, true);
        if (empty($rawResponse)) {
            return response()->json(['error' => 'No raw LLM response available to retry.'], 400);
        }

        // Simulated text that triggers reconstruction of this lead
        $simulatedMessage = "Saya ingin mendeploy ulang " . $deployment->serviceTemplate->key . " untuk " . $deployment->client_slug;
        
        // Delete the failed deployment first so the validator doesn't block it as duplicate
        $deployment->delete();

        ProcessLeadJob::dispatch($simulatedMessage, $deployment->source, $deployment->lead_reference);

        return response()->json(['success' => true, 'message' => 'Retry job dispatched.']);
    }

    /**
     * Approve payment for a deployment pending payment.
     */
    public function approve($id, \App\Services\TelegramBotService $botService): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);

        if ($deployment->status !== DeploymentStatus::PENDING_PAYMENT) {
            return response()->json(['error' => 'Only deployments pending payment can be approved.'], 400);
        }

        $deployment->update(['status' => DeploymentStatus::ACTIVE]);

        // Construct final URL
        $baseDomain = 'mockbuild.shop';
        $host = request()->getHost();
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
            $baseDomain = $host;
        }
        $clientUrl = "http://{$deployment->client_slug}.{$baseDomain}";

        // Notify user if Telegram ID is in lead_reference
        if (str_starts_with($deployment->lead_reference, 'tg_')) {
            $parts = explode('_', $deployment->lead_reference);
            $clientChatId = $parts[1] ?? null;

            if ($clientChatId) {
                $botService->sendMessage($clientChatId, "✅ <b>PEMBAYARAN DITERIMA!</b>\n\nTerima kasih, pembayaran Anda telah diverifikasi oleh Admin.\nAplikasi Anda telah aktif sepenuhnya.\n\n<b>Link Tautan:</b> <a href=\"{$clientUrl}\">{$clientUrl}</a>");
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Deployment approved and client notified.',
            'url' => $clientUrl
        ]);
    }

    /**
     * Read the latest deploy-audit logs.
     */
    public function logs(): JsonResponse
    {
        $logPath = storage_path('logs/deploy-audit.log');
        
        if (!File::exists($logPath)) {
            // Check daily log format: deploy-audit-YYYY-MM-DD.log
            $logPath = storage_path('logs/deploy-audit-' . now()->format('Y-m-d') . '.log');
        }

        if (!File::exists($logPath)) {
            return response()->json(['logs' => 'No deployment audit logs found yet.']);
        }

        // Read last 150 lines
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        $startLine = max(0, $totalLines - 150);
        $lines = [];
        
        $file->seek($startLine);
        while (!$file->eof()) {
            $lines[] = $file->current();
            $file->next();
        }

        return response()->json([
            'logs' => implode('', array_filter($lines))
        ]);
    }

    /**
     * Run a sandbox simulated webhook test.
     */
    public function sandboxTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'source' => 'required|string|in:telegram,whatsapp'
        ]);

        $leadRef = 'sandbox_' . time() . '_' . rand(100, 999);

        ProcessLeadJob::dispatch($validated['message'], $validated['source'], $leadRef);

        return response()->json([
            'success' => true,
            'message' => 'Simulated webhook dispatched successfully.',
            'lead_reference' => $leadRef
        ]);
    }

    /**
     * Run a manual deployment simulation on the sandbox.
     */
    public function sandboxManualDeploy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_key' => 'required|string',
            'durasi' => 'required|string',
            'client_slug_request' => 'required|string',
            'telegram_token' => 'required|string',
            'telegram_chat_id' => 'required|string',
            'price' => 'nullable|numeric',
        ]);

        $leadRef = 'sandbox_manual_' . time() . '_' . rand(100, 999);

        // Pre-initialize cache for polling feedback
        \Illuminate\Support\Facades\Cache::put("sandbox_status_{$leadRef}", [
            'stage' => 'webhook',
            'status' => 'success',
            'message' => 'Manual Deploy request received.'
        ], 600);

        \App\Jobs\ProcessManualDeployJob::dispatch($validated, $leadRef);

        return response()->json([
            'success' => true,
            'message' => 'Manual deployment job queued.',
            'lead_reference' => $leadRef
        ]);
    }

    public function sandboxStatus($leadReference): JsonResponse
    {
        $deployment = Deployment::where('lead_reference', $leadReference)->first();
        $cachedStatus = \Illuminate\Support\Facades\Cache::get("sandbox_status_{$leadReference}");

        if ($deployment) {
            if ($deployment->status === DeploymentStatus::ACTIVE) {
                return response()->json([
                    'stage' => 'completed',
                    'status' => 'active',
                    'message' => 'Deployment active and running successfully.',
                    'deployment' => $deployment
                ]);
            }

            if ($deployment->status === DeploymentStatus::FAILED) {
                if ($cachedStatus && $cachedStatus['status'] === 'failed') {
                    return response()->json(array_merge($cachedStatus, ['deployment' => $deployment]));
                }
                return response()->json([
                    'stage' => 'completed',
                    'status' => 'failed',
                    'message' => 'Deployment script execution failed and folder has been rolled back.',
                    'deployment' => $deployment
                ]);
            }

            if ($deployment->status === DeploymentStatus::PENDING) {
                if ($cachedStatus) {
                    return response()->json(array_merge($cachedStatus, ['deployment' => $deployment]));
                }
                return response()->json([
                    'stage' => 'deploying',
                    'status' => 'pending',
                    'message' => 'Replicating template filesystem and executing deploy.sh...',
                    'deployment' => $deployment
                ]);
            }
        }

        if ($cachedStatus) {
            return response()->json($cachedStatus);
        }

        return response()->json([
            'stage' => 'llm_analysis',
            'status' => 'pending',
            'message' => 'Hermes is analyzing the lead chat text...'
        ]);
    }

    /**
     * Get LLM Agent / Worker configuration metadata.
     */
    public function getAgentConfig(\App\Services\HermesService $hermesService): JsonResponse
    {
        $apiUrl = env('HERMES_API_URL', 'http://localhost:11434/v1/chat/completions');
        $apiKey = env('HERMES_API_KEY');
        $model = env('HERMES_MODEL', 'hermes3');

        $activeServiceKeys = ServiceTemplate::where('is_active', true)->pluck('key')->toArray();
        $durationKeys = collect(\App\Enums\ServiceDuration::cases())->map(fn ($case) => $case->value)->toArray();
        $activeSubdomains = Deployment::where('status', DeploymentStatus::ACTIVE)->pluck('client_slug')->toArray();

        $defaultSystemPrompt = $hermesService->buildAgentPlaygroundSystemPrompt($activeServiceKeys, $durationKeys, $activeSubdomains);

        $chatHistory = AgentChat::orderBy('id', 'asc')
            ->get()
            ->map(function ($chat) {
                return [
                    'role' => $chat->role,
                    'content' => $chat->content,
                    'isError' => $chat->is_error,
                    'isDeploying' => $chat->is_deploying,
                    'url' => $chat->url,
                ];
            })
            ->toArray();

        return response()->json([
            'model' => $model,
            'api_url' => $apiUrl,
            'has_api_key' => !empty($apiKey),
            'default_system_prompt' => $defaultSystemPrompt,
            'chat_history' => $chatHistory,
        ]);
    }

    /**
     * Send a custom chat to the LLM Agent / Worker.
     */
    public function agentChat(Request $request, \App\Services\HermesService $hermesService): JsonResponse
    {
        $validated = $request->validate([
            'system_prompt' => 'nullable|string',
            'message' => 'nullable|string',
            'messages' => 'nullable|array',
            'passkey' => 'required|string',
        ]);

        $correctPasskey = config('deploy.agent_passkey', '852963');

        if ($validated['passkey'] !== $correctPasskey) {
            return response()->json([
                'success' => false,
                'error' => 'Akses ditolak. Passkey tidak valid.'
            ], 403);
        }

        // Always build the prompt dynamically based on the current database state
        $activeServiceKeys = ServiceTemplate::where('is_active', true)->pluck('key')->toArray();
        $durationKeys = collect(\App\Enums\ServiceDuration::cases())->map(fn ($case) => $case->value)->toArray();
        $activeSubdomains = Deployment::where('status', DeploymentStatus::ACTIVE)->pluck('client_slug')->toArray();
        $systemPrompt = $hermesService->buildAgentPlaygroundSystemPrompt($activeServiceKeys, $durationKeys, $activeSubdomains);

        // Inject the Master / Developer recognition prompt
        $injectedSystemPrompt = "CRITICAL IDENTITY RECOGNITION:\n" .
            "The user you are chatting with is your Developer and Master: 'Ridzz'.\n" .
            "Recognize him as your creator/developer/master. Greet him respectfully in Indonesian as 'Tuan Ridzz' or 'Ridzz'.\n\n" .
            $systemPrompt;

        $messagesInput = $validated['messages'] ?? $validated['message'];
        $chatMessages = [];
        if (is_array($messagesInput)) {
            $chatMessages = $messagesInput;
        } elseif (is_string($messagesInput)) {
            $chatMessages = [
                [
                    'role' => 'user',
                    'content' => $messagesInput
                ]
            ];
        }

        $maxIterations = 3;
        $iteration = 0;
        $finalResponse = '';

        try {
            while ($iteration < $maxIterations) {
                $response = $hermesService->chat(
                    $injectedSystemPrompt,
                    $chatMessages
                );

                // Clean the response text from potential markdown block formatting
                $cleaned = trim($response);
                if (str_starts_with($cleaned, '```json')) {
                    $cleaned = substr($cleaned, 7);
                } elseif (str_starts_with($cleaned, '```')) {
                    $cleaned = substr($cleaned, 3);
                }
                if (str_ends_with($cleaned, '```')) {
                    $cleaned = substr($cleaned, 0, -3);
                }
                $cleaned = trim($cleaned);

                $toolCall = json_decode($cleaned, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($toolCall) && isset($toolCall['status'])) {
                    if ($toolCall['status'] === 'read_file' || $toolCall['status'] === 'write_file') {
                        // It's a file tool call!
                        $toolOutput = $this->executeFileToolCall($toolCall);

                        // Add this assistant message and system response to the message array
                        $chatMessages[] = [
                            'role' => 'assistant',
                            'content' => $response
                        ];
                        $chatMessages[] = [
                            'role' => 'user',
                            'content' => "SYSTEM FILE TOOL RESPONSE:\n" . $toolOutput
                        ];

                        $iteration++;
                        continue;
                    }
                }

                // If it's not a file tool call, we are done
                $finalResponse = $response;
                break;
            }

            if ($iteration >= $maxIterations) {
                // If we exceeded loop limits without finishing, we force a final response.
                $chatMessages[] = [
                    'role' => 'assistant',
                    'content' => $response
                ];
                $chatMessages[] = [
                    'role' => 'user',
                    'content' => "SYSTEM: Mohon berikan tanggapan akhir kepada Tuan Ridzz dalam bahasa Indonesia yang ramah, tanpa output JSON lagi."
                ];
                $finalResponse = $hermesService->chat(
                    $injectedSystemPrompt,
                    $chatMessages
                );
            }

            return response()->json([
                'success' => true,
                'response' => $finalResponse
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to execute a file read or write operation requested by the AI Worker.
     */
    private function executeFileToolCall(array $toolCall): string
    {
        $clientSlug = $toolCall['client_slug'] ?? null;
        $filePath = $toolCall['file_path'] ?? null;
        $status = $toolCall['status'] ?? null;

        if (empty($clientSlug)) {
            return "ERROR: client_slug is required.";
        }

        // Validate client slug (alphanumeric and hyphens only)
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $clientSlug)) {
            return "ERROR: Invalid client_slug format.";
        }

        $deployment = Deployment::where('client_slug', $clientSlug)
            ->where('status', DeploymentStatus::ACTIVE)
            ->first();

        if (!$deployment) {
            return "ERROR: Active deployment with slug '{$clientSlug}' not found.";
        }

        $basePath = realpath($deployment->instance_path);
        if (!$basePath || !File::isDirectory($basePath)) {
            return "ERROR: Instance directory does not exist or is not valid.";
        }

        if (empty($filePath)) {
            return "ERROR: file_path is required.";
        }

        // Security check: reject paths with '..' or starting with '/'
        if (str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
            return "ERROR: Path traversal or absolute paths are not allowed.";
        }

        // Resolve target path
        $targetPath = $basePath . '/' . $filePath;

        // Auto-mapping: if file doesn't exist directly, check inside immediate subdirectories
        if (!File::exists($targetPath)) {
            $directories = File::directories($basePath);
            foreach ($directories as $dir) {
                $possiblePath = $dir . '/' . $filePath;
                if (File::exists($possiblePath)) {
                    $targetPath = $possiblePath;
                    break;
                }
            }
        }

        // Auto-mapping for writing a new file: find correct root subdirectory if targetPath still doesn't exist
        if (!File::exists($targetPath)) {
            $directories = File::directories($basePath);
            if (count($directories) === 1) {
                $targetPath = $directories[0] . '/' . $filePath;
            } elseif (count($directories) > 1) {
                foreach ($directories as $dir) {
                    if (File::exists($dir . '/index.html')) {
                        $targetPath = $dir . '/' . $filePath;
                        break;
                    }
                }
            }
        }

        // Standardize paths and verify targetPath is within basePath
        $realTargetPath = realpath($targetPath);
        if ($realTargetPath) {
            if (!str_starts_with($realTargetPath, $basePath)) {
                return "ERROR: Security violation. Access outside the sandbox is forbidden.";
            }
        } else {
            $tempPath = $targetPath;
            while (!empty($tempPath) && !File::exists($tempPath)) {
                $tempPath = dirname($tempPath);
            }
            $realParentPath = realpath($tempPath);
            if (!$realParentPath || !str_starts_with($realParentPath, $basePath)) {
                return "ERROR: Security violation. Access outside the sandbox is forbidden.";
            }
        }

        if ($status === 'read_file') {
            if (!File::exists($targetPath)) {
                return "ERROR: File does not exist: {$filePath}";
            }
            if (File::isDirectory($targetPath)) {
                return "ERROR: Path '{$filePath}' is a directory, not a file.";
            }
            if (File::size($targetPath) > 51200) {
                return "ERROR: File is too large to read (max 50KB).";
            }
            return File::get($targetPath);
        } elseif ($status === 'write_file') {
            $content = $toolCall['content'] ?? '';
            $targetBlock = $toolCall['target'] ?? null;

            $dirPath = dirname($targetPath);
            if (!File::isDirectory($dirPath)) {
                File::makeDirectory($dirPath, 0755, true);
            }

            if (empty($targetBlock)) {
                File::put($targetPath, $content);
                return "SUCCESS: File '{$filePath}' has been fully overwritten/created successfully.";
            } else {
                if (!File::exists($targetPath)) {
                    return "ERROR: Cannot replace target block because the file does not exist.";
                }
                $existingContent = File::get($targetPath);
                if (!str_contains($existingContent, $targetBlock)) {
                    return "ERROR: Target text block to replace was not found inside the file.";
                }
                $newContent = str_replace($targetBlock, $content, $existingContent);
                File::put($targetPath, $newContent);
                return "SUCCESS: Target block in file '{$filePath}' has been replaced successfully.";
            }
        }

        return "ERROR: Unknown file tool status.";
    }

    /**
     * Persist the entire agent chat history to database.
     */
    public function persistAgentChat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_history' => 'required|array',
            'passkey' => 'required|string',
        ]);

        $correctPasskey = config('deploy.agent_passkey', '852963');

        if ($validated['passkey'] !== $correctPasskey) {
            return response()->json([
                'success' => false,
                'error' => 'Akses ditolak. Passkey tidak valid.'
            ], 403);
        }

        // Wipe the old history
        AgentChat::truncate();

        // Insert new history items
        foreach ($validated['chat_history'] as $item) {
            AgentChat::create([
                'role' => $item['role'],
                'content' => $item['content'] ?? '',
                'is_error' => !empty($item['isError']),
                'is_deploying' => !empty($item['isDeploying']),
                'url' => $item['url'] ?? null,
            ]);
        }

        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Upload template ZIP file.
     */
    public function uploadZip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zip_file' => 'required|file|mimes:zip|max:51200', // 50MB
        ]);

        $file = $request->file('zip_file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $uploadDir = storage_path('app/uploads');

        if (!File::isDirectory($uploadDir)) {
            File::makeDirectory($uploadDir, 0755, true);
        }

        $file->move($uploadDir, $fileName);

        return response()->json([
            'success' => true,
            'filename' => $fileName,
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * List uploaded ZIP files.
     */
    public function listZips(): JsonResponse
    {
        $uploadDir = storage_path('app/uploads');
        $zips = [];

        if (File::isDirectory($uploadDir)) {
            $files = File::files($uploadDir);
            foreach ($files as $file) {
                if ($file->getExtension() === 'zip') {
                    $zips[] = [
                        'filename' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'uploaded_at' => Carbon::createFromTimestamp($file->getMTime())->toIso8601String()
                    ];
                }
            }
        }

        // Sort by uploaded_at descending
        usort($zips, fn($a, $b) => strcmp($b['uploaded_at'], $a['uploaded_at']));

        return response()->json($zips);
    }

    /**
     * Extract uploaded ZIP file and register template.
     */
    public function extractZip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string',
            'key' => 'required|string|unique:service_templates,key|regex:/^[a-z0-9-]+$/',
            'name' => 'required|string',
            'category' => 'nullable|string',
        ]);

        $filename = basename($validated['filename']); // Prevent directory traversal
        $filePath = storage_path('app/uploads/' . $filename);

        if (!File::exists($filePath)) {
            return response()->json(['error' => 'ZIP file not found.'], 404);
        }

        $templateBaseDir = config('deploy.template_base_path');
        $destinationFolder = $templateBaseDir . '/' . $validated['key'];

        if (File::isDirectory($destinationFolder)) {
            return response()->json(['error' => 'Destination template folder already exists.'], 400);
        }

        try {
            if (!File::isDirectory($templateBaseDir)) {
                File::makeDirectory($templateBaseDir, 0755, true);
            }

            // Extract using ZipArchive
            $zip = new \ZipArchive;
            if ($zip->open($filePath) === TRUE) {
                File::makeDirectory($destinationFolder, 0755, true);
                $zip->extractTo($destinationFolder);
                $zip->close();
            } else {
                return response()->json(['error' => 'Failed to open ZIP archive.'], 500);
            }

            // Optional: delete uploaded zip file after extraction
            File::delete($filePath);

            // Register template in DB
            $template = ServiceTemplate::create([
                'key' => $validated['key'],
                'name' => $validated['name'],
                'category' => $validated['category'] ?? null,
                'template_path' => $validated['key'],
                'is_active' => true
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Template extracted and registered successfully.',
                'template' => $template
            ]);
        } catch (\Exception $e) {
            // Cleanup on failure
            if (File::isDirectory($destinationFolder)) {
                File::deleteDirectory($destinationFolder);
            }
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Directly trigger deployment from the AI Worker chat interface.
     */
    public function agentDeploy(Request $request, \App\Actions\DeployServiceAction $deployAction): JsonResponse
    {
        $validated = $request->validate([
            'service_key' => 'required|string',
            'durasi' => 'required|string',
            'client_slug_request' => 'required|string',
            'telegram_token' => 'required|string',
            'telegram_chat_id' => 'required|string',
            'price' => 'nullable',
        ]);

        $serviceTemplate = ServiceTemplate::where('key', $validated['service_key'])->first();
        if (!$serviceTemplate || !$serviceTemplate->is_active) {
            return response()->json(['success' => false, 'error' => 'Template not found or inactive.'], 400);
        }

        // Validate slug compliance
        $clientSlug = strtolower(trim($validated['client_slug_request']));
        if (!preg_match('/^[a-z0-9](-?[a-z0-9])*$/', $clientSlug) || strlen($clientSlug) > 63 || strlen($clientSlug) < 2) {
            return response()->json(['success' => false, 'error' => 'Slug tidak sesuai format DNS.'], 400);
        }

        // Check reserved words
        $reserved = config('deploy.reserved_slugs', []);
        if (in_array($clientSlug, $reserved)) {
            return response()->json(['success' => false, 'error' => 'Slug merupakan kata terlarang sistem.'], 400);
        }

        // Check duplicates
        $duplicate = Deployment::where('client_slug', $clientSlug)
            ->whereIn('status', [DeploymentStatus::PENDING, DeploymentStatus::ACTIVE])
            ->first();
        if ($duplicate) {
            return response()->json(['success' => false, 'error' => 'Subdomain/Slug ini sudah aktif digunakan.'], 400);
        }

        // Parse price
        $price = null;
        if (!empty($validated['price'])) {
            $price = (int)$validated['price'];
        }

        // Map duration enum
        $durationEnum = \App\Enums\ServiceDuration::tryFrom($validated['durasi']);
        if (!$durationEnum) {
            return response()->json(['success' => false, 'error' => 'Durasi tidak valid.'], 400);
        }

        // Build DTO
        $result = new \App\DataTransferObjects\LeadAnalysisResult(
            serviceTemplateId: $serviceTemplate->id,
            duration: $durationEnum,
            clientSlug: $clientSlug,
            expiresAt: $durationEnum->calculateExpiry(),
            source: 'agent',
            leadReference: 'agent_' . time() . '_' . rand(100, 999),
            price: $price,
            rawLlmResponse: json_encode($validated)
        );

        try {
            // Execute deployment
            $deployment = $deployAction->execute($result);

            // Construct final URL
            $host = $request->getHost();
            $baseDomain = 'mockbuild.shop';
            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
                $baseDomain = $host;
            }
            $clientUrl = "http://{$clientSlug}.{$baseDomain}";

            return response()->json([
                'success' => true,
                'message' => 'Deployment successful!',
                'url' => $clientUrl,
                'deployment' => $deployment
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Deployment failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active templates for public landing page.
     */
    public function publicTemplates(): JsonResponse
    {
        $templates = ServiceTemplate::where('is_active', true)->get(['key', 'name', 'category']);
        return response()->json($templates);
    }

    /**
     * Handles public client checkout deployment request.
     */
    public function publicDeploy(Request $request, \App\Actions\DeployServiceAction $deployAction): JsonResponse
    {
        $validated = $request->validate([
            'service_key' => 'required|string',
            'durasi' => 'required|string',
            'client_slug_request' => 'required|string',
            'telegram_token' => 'required|string',
            'telegram_chat_id' => 'required|string',
        ]);

        $serviceTemplate = ServiceTemplate::where('key', $validated['service_key'])->first();
        if (!$serviceTemplate || !$serviceTemplate->is_active) {
            return response()->json(['success' => false, 'error' => 'Template tidak ditemukan atau tidak aktif.'], 400);
        }

        // Validate slug compliance
        $clientSlug = strtolower(trim($validated['client_slug_request']));
        if (!preg_match('/^[a-z0-9](-?[a-z0-9])*$/', $clientSlug) || strlen($clientSlug) > 63 || strlen($clientSlug) < 2) {
            return response()->json(['success' => false, 'error' => 'Subdomain tidak sesuai format DNS.'], 400);
        }

        // Check reserved words
        $reserved = config('deploy.reserved_slugs', []);
        if (in_array($clientSlug, $reserved)) {
            return response()->json(['success' => false, 'error' => 'Subdomain merupakan kata terlarang.'], 400);
        }

        // Check duplicates
        $duplicate = Deployment::where('client_slug', $clientSlug)
            ->whereIn('status', [DeploymentStatus::PENDING, DeploymentStatus::ACTIVE])
            ->first();
        if ($duplicate) {
            return response()->json(['success' => false, 'error' => 'Subdomain ini sudah aktif digunakan.'], 400);
        }

        // Map duration enum
        $durationEnum = \App\Enums\ServiceDuration::tryFrom($validated['durasi']);
        if (!$durationEnum) {
            return response()->json(['success' => false, 'error' => 'Durasi tidak valid.'], 400);
        }

        // Calculate standard price based on duration
        $price = match ($durationEnum) {
            \App\Enums\ServiceDuration::ONE_WEEK => 50000,
            \App\Enums\ServiceDuration::ONE_MONTH => 150000,
            \App\Enums\ServiceDuration::THREE_MONTHS => 400000,
            \App\Enums\ServiceDuration::SIX_MONTHS => 750000,
            \App\Enums\ServiceDuration::ONE_YEAR => 1200000,
        };

        // Build DTO
        $result = new \App\DataTransferObjects\LeadAnalysisResult(
            serviceTemplateId: $serviceTemplate->id,
            duration: $durationEnum,
            clientSlug: $clientSlug,
            expiresAt: $durationEnum->calculateExpiry(),
            source: 'telegram', // Set to 'telegram' to make status PENDING_PAYMENT
            leadReference: 'web_' . time() . '_' . rand(100, 999),
            price: $price,
            rawLlmResponse: json_encode([
                'telegram_token' => $validated['telegram_token'],
                'telegram_chat_id' => $validated['telegram_chat_id'],
            ])
        );

        try {
            // Execute deployment
            $deployment = $deployAction->execute($result);

            // Construct final URL
            $host = $request->getHost();
            $baseDomain = 'mockbuild.shop';
            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
                $baseDomain = $host;
            }
            $clientUrl = "http://{$clientSlug}.{$baseDomain}";

            // Notify Admin via Telegram bot
            $adminChatId = env('TELEGRAM_ADMIN_CHAT_ID');
            if ($adminChatId) {
                try {
                    $botService = app(\App\Services\TelegramBotService::class);
                    $formattedPrice = 'Rp ' . number_format($price, 0, ',', '.');
                    $botService->sendMessage($adminChatId, "<b>🔔 PEMESANAN WEB BARU MENUNGGU PERSETUJUAN</b>\n\n• Source: <b>Web Checkout</b>\n• Subdomain: <b>{$clientSlug}</b>\n• Durasi Sewa: <b>{$durationEnum->value}</b>\n• Harga: <b>{$formattedPrice}</b>\n\nSilakan verifikasi bukti pembayaran QRIS dari client lalu approve di dashboard atau ketik:\n<code>/approve {$clientSlug}</code>");
                } catch (\Exception $ex) {
                    \Illuminate\Support\Facades\Log::error('Failed to send telegram admin notification: ' . $ex->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pemesanan berhasil dikonfigurasi.',
                'url' => $clientUrl,
                'price' => $price,
                'deployment' => $deployment
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Deployment failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
