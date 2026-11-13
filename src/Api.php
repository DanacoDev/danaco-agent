<?php

class Api
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function register(Router $router): void
    {
        $router->add('POST', '#^/api/login$#', function (array $args = []) { $this->login(); });
        $router->add('GET', '#^/api/session$#', function (array $args = []) { $this->session(); });
        $router->add('POST', '#^/api/logout$#', $this->guard(fn() => $this->logout()));
        $router->add('GET', '#^/api/projects$#', $this->guard(fn() => $this->projects()));
        $router->add('POST', '#^/api/projects$#', $this->guard(fn() => $this->createProject()));
        $router->add('POST', '#^/api/projects/(?P<id>\d+)/rename$#', $this->guard(fn(array $args) => $this->renameProject((int)$args['id'])));
        $router->add('POST', '#^/api/projects/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteProject((int)$args['id'])));
        $router->add('GET', '#^/api/tasks$#', $this->guard(fn() => $this->tasks()));
        $router->add('POST', '#^/api/tasks$#', $this->guard(fn() => $this->createTask()));
        $router->add('PATCH', '#^/api/tasks/(?P<id>\d+)$#', $this->guard(fn(array $args) => $this->updateTask((int)$args['id'])));
        $router->add('POST', '#^/api/tasks/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteTask((int)$args['id'])));
        $router->add('POST', '#^/api/tasks/finished/clear$#', $this->guard(fn() => $this->clearFinishedTasks()));
        $router->add('GET', '#^/api/messages$#', $this->guard(fn() => $this->messages()));
        $router->add('POST', '#^/api/messages$#', $this->guard(fn() => $this->saveMessage()));
        $router->add('POST', '#^/api/context/compress$#', $this->guard(fn() => $this->compressContext()));
        $router->add('POST', '#^/api/context/clear$#', $this->guard(fn() => $this->clearContext()));
        $router->add('GET', '#^/api/context/download$#', $this->guard(fn() => $this->downloadContext()));
        $router->add('POST', '#^/api/chat$#', $this->guard(fn() => $this->chat()));
        $router->add('POST', '#^/api/chat/stop$#', $this->guard(fn() => $this->stopChat()));
        $router->add('POST', '#^/api/install/answer$#', $this->guard(fn() => $this->answerInstall()));
        $router->add('POST', '#^/api/actions/answer$#', $this->guard(fn() => $this->answerAction()));
        $router->add('GET', '#^/api/actions/pending$#', $this->guard(fn() => $this->pendingActions()));
        $router->add('GET', '#^/api/compliance$#', $this->guard(fn() => $this->complianceStatus()));
        $router->add('POST', '#^/api/compliance$#', $this->guard(fn() => $this->saveCompliance()));
        $router->add('POST', '#^/api/compliance/terms$#', $this->guard(fn() => $this->acceptTerms()));
        $router->add('GET', '#^/api/compliance/system-card$#', $this->guard(fn() => $this->systemCard()));
        $router->add('GET', '#^/api/compliance/events$#', $this->guard(fn() => $this->complianceEvents()));
        $router->add('GET', '#^/api/compliance/events/export$#', $this->guard(fn() => $this->exportComplianceEvents()));
        $router->add('GET', '#^/api/workspace/tree$#', $this->guard(fn() => $this->tree()));
        $router->add('GET', '#^/api/workspace/file$#', $this->guard(fn() => $this->file()));
        $router->add('GET', '#^/api/workspace/structured$#', $this->guard(fn() => $this->structuredFile()));
        $router->add('GET', '#^/api/workspace/download$#', $this->guard(fn() => $this->download()));
        $router->add('GET', '#^/api/workspace/exists$#', $this->guard(fn() => $this->exists()));
        $router->add('GET', '#^/api/workspace/raw$#', $this->guard(fn() => $this->rawFile()));
        $router->add('POST', '#^/api/workspace/file$#', $this->guard(fn() => $this->saveWorkspaceFile()));
        $router->add('POST', '#^/api/workspace/structured$#', $this->guard(fn() => $this->saveStructuredFile()));
        $router->add('POST', '#^/api/workspace/delete$#', $this->guard(fn() => $this->deleteWorkspaceFile()));
        $router->add('POST', '#^/api/workspace/move$#', $this->guard(fn() => $this->moveWorkspaceFile()));
        $router->add('POST', '#^/api/workspace/search$#', $this->guard(fn() => $this->workspaceSearch()));
        $router->add('POST', '#^/api/workspace/upload$#', function (array $args = []) {
            $this->requireAuth();
            $this->upload();
        });
        $router->add('GET', '#^/api/workspace/vault$#', $this->guard(fn() => $this->vault()));
        $router->add('POST', '#^/api/workspace/restore$#', $this->guard(fn() => $this->restore()));
        $router->add('GET', '#^/api/wiki$#', $this->guard(fn() => $this->wiki()));
        $router->add('POST', '#^/api/wiki/search$#', $this->guard(fn() => $this->wikiSearch()));
        $router->add('POST', '#^/api/wiki/reindex$#', $this->guard(fn() => $this->wikiReindex()));
        $router->add('GET', '#^/api/agents/status$#', $this->guard(fn() => $this->agentStatus()));
        $router->add('POST', '#^/api/agents/stop$#', $this->guard(fn() => $this->stopAgents()));
        $router->add('POST', '#^/api/agents/start$#', $this->guard(fn() => $this->startAgents()));
        $router->add('GET', '#^/api/model/load$#', $this->guard(fn() => $this->modelLoad()));
        $router->add('GET', '#^/api/agents/logs$#', $this->guard(fn() => $this->agentLogs()));
        $router->add('GET', '#^/api/agents/loop$#', $this->guard(fn() => $this->loopLogs()));
        $router->add('GET', '#^/api/agents/loop/download$#', $this->guard(fn() => $this->downloadLoop()));
        $router->add('GET', '#^/api/tools$#', $this->guard(fn() => $this->tools()));
        $router->add('POST', '#^/api/tools$#', $this->guard(fn() => $this->saveTools()));
        $router->add('GET', '#^/api/tools/email$#', $this->guard(fn() => $this->emailAccounts()));
        $router->add('POST', '#^/api/tools/email$#', $this->guard(fn() => $this->saveEmailAccount()));
        $router->add('POST', '#^/api/tools/email/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteEmailAccount((int)$args['id'])));
        $router->add('GET', '#^/api/tools/ftp$#', $this->guard(fn() => $this->ftpAccounts()));
        $router->add('POST', '#^/api/tools/ftp$#', $this->guard(fn() => $this->saveFtpAccount()));
        $router->add('POST', '#^/api/tools/ftp/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteFtpAccount((int)$args['id'])));
        $router->add('GET', '#^/api/tools/mcp$#', $this->guard(fn() => $this->mcpServers()));
        $router->add('POST', '#^/api/tools/mcp$#', $this->guard(fn() => $this->saveMcpServer()));
        $router->add('POST', '#^/api/tools/mcp/(?P<id>\d+)/refresh$#', $this->guard(fn(array $args) => $this->refreshMcpServer((int)$args['id'])));
        $router->add('POST', '#^/api/tools/mcp/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteMcpServer((int)$args['id'])));
        $router->add('GET', '#^/api/skills$#', $this->guard(fn() => $this->skills()));
        $router->add('POST', '#^/api/skills$#', $this->guard(fn() => $this->saveSkill()));
        $router->add('POST', '#^/api/skills/(?P<id>\d+)/toggle$#', $this->guard(fn(array $args) => $this->toggleSkill((int)$args['id'])));
        $router->add('POST', '#^/api/skills/(?P<id>\d+)/delete$#', $this->guard(fn(array $args) => $this->deleteSkill((int)$args['id'])));
        $router->add('POST', '#^/api/skills/(?P<id>\d+)/test$#', $this->guard(fn(array $args) => $this->testSkill((int)$args['id'])));
        $router->add('GET', '#^/api/setup$#', $this->guard(fn() => $this->setup()));
        $router->add('GET', '#^/api/setup/health$#', $this->guard(fn() => $this->systemHealth()));
        $router->add('POST', '#^/api/setup/restart$#', $this->guard(fn() => $this->restartApp()));
        $router->add('POST', '#^/api/setup$#', $this->guard(fn() => $this->saveSetup()));
        $router->add('POST', '#^/api/setup/provider-test$#', $this->guard(fn() => $this->testProvider()));
        $router->add('GET', '#^/api/setup/update$#', $this->guard(fn() => $this->updateStatus()));
        $router->add('POST', '#^/api/setup/update$#', $this->guard(fn() => $this->startUpdate()));
        $router->add('POST', '#^/api/setup/password$#', $this->guard(fn() => $this->changePassword()));
        $router->add('POST', '#^/api/setup/system-control$#', $this->guard(fn() => $this->setSystemControl()));
        $router->add('GET', '#^/api/setup/models$#', $this->guard(fn() => $this->ollamaModels()));
        $router->add('POST', '#^/api/setup/models/pull$#', $this->guard(fn() => $this->pullModel()));
        $router->add('POST', '#^/api/setup/models/delete$#', $this->guard(fn() => $this->deleteModel()));
        $router->add('POST', '#^/api/setup/backup$#', $this->guard(fn() => $this->exportBackup()));
        $router->add('POST', '#^/api/setup/backup/preview$#', function (array $args = []) {
            $this->requireAuth();
            $this->previewBackup();
        });
        $router->add('POST', '#^/api/setup/backup/restore$#', $this->guard(fn() => $this->importBackup()));
        $router->add('POST', '#^/api/setup/backup/cancel$#', $this->guard(fn() => $this->cancelBackup()));
    }

    private function guard(callable $fn): callable
    {
        return function (array $args = []) use ($fn): void {
            $this->assertJson();
            $this->requireAuth();
            $fn($args);
        };
    }

    private function login(): void
    {
        $this->assertJson();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->db->failedLogins($ip) >= (int)app_config()['login_max_attempts']) {
            $this->json(['error' => 'Too many attempts. Try again in a few minutes.'], 429);
            return;
        }
        $hash = (string)$this->db->getSetting('password_hash', '');
        if ($hash === '') {
            $this->json(['error' => 'No password is set. Run install.sh first.'], 503);
            return;
        }
        $password = (string)($this->input()['password'] ?? '');
        if (!password_verify($password, $hash)) {
            $this->db->recordLogin($ip, false);
            $this->json(['error' => 'Wrong password.'], 401);
            return;
        }
        $this->db->clearLogins($ip);
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $this->json(['ok' => true]);
    }

    private function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
        $this->json(['ok' => true]);
    }

    private function session(): void
    {
        $docs = new ComplianceDocs($this->db);
        $this->json([
            'authed' => !empty($_SESSION['authed']),
            'password_set' => (string)$this->db->getSetting('password_hash', '') !== '',
            'terms_accepted' => $docs->termsAccepted(),
            'terms_version' => ComplianceDocs::TERMS_VERSION,
        ]);
    }

    private function projects(): void
    {
        $this->json(['projects' => $this->db->projects()]);
    }

    private function createProject(): void
    {
        $body = $this->input();
        try {
            $project = Projects::create(
                $this->db,
                (string)($body['name'] ?? ''),
                (string)($body['folder_name'] ?? $body['folder'] ?? ''),
                (string)($body['description'] ?? $body['purpose'] ?? ''),
                (string)($body['project_type'] ?? $body['type'] ?? ''),
                (string)($body['goals'] ?? ''),
                (string)($body['limitations'] ?? ''),
                (string)($body['role'] ?? '')
            );
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json(['project' => $project], 201);
    }

    private function renameProject(int $id): void
    {
        try {
            $project = Projects::rename($this->db, $id, (string)($this->input()['name'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        } catch (RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 500);
            return;
        }
        if (!$project) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $this->json(['project' => $project]);
    }

    private function deleteProject(int $id): void
    {
        $removeFolder = !empty($this->input()['folder']);
        $project = $this->db->deleteProject($id);
        if (!$project) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        Projects::removeIndex((string)$project['folder_name']);
        if ($removeFolder) {
            try {
                Projects::deleteFolder((string)$project['folder_name']);
            } catch (RuntimeException $e) {
                $this->json(['error' => $e->getMessage()], 500);
                return;
            }
        }
        $this->json(['ok' => true]);
    }

    private function tasks(): void
    {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $this->json(['tasks' => $this->db->tasks($projectId > 0 ? $projectId : null)]);
    }

    private function createTask(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if (!$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '' || strlen($title) > 200) {
            $this->json(['error' => 'Task title is required.'], 422);
            return;
        }
        try {
            $task = (new Kanban($this->db))->create($projectId, $body, true);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json(['task' => $task], 201);
    }

    private function updateTask(int $id): void
    {
        $existing = $this->db->task($id);
        if (!$existing) {
            $this->json(['error' => 'Task not found.'], 404);
            return;
        }
        $body = $this->input();
        try {
            $task = (new Kanban($this->db))->move($id, $body, true);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json(['task' => $task]);
    }

    private function deleteTask(int $id): void
    {
        if (!$this->db->deleteTask($id)) {
            $this->json(['error' => 'Task not found.'], 404);
            return;
        }
        $this->json(['ok' => true]);
    }

    private function clearFinishedTasks(): void
    {
        $projectId = (int)($this->input()['project_id'] ?? 0);
        if ($projectId <= 0 || !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $this->json(['ok' => true, 'removed' => $this->db->deleteFinishedTasks($projectId)]);
    }

    private function presentMessages(int $projectId, array $rows): array
    {
        $until = (int)$this->db->getSetting('context_summary_until_' . max(0, $projectId), '0');
        $summary = '';
        if ($until > 0) {
            $folder = null;
            if ($projectId > 0) {
                $project = $this->db->project($projectId);
                $folder = is_array($project) ? (string)$project['folder_name'] : null;
            }
            $summary = app_services()['memory']->readContextSummary($folder);
        }
        $conversational = [];
        foreach ($rows as $row) {
            if (in_array($row['role'] ?? '', ['user', 'assistant'], true)) {
                $conversational[] = $row;
            }
        }
        $recent = [];
        foreach (array_slice($conversational, -ContextWindow::KEEP) as $row) {
            $recent[(int)$row['id']] = true;
        }
        $out = [];
        if ($until > 0 && trim($summary) !== '') {
            $out[] = [
                'id' => 'summary-' . $until,
                'role' => 'notice',
                'agent_name' => 'orchestrator',
                'content' => ContextWindow::NOTICE,
                'failed' => 0,
                'detail' => null,
            ];
        }
        $onScheduled = false;
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $role = (string)($row['role'] ?? '');
            if ($role === 'user') {
                $content = ltrim((string)($row['content'] ?? ''));
                $onScheduled = (string)($row['agent_name'] ?? '') === 'task' || str_starts_with($content, 'Scheduled task:');
            }
            if ($role === 'notice' && $onScheduled && $this->taskRecoveryNote((string)($row['content'] ?? ''))) {
                continue;
            }
            if ($role === 'user' || $role === 'assistant') {
                if ($until > 0 && $id <= $until && !isset($recent[$id])) {
                    continue;
                }
                if ($role === 'assistant') {
                    $row['content'] = trim(strip_chat_tags((string)($row['content'] ?? '')));
                }
                $row['detail'] = isset($recent[$id]) ? $this->trimThinking($row['detail'] ?? null) : $this->leanDetail($row['detail'] ?? null);
                $out[] = $row;
                continue;
            }
            if ($role === 'notice' && $until > 0 && $id <= $until) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function taskRecoveryNote(string $text): bool
    {
        foreach ([
            'The reply ran out of room while thinking.',
            'The reply stopped while thinking.',
            'Ollama was not running.',
            'The whole model does not fit on the GPU.',
            'Freed memory by unloading ',
            'Checked memory.',
            'Lowered the context window',
            'The model request did not go through.',
            'The model connection was lost.',
            'The GPU model is loaded.',
        ] as $prefix) {
            if (str_contains($text, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function trimThinking(?array $detail): ?array
    {
        if (!$detail) {
            return null;
        }
        $thinking = [];
        foreach ($detail['thinking'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = trim((string)($block['text'] ?? ''));
            $marker = "\n…[truncated]";
            if (str_ends_with($text, $marker)) {
                $text = substr($text, 0, -strlen($marker));
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if (strlen($text) > 1200) {
                $text = '…' . substr($text, -1200);
            }
            $thinking[] = [
                'agent' => (string)($block['agent'] ?? ''),
                'text' => $text,
            ];
        }
        $tools = [];
        foreach ($detail['tools'] ?? [] as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $tools[] = [
                'agent' => (string)($tool['agent'] ?? ''),
                'name' => (string)($tool['name'] ?? ''),
                'args' => is_array($tool['args'] ?? null) ? $tool['args'] : [],
                'result' => clip_text((string)($tool['result'] ?? ''), 800),
            ];
        }
        if (!$thinking && !$tools) {
            return null;
        }
        return ['thinking' => $thinking, 'tools' => $tools];
    }

    private function leanDetail(?array $detail): ?array
    {
        if (!$detail) {
            return null;
        }
        $tools = [];
        foreach ($detail['tools'] ?? [] as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $result = trim((string)($tool['result'] ?? ''));
            if ($result === '') {
                continue;
            }
            $tools[] = [
                'agent' => (string)($tool['agent'] ?? ''),
                'name' => (string)($tool['name'] ?? ''),
                'args' => [],
                'result' => clip_text($result, 400),
            ];
        }
        return $tools ? ['thinking' => [], 'tools' => $tools] : null;
    }

    private function messages(): void
    {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $key = max(0, $projectId);
        $this->json([
            'messages' => $this->presentMessages($projectId, $this->db->messages($projectId)),
            'context' => app_services()['orchestrator']->contextUsage($projectId),
            'compressing' => $this->db->contextCompressLocked($projectId) || $this->db->getSetting('context_compress_pending_' . $key, '0') === '1',
        ]);
    }

    private function saveMessage(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $message = trim((string)($body['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 20000) {
            $this->json(['error' => 'Message is required.'], 422);
            return;
        }
        $this->json(['message' => $this->db->addMessage($projectId, 'user', $message, 'human')]);
    }

    private function compressContext(): void
    {
        $projectId = (int)($this->input()['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $result = app_services()['orchestrator']->compressContext($projectId);
        if (!empty($result['deferred'])) {
            $this->json($result);
            return;
        }
        if (empty($result['compressed'])) {
            $this->json(['error' => 'Not enough conversation to compress.'], 422);
            return;
        }
        $this->json($result);
    }

    private function clearContext(): void
    {
        $projectId = (int)($this->input()['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $this->db->clearMessages($projectId);
        $key = (string)max(0, $projectId);
        $this->db->setSetting('context_summary_until_' . $key, '0');
        $this->db->setSetting('context_compress_' . $key, '0');
        $this->db->setSetting('context_compress_pending_' . $key, '0');
        $this->db->releaseContextCompress($projectId);
        if ($projectId > 0) {
            $this->db->setSetting('reread_desc_' . $key, '1');
        }
        $this->json(['ok' => true]);
    }

    private function chat(): void
    {
        @set_time_limit(0);
        if ($this->db->getSetting('model_hold', '0') === '1') {
            $this->db->setSetting('model_hold', '0');
        }
        $load = $this->modelLoadStatus();
        if (empty($load['ready'])) {
            $this->db->loopWrite(null, null, 'heartbeat', 'process', 'Chat refused · model still loading');
            $this->json(['error' => 'The model is still loading.'], 409);
            return;
        }
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->db->loopWrite(null, $projectId, 'heartbeat', 'process', 'Chat refused · project not found');
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $message = trim((string)($body['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 20000) {
            $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Chat refused · message rejected');
            $this->json(['error' => 'Message is required.'], 422);
            return;
        }
        if ($this->db->runStillStopping($projectId)) {
            $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Chat refused · run still stopping');
            $this->json(['error' => 'The run is still stopping.'], 409);
            return;
        }
        $this->db->waitContextCompress($projectId, null);
        if ($projectId > 0) {
            if (!empty($body['pause_heartbeat'])) {
                $this->db->pauseHeartbeat($projectId);
            } else {
                $this->db->resumeHeartbeat($projectId);
            }
        }
        $generation = trim((string)($body['generation'] ?? ''));
        if (strlen($generation) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/', $generation)) {
            $generation = bin2hex(random_bytes(8));
        }
        $this->settlePreviousChat($projectId);
        $this->db->beginChat($projectId, $generation);
        Recovery::reset($this->db);
        if (!empty($body['retry'])) {
            $this->db->deleteFailedMessage($projectId, (int)($body['failed_id'] ?? 0));
        } elseif (empty($body['saved'])) {
            $this->db->addMessage($projectId, 'user', $message, 'human');
        }
        $jobId = $this->db->startJob('chat', $projectId > 0 ? $projectId : null, ['message' => $message]);
        $this->db->loopPush($jobId, $projectId > 0 ? $projectId : null);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->beginSse();
        $turn = new ChatTurn();
        $emit = function (array $event): void {
            $this->sse((string)($event['type'] ?? 'message'), $event);
        };
        $lastPing = 0.0;
        $lastStopCheck = 0.0;
        $stopCached = false;
        $stop = function () use ($projectId, &$lastPing, &$lastStopCheck, &$stopCached, $jobId): bool {
            $now = microtime(true);
            if (($now - $lastPing) > 1) {
                $lastPing = $now;
                echo ": ping\n\n";
                @flush();
            }
            if (($now - $lastStopCheck) < 0.25) {
                return $stopCached;
            }
            $lastStopCheck = $now;
            try {
                $this->db->touchJob($jobId);
                $stopCached = $this->db->chatStopRequested($projectId) || $this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($jobId);
            } catch (Throwable $e) {
                return $stopCached;
            }
            return $stopCached;
        };
        try {
            $this->db->loop('orchestrator', 'process', 'Job ' . $jobId . ' started · chat');
            if (!empty($body['retry'])) {
                $this->db->loop('orchestrator', 'process', 'User retry of a failed message');
            }
            $this->db->loop('orchestrator', 'process', 'Recovery counters reset');
            try {
                $answer = $turn->run($projectId, $emit, $stop);
                if ($stop()) {
                    throw new ChatStopped('Stopped.');
                }
                $detail = ChatTurn::encodeDetail($turn->detail);
                if (trim($answer) === '' && $detail === null) {
                    throw new RuntimeException('The model returned an empty answer.');
                }
                $saved = $this->db->addMessage($projectId, 'assistant', $answer, 'orchestrator', false, $detail);
                $this->db->markChatAnswerSaved($jobId);
                $this->db->takeChatFollowup($projectId, $jobId);
                $this->db->loop('orchestrator', 'process', 'Answer saved · job ' . $jobId . ' finished');
                $this->db->finishJob($jobId);
                $this->sse('done', ['message' => $saved]);
            } catch (ChatStopped $e) {
                $text = trim($turn->streamed);
                $followup = $this->db->takeChatFollowup($projectId, $jobId);
                $runStop = $this->db->runStopRequested($projectId);
                $closed = !$this->db->jobIsRunning($jobId);
                $asked = $this->db->chatStopRequested($projectId);
                $this->db->finishJob($jobId);
                if ($followup && !$runStop) {
                    $this->db->loop('orchestrator', 'process', 'Follow-up · answer kept · job ' . $jobId . ' finished');
                    if ($text !== '') {
                        $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', false, ChatTurn::encodeDetail($turn->detail));
                    }
                    $this->db->setAgentStatus('orchestrator', 'idle');
                    $this->sse('stopped', ['text' => 'Stopped.']);
                } elseif ($runStop || $closed) {
                    $this->db->acknowledgeRunStop($projectId, $jobId);
                    $this->db->loop('orchestrator', 'process', 'Stopped by user · job ' . $jobId . ' finished');
                    if ($text !== '') {
                        $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', false, ChatTurn::encodeDetail($turn->detail));
                    }
                    $this->db->setAgentStatus('orchestrator', 'idle');
                    $this->sse('stopped', ['text' => 'All processes stopped by user. Waiting for further instructions.']);
                } elseif ($asked) {
                    $this->db->loop('orchestrator', 'process', 'Intentional stop · job ' . $jobId . ' finished');
                    $text = $text === '' ? 'Stopped.' : $text . "\n\nStopped.";
                    $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', false, ChatTurn::encodeDetail($turn->detail));
                    $this->db->setAgentStatus('orchestrator', 'idle');
                    $this->sse('stopped', ['text' => 'Stopped.']);
                } else {
                    $this->db->loop('orchestrator', 'process', 'Stop while the model was still thinking · job ' . $jobId . ' finished');
                    $why = error_text('The reply stopped while the model was still thinking.');
                    $text = $text !== '' ? $text . "\n\n" . $why : $why;
                    $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', true, ChatTurn::encodeDetail($turn->detail));
                    $this->db->setAgentStatus('orchestrator', 'idle');
                    $this->sse('error', ['text' => $why]);
                }
                $this->db->markChatAnswerSaved($jobId);
            } catch (Throwable $e) {
                $reason = $e->getMessage();
                $retry = str_contains($reason, 'cut off before it finished') ? 1 : Heartbeat::MAX_ATTEMPTS;
                $this->db->failJob($jobId, $reason, $retry);
                $this->db->setAgentStatus('orchestrator', 'error');
                if (str_contains($reason, 'empty answer')) {
                    $line = 'Empty answer · job ' . $jobId;
                } else {
                    $line = 'Job ' . $jobId . ' failed · ' . $reason;
                }
                $line .= $retry === 1 ? ' · give up' : ' · heartbeat may retry';
                $this->db->loop('orchestrator', 'process', $line);
                $error = error_text($reason);
                $text = trim($turn->streamed);
                $text = $text !== '' ? $text . "\n\n" . $error : $error;
                $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', true, ChatTurn::encodeDetail($turn->detail));
                $this->db->markChatAnswerSaved($jobId);
                $this->db->takeChatFollowup($projectId, $jobId);
                $this->sse('status', ['agent' => 'orchestrator', 'status' => 'error']);
                $this->sse('error', ['text' => $error]);
            }
        } finally {
            app_services()['llm']->releaseIfIdle();
            $this->db->loopPop();
        }
    }

    private function settlePreviousChat(int $projectId): void
    {
        $job = $this->db->runningChatJob($projectId);
        if (!$job) {
            return;
        }
        $jobId = (int)$job['id'];
        $this->db->markChatFollowup($projectId, $jobId);
        $generation = $this->db->chatGeneration($projectId);
        if ($generation !== '') {
            $this->db->requestChatStop($projectId, $generation);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if ($this->db->chatAnswerSaved($jobId)) {
                return;
            }
            usleep(200000);
        }
        $scope = $projectId > 0 ? $projectId : null;
        $this->db->loopWrite(null, $scope, 'heartbeat', 'process', 'Follow-up did not settle · job ' . $jobId);
    }

    private function stopChat(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $generation = trim((string)($body['generation'] ?? ''));
        $this->db->requestChatStop($projectId, $generation);
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Stop asked');
        $this->json(['ok' => true]);
    }

    private function stopAgents(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $jobs = $this->db->runningJobs($projectId);
        if (!$jobs && $this->db->runStillStopping($projectId)) {
            $held = [];
            if ($projectId > 0) {
                foreach ($this->db->tasks($projectId) as $task) {
                    if ((int)($task['held'] ?? 0) === 1) {
                        $held[] = (int)$task['id'];
                    }
                }
            }
            $this->json(['ok' => true, 'held' => $held]);
            return;
        }
        $held = [];
        $ids = [];
        foreach ($jobs as $row) {
            $id = (int)$row['id'];
            if ((string)($row['status'] ?? '') !== 'running') {
                $this->db->finishJob($id);
                continue;
            }
            $ids[] = $id;
            if ((string)$row['kind'] !== 'task') {
                continue;
            }
            $taskId = (int)($row['ref_id'] ?? 0);
            if ($taskId <= 0) {
                $payload = json_decode((string)($row['payload'] ?? ''), true);
                $taskId = is_array($payload) ? (int)($payload['task_id'] ?? 0) : 0;
            }
            if ($taskId <= 0) {
                continue;
            }
            $this->db->updateTask($taskId, ['held' => 1]);
            $held[$taskId] = $taskId;
        }
        $this->db->requestRunStop($projectId, $ids);
        $generation = trim((string)($body['generation'] ?? ''));
        if ($generation !== '') {
            $this->db->requestChatStop($projectId, $generation);
        }
        foreach ($ids as $id) {
            $this->db->finishJob($id);
        }
        if ($ids && $projectId > 0) {
            $this->db->pauseHeartbeat($projectId);
        }
        $this->db->addMessage($projectId, 'notice', 'All processes stopped by user. Waiting for further instructions.', 'orchestrator');
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Stopped by user');
        $this->json(['ok' => true, 'held' => array_values($held)]);
    }

    private function startAgents(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId > 0 && !$this->db->project($projectId)) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        if ($projectId > 0) {
            $this->db->resumeHeartbeat($projectId);
        }
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Heartbeat resumed');
        $this->json(['ok' => true, 'heartbeat_paused' => false]);
    }

    private function answerInstall(): void
    {
        $this->answerAction();
    }

    private function answerAction(): void
    {
        $body = $this->input();
        $id = (string)($body['id'] ?? '');
        if (!$this->db->answerAction($id, !empty($body['allow']))) {
            $this->json(['error' => 'Unknown action prompt.'], 422);
            return;
        }
        $this->json(['ok' => true]);
    }

    private function pendingActions(): void
    {
        $this->json([
            'pending' => $this->db->pendingActions(),
            'hitl' => $this->db->hitlExternalActions(),
        ]);
    }

    private function complianceStatus(): void
    {
        $docs = new ComplianceDocs($this->db);
        $this->json([
            'status' => $docs->status(),
            'intended_purpose' => ComplianceDocs::INTENDED_PURPOSE,
            'disclosure' => $docs->disclosureSnippet(),
            'events' => $this->db->complianceEvents(50),
        ]);
    }

    private function saveCompliance(): void
    {
        $body = $this->input();
        if (array_key_exists('ai_act_applies', $body)) {
            $value = (string)$body['ai_act_applies'];
            if (!in_array($value, ['', 'yes', 'no', 'unsure'], true)) {
                $this->json(['error' => 'Choose yes, no, or not sure.'], 422);
                return;
            }
            $this->db->setSetting('ai_act_applies', $value);
        }
        if (array_key_exists('compliance_logging', $body)) {
            $this->db->setSetting('compliance_logging', !empty($body['compliance_logging']) ? '1' : '0');
        }
        if (array_key_exists('hitl_external_actions', $body)) {
            $this->db->setSetting('hitl_external_actions', !empty($body['hitl_external_actions']) ? '1' : '0');
        }
        if (array_key_exists('compliance_retention_days', $body)) {
            $days = (int)$body['compliance_retention_days'];
            $this->db->setSetting('compliance_retention_days', (string)max(7, min(3650, $days)));
        }
        if (array_key_exists('compliance_responsible_person', $body)) {
            $person = trim((string)$body['compliance_responsible_person']);
            if (strlen($person) > 200 || preg_match('/[\r\n]/', $person)) {
                $this->json(['error' => 'Responsible person must be a single line up to 200 characters.'], 422);
                return;
            }
            $this->db->setSetting('compliance_responsible_person', $person);
        }
        if (array_key_exists('compliance_intended_use', $body)) {
            $notes = trim((string)$body['compliance_intended_use']);
            if (strlen($notes) > 4000) {
                $this->json(['error' => 'Intended-use notes are too long.'], 422);
                return;
            }
            $this->db->setSetting('compliance_intended_use', $notes);
        }
        $this->db->logCompliance('setup_change', 'user', 'Compliance settings updated.', [
            'ai_act_applies' => (string)$this->db->getSetting('ai_act_applies', ''),
            'compliance_logging' => (string)$this->db->getSetting('compliance_logging', '0'),
            'hitl_external_actions' => (string)$this->db->getSetting('hitl_external_actions', '0'),
        ]);
        $docs = new ComplianceDocs($this->db);
        $this->json([
            'status' => $docs->status(),
            'settings' => $this->publicSettings(),
            'disclosure' => $docs->disclosureSnippet(),
        ]);
    }

    private function acceptTerms(): void
    {
        $docs = new ComplianceDocs($this->db);
        $docs->acceptTerms();
        $this->json([
            'ok' => true,
            'status' => $docs->status(),
            'settings' => $this->publicSettings(),
        ]);
    }

    private function systemCard(): void
    {
        $docs = new ComplianceDocs($this->db);
        $markdown = $docs->systemCardMarkdown();
        $this->db->setSetting('system_card_exported_at', gmdate('Y-m-d H:i:s'));
        $this->db->logCompliance('system_card_export', 'user', 'System card exported.');
        $download = !empty($_GET['download']);
        if ($download) {
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="danaco-system-card.md"');
            header('Cache-Control: no-store');
            echo $markdown;
            return;
        }
        $this->json([
            'markdown' => $markdown,
            'status' => $docs->status(),
        ]);
    }

    private function complianceEvents(): void
    {
        $limit = (int)($_GET['limit'] ?? 100);
        $this->json(['events' => $this->db->complianceEvents($limit)]);
    }

    private function exportComplianceEvents(): void
    {
        $rows = $this->db->exportComplianceEvents();
        $format = strtolower((string)($_GET['format'] ?? 'json'));
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="danaco-compliance-events.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            if ($out === false) {
                $this->json(['error' => 'Could not write export.'], 500);
                return;
            }
            fputcsv($out, ['id', 'created_at', 'project_id', 'actor', 'event_type', 'summary', 'detail_json', 'related_job_id']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'] ?? '',
                    $row['created_at'] ?? '',
                    $row['project_id'] ?? '',
                    $row['actor'] ?? '',
                    $row['event_type'] ?? '',
                    $row['summary'] ?? '',
                    $row['detail_json'] ?? '',
                    $row['related_job_id'] ?? '',
                ]);
            }
            fclose($out);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="danaco-compliance-events.json"');
        header('Cache-Control: no-store');
        echo json_encode(['events' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function downloadContext(): void
    {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $context = app_services()['orchestrator']->contextForDownload($projectId);
        $text = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($text)) {
            $this->json(['error' => 'Could not encode the context.'], 500);
            return;
        }
        $name = $projectId > 0 && is_array($project)
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$project['folder_name']) . '-context.txt'
            : 'danaco-context.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($name ?: 'danaco-context.txt') . '"');
        header('Content-Length: ' . strlen($text));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $text;
    }

    private function tree(): void
    {
        $this->json(['tree' => app_services()['safety']->listWorkspace($this->db->projects())]);
    }

    private function file(): void
    {
        $scope = (string)($_GET['scope'] ?? '');
        $folder = (string)($_GET['folder'] ?? '');
        $path = (string)($_GET['path'] ?? '');
        try {
            if ($scope === 'wiki' || $scope === 'project') {
                $file = app_services()['safety']->readWorkspace($scope, $folder, $path, 100000);
            } else {
                $project = $this->projectFromQuery();
                $file = ['path' => $path, 'content' => app_services()['safety']->readFile($project['folder_name'], $path, 100000)];
            }
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($file);
    }

    private function structuredFile(): void
    {
        $scope = (string)($_GET['scope'] ?? '');
        $folder = (string)($_GET['folder'] ?? '');
        $path = (string)($_GET['path'] ?? '');
        try {
            $this->json((new StructuredFile(app_services()['safety']))->read($scope, $folder, $path));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    private function exists(): void
    {
        $folder = $this->uploadFolder((int)($_GET['project_id'] ?? 0));
        if ($folder === null) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $path = (string)($_GET['path'] ?? '');
        try {
            $ok = app_services()['safety']->fileExists($folder, $path);
        } catch (Throwable $e) {
            $ok = false;
        }
        $this->json(['exists' => $ok]);
    }

    private function download(): void
    {
        $folder = $this->uploadFolder((int)($_GET['project_id'] ?? 0));
        if ($folder === null) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        try {
            $file = app_services()['safety']->readBytes($folder, (string)($_GET['path'] ?? ''), 8 * 1024 * 1024);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $name = basename($file['path']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = [
            'pdf' => 'application/pdf',
            'md' => 'text/markdown; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'html' => 'text/html; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($file['bytes']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $file['bytes'];
    }

    private function rawFile(): void
    {
        $scope = (string)($_GET['scope'] ?? '');
        $folder = (string)($_GET['folder'] ?? '');
        $path = (string)($_GET['path'] ?? '');
        $name = basename(str_replace('\\', '/', $path));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];
        if (!isset($types[$ext])) {
            $this->json(['error' => 'That file cannot be previewed.'], 422);
            return;
        }
        try {
            $file = app_services()['safety']->readWorkspaceBytes($scope, $folder, $path, 8 * 1024 * 1024);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $name = str_replace(["\r", "\n", '"'], '', $name);
        if ($name === '') {
            $name = 'file.' . $ext;
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . strlen($file['bytes']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $file['bytes'];
    }

    private function saveWorkspaceFile(): void
    {
        $body = $this->input();
        try {
            $file = app_services()['safety']->writeWorkspace(
                (string)($body['scope'] ?? ''),
                (string)($body['folder'] ?? ''),
                (string)($body['path'] ?? ''),
                (string)($body['content'] ?? '')
            );
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($file);
    }

    private function saveStructuredFile(): void
    {
        $body = $this->input();
        try {
            $result = (new StructuredFile(app_services()['safety']))->save(
                (string)($body['scope'] ?? ''),
                (string)($body['folder'] ?? ''),
                (string)($body['path'] ?? ''),
                $body
            );
            $this->json($result);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    private function deleteWorkspaceFile(): void
    {
        $body = $this->input();
        try {
            $file = app_services()['safety']->deleteWorkspace(
                (string)($body['scope'] ?? ''),
                (string)($body['folder'] ?? ''),
                (string)($body['path'] ?? '')
            );
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($file);
    }

    private function moveWorkspaceFile(): void
    {
        $body = $this->input();
        try {
            $file = app_services()['safety']->moveWorkspace(
                (string)($body['scope'] ?? ''),
                (string)($body['folder'] ?? ''),
                (string)($body['path'] ?? ''),
                (string)($body['dest_scope'] ?? ''),
                (string)($body['dest_folder'] ?? ''),
                (string)($body['dest_path'] ?? '')
            );
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($file);
    }

    private function workspaceSearch(): void
    {
        try {
            $results = app_services()['safety']->searchWorkspace((string)($this->input()['query'] ?? ''), $this->db->projects());
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json(['results' => $results]);
    }

    private function upload(): void
    {
        $folder = $this->uploadFolder((int)($_POST['project_id'] ?? 0));
        if ($folder === null) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            $this->json(['error' => 'Choose a file to upload.'], 422);
            return;
        }
        if ((int)$file['size'] > 8 * 1024 * 1024) {
            $this->json(['error' => 'File is larger than 8 MB.'], 422);
            return;
        }
        $name = $this->safeUploadName((string)$file['name']);
        $content = file_get_contents((string)$file['tmp_name']);
        if ($content === false) {
            $this->json(['error' => 'Could not read the uploaded file.'], 422);
            return;
        }
        try {
            $uploads = Projects::root($folder) . '/uploads';
            if (!is_dir($uploads) && !mkdir($uploads, 0775, true) && !is_dir($uploads)) {
                throw new RuntimeException('Could not create the uploads folder.');
            }
            $written = app_services()['safety']->writeFile($folder, 'uploads/' . $name, $content);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json(['name' => $name, 'path' => $written['path']]);
    }

    private function uploadFolder(int $projectId): ?string
    {
        if ($projectId <= 0) {
            return Projects::generalFolder();
        }
        $project = $this->db->project($projectId);
        if (!$project) {
            return null;
        }
        return (string)$project['folder_name'];
    }

    private function safeUploadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';
        $name = trim($name, '._');
        if ($name === '' || str_contains($name, '..')) {
            $name = 'file';
        }
        return substr($name, 0, 80);
    }

    private function vault(): void
    {
        $safety = app_services()['safety'];
        $this->json([
            'backups' => $safety->listBackups(),
            'trash' => $safety->listTrash(),
        ]);
    }

    private function restore(): void
    {
        $body = $this->input();
        try {
            $result = app_services()['safety']->restore((string)($body['kind'] ?? ''), (string)($body['stored_as'] ?? ''));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($result);
    }

    private function wiki(): void
    {
        $memory = app_services()['memory'];
        $which = (string)($_GET['which'] ?? 'central');
        if ($which === 'project') {
            $project = $this->projectFromQuery();
            $folder = (string)$project['folder_name'];
            $name = Projects::descFile($folder);
            try {
                $content = app_services()['safety']->readFile($folder, 'wiki/' . $name, 100000);
            } catch (Throwable $e) {
                $content = '';
            }
            $this->json(['which' => 'project', 'name' => $name, 'content' => $content, 'files' => [$name]]);
            return;
        }
        $files = array_map(static fn(array $file): string => $file['name'], $memory->centralFiles());
        $name = basename((string)($_GET['name'] ?? 'projects_index.md'));
        if (strtolower($name) === 'tools.md') {
            $this->json(['error' => 'That file is not available.'], 404);
            return;
        }
        if (!in_array($name, $files, true)) {
            $name = in_array('projects_index.md', $files, true) ? 'projects_index.md' : ($files[0] ?? '');
        }
        $content = '';
        if ($name !== '') {
            try {
                $content = $memory->readCentral($name);
            } catch (Throwable $e) {
                $content = '';
            }
        }
        $this->json(['which' => 'central', 'name' => $name, 'content' => $content, 'files' => $files]);
    }

    private function wikiSearch(): void
    {
        $body = $this->input();
        $folder = null;
        if (!empty($body['project_id'])) {
            $project = $this->db->project((int)$body['project_id']);
            $folder = $project['folder_name'] ?? null;
        }
        $result = app_services()['memory']->search((string)($body['query'] ?? ''), $folder);
        $this->json(['result' => $result]);
    }

    private function wikiReindex(): void
    {
        $memory = app_services()['memory'];
        $this->json(['update' => $memory->update(), 'embed' => $memory->embed()]);
    }

    private function agentStatus(): void
    {
        $labels = [
            'idle' => 'Idle',
            'thinking' => 'Thinking',
            'executing_tool' => 'Executing Tool',
            'completed' => 'Completed',
            'error' => 'Error',
            'stuck' => 'Stuck',
            'inactive' => 'Inactive',
        ];
        $reconciled = (new Heartbeat($this->db))->reconcile();
        $consultant = $this->db->consultantState();
        $agents = [];
        foreach (array_keys(Database::idleStatus()) as $name) {
            $row = $reconciled[$name] ?? ['status' => 'idle', 'project_id' => null];
            $status = (string)$row['status'];
            $projectId = $row['project_id'];
            $note = '';
            if ($name === 'consultant' && !$consultant['active']) {
                $status = 'inactive';
                $note = (string)$consultant['note'];
                $projectId = null;
            }
            $agents[] = [
                'name' => $name,
                'status' => $status,
                'label' => $labels[$status] ?? $status,
                'note' => $note,
                'reason' => $name === 'consultant' ? (string)$consultant['reason'] : '',
                'project_id' => $projectId,
            ];
        }
        $projectId = (int)($_GET['project_id'] ?? 0);
        $this->json([
            'agents' => $agents,
            'stopping' => $this->db->runStillStopping($projectId),
            'heartbeat_paused' => $projectId > 0 && $this->db->heartbeatPaused($projectId),
        ]);
    }

    private function agentLogs(): void
    {
        $after = (int)($_GET['after'] ?? 0);
        $agent = trim((string)($_GET['agent'] ?? ''));
        $projectId = (int)($_GET['project_id'] ?? 0);
        $this->json(['logs' => $this->db->logs($after, $agent, $projectId > 0 ? $projectId : null)]);
    }

    private function loopLogs(): void
    {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $this->json(['logs' => $this->db->loopLogs($projectId > 0 ? $projectId : null)]);
    }

    private function downloadLoop(): void
    {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            $this->json(['error' => 'Project not found.'], 404);
            return;
        }
        $label = is_array($project) ? (string)($project['name'] ?? '') : '';
        $text = $this->db->loopLogText($projectId > 0 ? $projectId : null, $label);
        $slug = $projectId > 0 && is_array($project)
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$project['folder_name'])
            : 'danaco';
        $name = ($slug !== '' ? $slug : 'danaco') . '-loop.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($text));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $text;
    }

    private function tools(): void
    {
        $this->json(['settings' => $this->publicSettings()]);
    }

    private function saveTools(): void
    {
        $body = $this->input();
        if (!empty($body['clear_cloud_api_key'])) {
            $this->db->setSetting('cloud_api_key', '');
        } elseif (array_key_exists('cloud_api_key', $body)) {
            $this->db->setSetting('cloud_api_key', $this->keepSecret((string)$body['cloud_api_key'], (string)$this->db->getSetting('cloud_api_key', '')));
        }
        if (array_key_exists('extra_api_key_name', $body)) {
            $name = trim((string)$body['extra_api_key_name']);
            if ($name !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $this->json(['error' => 'Extra key name must be an environment variable name.'], 422);
                return;
            }
            $this->db->setSetting('extra_api_key_name', $name);
        }
        if (!empty($body['clear_extra_api_key'])) {
            $this->db->setSetting('extra_api_key_value', '');
        } elseif (array_key_exists('extra_api_key_value', $body)) {
            $this->db->setSetting('extra_api_key_value', $this->keepSecret((string)$body['extra_api_key_value'], (string)$this->db->getSetting('extra_api_key_value', '')));
        }
        $this->json(['settings' => $this->publicSettings()]);
    }

    private function emailAccounts(): void
    {
        $this->json(['accounts' => $this->db->emailAccounts()]);
    }

    private function saveEmailAccount(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId <= 0 || !$this->db->project($projectId)) {
            $this->json(['error' => 'Choose a project for this mailbox.'], 422);
            return;
        }
        $fromName = trim((string)($body['from_name'] ?? ''));
        $fromEmail = trim((string)($body['from_email'] ?? ''));
        $host = trim((string)($body['smtp_host'] ?? ''));
        $port = (int)($body['smtp_port'] ?? 0);
        $security = (string)($body['smtp_security'] ?? 'tls');
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($fromName === '' || strlen($fromName) > 120 || preg_match('/[\r\n]/', $fromName)) {
            $this->json(['error' => 'From name is required.'], 422);
            return;
        }
        $label = $fromName;
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $fromEmail)) {
            $this->json(['error' => 'From email is not valid.'], 422);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) || !str_contains($host, '.')) {
            $this->json(['error' => 'SMTP host is not valid.'], 422);
            return;
        }
        if ($port < 1 || $port > 65535) {
            $this->json(['error' => 'SMTP port is not valid.'], 422);
            return;
        }
        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            $security = 'tls';
        }
        if ($username === '' || strlen($username) > 200 || $password === '' || strlen($password) > 200) {
            $this->json(['error' => 'Username and password are required.'], 422);
            return;
        }
        $account = $this->db->saveEmailAccount([
            'project_id' => $projectId,
            'label' => $label,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_security' => $security,
            'username' => $username,
            'password' => $password,
        ]);
        $project = $this->db->project($projectId);
        app_services()['memory']->remember('email', 'Mailbox "' . $label . '" <' . $fromEmail . '> via ' . $host . ' for ' . (string)($project['name'] ?? 'a project') . '.');
        $this->json(['account' => $account]);
    }

    private function ftpAccounts(): void
    {
        $this->json(['accounts' => $this->db->ftpAccounts()]);
    }

    private function saveFtpAccount(): void
    {
        $body = $this->input();
        $projectId = (int)($body['project_id'] ?? 0);
        if ($projectId <= 0 || !$this->db->project($projectId)) {
            $this->json(['error' => 'Choose a project for this FTP login.'], 422);
            return;
        }
        $label = trim((string)($body['label'] ?? ''));
        $host = trim((string)($body['host'] ?? ''));
        $port = (int)($body['port'] ?? 0);
        $security = (string)($body['security'] ?? 'ftp');
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($label === '' || strlen($label) > 80 || preg_match('/[\r\n]/', $label)) {
            $this->json(['error' => 'Name is required.'], 422);
            return;
        }
        foreach ($this->db->ftpAccounts($projectId) as $existing) {
            if (strtolower((string)$existing['label']) === strtolower($label)) {
                $this->json(['error' => 'This project already has an FTP login with that name.'], 422);
                return;
            }
        }
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) || !str_contains($host, '.')) {
            $this->json(['error' => 'FTP host is not valid.'], 422);
            return;
        }
        if ($port < 1 || $port > 65535) {
            $this->json(['error' => 'FTP port is not valid.'], 422);
            return;
        }
        if (!in_array($security, ['ftp', 'ftps'], true)) {
            $security = 'ftp';
        }
        if ($username === '' || strlen($username) > 200 || $password === '' || strlen($password) > 200) {
            $this->json(['error' => 'Username and password are required.'], 422);
            return;
        }
        $account = $this->db->saveFtpAccount([
            'project_id' => $projectId,
            'label' => $label,
            'host' => $host,
            'port' => $port,
            'security' => $security,
            'username' => $username,
            'password' => $password,
        ]);
        $project = $this->db->project($projectId);
        app_services()['memory']->remember('ftp', 'FTP "' . $label . '" ' . $username . '@' . $host . ':' . $port . ' for ' . (string)($project['name'] ?? 'a project') . '.');
        $this->json(['account' => $account]);
    }

    private function deleteFtpAccount(int $id): void
    {
        $this->db->deleteFtpAccount($id);
        $this->json(['ok' => true]);
    }

    private function mcpServers(): void
    {
        $this->json(['servers' => $this->db->mcpServers()]);
    }

    private function saveMcpServer(): void
    {
        $body = $this->input();
        $label = trim((string)($body['label'] ?? ''));
        $target = trim((string)($body['target'] ?? ''));
        if ($label === '' || strlen($label) > 80 || preg_match('/[\r\n]/', $label)) {
            $this->json(['error' => 'Name is required.'], 422);
            return;
        }
        foreach ($this->db->mcpServers() as $existing) {
            if (strtolower((string)$existing['label']) === strtolower($label)) {
                $this->json(['error' => 'An MCP server with that name already exists.'], 422);
                return;
            }
        }
        try {
            McpClient::parseTarget($target);
            $env = McpClient::parseEnv((string)($body['env'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $envJson = json_encode($env, JSON_UNESCAPED_SLASHES);
        $server = $this->db->saveMcpServer([
            'label' => $label,
            'target' => $target,
            'env_json' => $envJson === false ? '{}' : $envJson,
        ]);
        app_services()['memory']->remember('mcp', 'MCP server "' . $label . '" is available to the agents.');
        $this->json(['server' => $server]);
    }

    private function refreshMcpServer(int $id): void
    {
        $server = $this->db->mcpServer($id, true);
        if (!$server) {
            $this->json(['error' => 'MCP server not found.'], 404);
            return;
        }
        try {
            $tools = McpClient::listTools($server);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->db->saveMcpTools($id, $tools);
        $this->json(['server' => $this->db->mcpServer($id)]);
    }

    private function deleteMcpServer(int $id): void
    {
        $this->db->deleteMcpServer($id);
        $this->json(['ok' => true]);
    }

    private function deleteEmailAccount(int $id): void
    {
        $this->db->deleteEmailAccount($id);
        $this->json(['ok' => true]);
    }

    private function skills(): void
    {
        $this->json(['skills' => app_services()['skills']->sync()]);
    }

    private function saveSkill(): void
    {
        $body = $this->input();
        $previous = !empty($body['id']) ? $this->db->skill((int)$body['id']) : null;
        if ($previous && (string)($previous['created_by'] ?? '') === 'system') {
            $this->json(['error' => 'Built-in skills can only be turned on or off.'], 422);
            return;
        }
        try {
            $script = array_key_exists('script', $body) ? (string)$body['script'] : null;
            $skill = app_services()['skills']->saveSource(
                (string)($body['name'] ?? ''),
                (string)($body['description'] ?? ''),
                (string)($body['source'] ?? ''),
                !array_key_exists('is_active', $body) || !empty($body['is_active']),
                'user',
                true,
                $script,
                (string)($body['lang'] ?? '')
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        if ($previous && $previous['name'] !== $skill['name']) {
            app_services()['skills']->deleteOwned((int)$previous['id']);
        }
        $rows = app_services()['skills']->sync();
        foreach ($rows as $row) {
            if ((int)$row['id'] === (int)$skill['id']) {
                $skill = $row;
                break;
            }
        }
        $this->json(['skill' => $skill]);
    }

    private function toggleSkill(int $id): void
    {
        $skill = $this->db->skill($id);
        if (!$skill) {
            $this->json(['error' => 'Skill not found.'], 404);
            return;
        }
        $body = $this->input();
        $active = array_key_exists('active', $body) ? (bool)$body['active'] : !$skill['is_active'];
        $updated = $this->db->setSkillActive($id, $active);
        $this->json(['skill' => $updated]);
    }

    private function deleteSkill(int $id): void
    {
        try {
            app_services()['skills']->deleteOwned($id);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Skill not found.' ? 404 : 422;
            $this->json(['error' => $e->getMessage()], $status);
            return;
        }
        $this->json(['ok' => true]);
    }

    private function testSkill(int $id): void
    {
        $skill = $this->db->skill($id);
        if (!$skill) {
            $this->json(['error' => 'Skill not found.'], 404);
            return;
        }
        $body = $this->input();
        $args = $body['args'] ?? [];
        if (!is_array($args)) {
            $this->json(['error' => 'Args must be an object.'], 422);
            return;
        }
        $folder = 'skill_test';
        if (!empty($body['project_id'])) {
            $project = $this->db->project((int)$body['project_id']);
            if ($project) {
                $folder = $project['folder_name'];
            }
        }
        try {
            $result = app_services()['skills']->run($skill['name'], $args, $folder, true);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }
        $this->json($result);
    }

    private function setup(): void
    {
        $this->json(['settings' => $this->publicSettings(), 'hardware' => app_services()['hardware']->snapshot()]);
    }

    private function systemHealth(): void
    {
        $this->json(app_services()['hardware']->resourceUsage());
    }

    private function restartApp(): void
    {
        $this->db->stopAllChats();
        usleep(400000);
        (new Recovery($this->db))->releaseMemory();
        $this->db->setSetting('model_hold', '1');
        Recovery::reset($this->db);
        $this->db->setSetting('cpu_fallback', '0');
        $this->clearModelLoad();
        $warm = app_config()['data_dir'] . '/model-warm.json';
        if (is_file($warm)) {
            file_put_contents($warm, '');
        }
        $this->db->finishRunningJobs();
        foreach (array_keys(Database::idleStatus()) as $name) {
            $this->db->setAgentStatus($name, 'idle');
        }
        $this->db->unlock('subagent_lock');
        $this->db->unlock('consultant_lock');
        $this->scheduleRestart();
        $this->json(['ok' => true]);
    }

    private function scheduleRestart(): void
    {
        $root = dirname(__DIR__);
        $mode = '';
        $modeFile = $root . '/data/service.mode';
        if (is_file($modeFile)) {
            $mode = trim((string)file_get_contents($modeFile));
        }
        $inner = 'sleep 1; ' . $this->restartLine($root, $mode);
        $command = 'perl -MPOSIX -e ' . escapeshellarg('fork and exit; POSIX::setsid(); exec @ARGV or die $!')
            . ' /bin/sh -c ' . escapeshellarg($inner)
            . ' >> ' . escapeshellarg($root . '/data/restart.log') . ' 2>&1 &';
        exec($command);
    }

    private function restartLine(string $root, string $mode): string
    {
        $rootQ = escapeshellarg($root);
        $kick = '';
        if ($mode === 'launchdaemon') {
            $kick = 'sudo -n launchctl kickstart -k system/com.danaco.webui';
        } elseif ($mode === 'launchagent') {
            $kick = 'launchctl kickstart -k gui/$(id -u)/com.danaco.webui';
        } elseif ($mode === 'systemd') {
            $kick = 'sudo -n systemctl restart danaco.service';
        } elseif ($mode === 'systemd-user') {
            $kick = 'systemctl --user restart danaco.service';
        }
        $manual = 'if [ -f ' . $rootQ . '/data/server.pid ]; then kill "$(cat ' . $rootQ . '/data/server.pid)" 2>/dev/null || true; fi; '
            . 'if [ -f ' . $rootQ . '/data/tick.pid ]; then kill "$(cat ' . $rootQ . '/data/tick.pid)" 2>/dev/null || true; fi; '
            . 'for pid in $(pgrep -f ' . escapeshellarg($root . '/bin/run-agent.sh') . ' || true); do if [ "$pid" != "$$" ]; then kill "$pid" 2>/dev/null || true; fi; done; '
            . 'sleep 1; nohup /bin/bash ' . $rootQ . '/bin/run-agent.sh >> ' . $rootQ . '/data/service.log 2>&1 &';
        if ($kick === '') {
            return $manual;
        }
        return 'if ! ' . $kick . '; then ' . $manual . '; fi';
    }

    private function testProvider(): void
    {
        try {
            $reply = app_services()['llm']->stream([
                ['role' => 'system', 'content' => 'Reply briefly.'],
                ['role' => 'user', 'content' => 'just say hello'],
            ], static function (): void {
            }, null, null, true, 'setup-test');
            if (trim($reply) === '') {
                throw new RuntimeException('The external model returned an empty reply.');
            }
            $this->json(['ok' => true, 'message' => 'Success. The external model replied.']);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    private function updateStatus(): void
    {
        $this->json($this->readUpdate());
    }

    private function startUpdate(): void
    {
        $info = $this->readUpdate();
        if (empty($info['available'])) {
            $this->json(['error' => 'No update is available.'], 422);
            return;
        }
        if (($info['blocked'] ?? '') !== '') {
            $this->json(['error' => $info['blocked']], 422);
            return;
        }
        $root = dirname(__DIR__);
        $script = $root . '/bin/danaco';
        if (!is_file($script)) {
            $this->json(['error' => 'The update command is missing.'], 500);
            return;
        }
        $log = $root . '/data/update.log';
        $command = 'nohup /bin/bash ' . escapeshellarg($script) . ' update >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        exec($command);
        $this->json(['ok' => true]);
    }

    private function readUpdate(): array
    {
        $root = dirname(__DIR__);
        $none = ['available' => false, 'version' => $this->localVersion($root)];
        $inside = Process::run(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree'], $root, null, 5);
        if ($inside['code'] !== 0 || trim($inside['stdout']) !== 'true') {
            return $none;
        }
        $remoteUrl = Process::run(['git', '-C', $root, 'remote', 'get-url', 'origin'], $root, null, 5);
        $origin = trim($remoteUrl['stdout']);
        if ($remoteUrl['code'] !== 0 || !str_contains($origin, 'github.com')) {
            return $none;
        }
        $fetch = $this->fetchOrigin($root, $origin);
        $fetchError = $fetch['code'] === 0 ? '' : $this->fetchFailure($fetch);
        // AppImage installs clone installer-appimage and never fetch main.
        // Updates always come from main, whatever branch this copy is on.
        $branch = 'main';
        $main = Process::run(['git', '-C', $root, 'show-ref', '--verify', '--quiet', 'refs/remotes/origin/main'], $root, null, 5);
        if ($main['code'] !== 0) {
            $branch = 'master';
        }
        $localRun = Process::run(['git', '-C', $root, 'rev-parse', 'HEAD'], $root, null, 5);
        $remoteRun = Process::run(['git', '-C', $root, 'rev-parse', 'origin/' . $branch], $root, null, 5);
        $local = trim($localRun['stdout']);
        $remote = trim($remoteRun['stdout']);
        if (!preg_match('/^[0-9a-f]{40}$/', $local) || !preg_match('/^[0-9a-f]{40}$/', $remote)) {
            return $this->noUpdate($none, $fetchError);
        }
        $behindRun = Process::run(['git', '-C', $root, 'rev-list', '--count', $local . '..' . $remote], $root, null, 5);
        $behind = trim($behindRun['stdout']);
        if (!ctype_digit($behind) || (int)$behind === 0) {
            return $this->noUpdate($none, $fetchError);
        }
        $aheadRun = Process::run(['git', '-C', $root, 'rev-list', '--count', $remote . '..' . $local], $root, null, 5);
        $ahead = trim($aheadRun['stdout']);
        $localCount = $this->localVersion($root);
        $remoteCount = $this->fileVersionAt($root, 'origin/' . $branch);
        if ($localCount === '') {
            $localCount = substr($local, 0, 7);
        }
        if ($remoteCount === '') {
            $remoteCount = substr($remote, 0, 7);
        }
        $dirty = Process::run(['git', '-C', $root, 'status', '--porcelain', '--untracked-files=no'], $root, null, 5);
        $blocked = '';
        if (trim($dirty['stdout']) !== '') {
            $blocked = 'Local changes are present. Commit or stash them, then run danaco update again.';
        } elseif (ctype_digit($ahead) && (int)$ahead > 0) {
            $blocked = 'Local history has diverged from GitHub. Nothing will be overwritten.';
        }
        $web = '';
        if (preg_match('#github\.com[:/]([^/]+)/([^/\s.]+)#', $origin, $match)) {
            $web = 'https://github.com/' . $match[1] . '/' . $match[2];
        }
        return [
            'available' => true,
            'version' => $localCount,
            'current' => $localCount,
            'latest' => $remoteCount,
            'behind' => (int)$behind,
            'url' => $web !== '' ? $web . '/commit/' . $remote : '',
            'repo' => $web,
            'blocked' => $blocked,
        ];
    }

    private function localVersion(string $root): string
    {
        $fromFile = $this->readVersionFile($root . '/VERSION');
        if ($fromFile !== '') {
            return $fromFile;
        }
        $tag = Process::run(['git', '-C', $root, 'describe', '--tags', '--abbrev=0'], $root, null, 5);
        $label = trim($tag['stdout']);
        if ($tag['code'] === 0 && preg_match('/^v?(\d+(?:\.\d+){0,2})$/', $label, $match)) {
            return $match[1];
        }
        $run = Process::run(['git', '-C', $root, 'rev-parse', '--short=7', 'HEAD'], $root, null, 5);
        $hash = trim($run['stdout']);
        if ($run['code'] !== 0 || !preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
            return '';
        }
        return substr($hash, 0, 7);
    }

    private function fileVersionAt(string $root, string $ref): string
    {
        $run = Process::run(['git', '-C', $root, 'show', $ref . ':VERSION'], $root, null, 5);
        if ($run['code'] !== 0) {
            return '';
        }
        return $this->readVersionFileContents($run['stdout']);
    }

    private function readVersionFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }
        return $this->readVersionFileContents($raw);
    }

    private function readVersionFileContents(string $raw): string
    {
        $line = trim(strtok(str_replace("\r", '', $raw), "\n") ?: '');
        if ($line === '' || !preg_match('/^\d+(?:\.\d+){0,2}$/', $line)) {
            return '';
        }
        return $line;
    }

    private function noUpdate(array $none, string $fetchError): array
    {
        if ($fetchError !== '') {
            $none['error'] = $fetchError;
        }
        return $none;
    }

    private function fetchOrigin(string $root, string $origin): array
    {
        $env = $this->gitEnv();
        $command = ['git', '-C', $root, '-c', 'safe.directory=' . $root];
        $token = $this->githubToken($root, $origin);
        $askpass = $root . '/bin/git-askpass.sh';
        if ($token !== '' && is_executable($askpass)) {
            $command[] = '-c';
            $command[] = 'credential.helper=';
            $env['GIT_ASKPASS'] = $askpass;
            $env['SSH_ASKPASS'] = $askpass;
            $env['DANACO_GITHUB_TOKEN'] = $token;
        }
        $main = $command;
        array_push($main, 'fetch', 'origin', '+refs/heads/main:refs/remotes/origin/main');
        $fetched = Process::run($main, $root, null, 25, $env);
        if ($fetched['code'] === 0) {
            return $fetched;
        }
        $detail = strtolower((string)($fetched['stderr'] ?? '') . "\n" . (string)($fetched['stdout'] ?? ''));
        if (!str_contains($detail, 'remote ref') && !str_contains($detail, 'couldn\'t find remote ref')) {
            return $fetched;
        }
        array_push($command, 'fetch', 'origin', '+refs/heads/master:refs/remotes/origin/master');
        return Process::run($command, $root, null, 25, $env);
    }

    private function githubToken(string $root, string $origin): string
    {
        if (!preg_match('#^https?://#i', $origin)) {
            return '';
        }
        $file = $root . '/data/.github-credentials';
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $token = trim((string)file_get_contents($file, false, null, 0, 400));
        return $this->validGithubToken($token) ? $token : '';
    }

    private function validGithubToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]{20,300}$/', $token);
    }

    private function gitEnv(): array
    {
        $env = [];
        $source = getenv();
        if (!is_array($source)) {
            $source = [];
        }
        foreach ($source as $key => $value) {
            if (!is_string($key) || !is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $env[$key] = $value;
        }
        $path = $env['PATH'] ?? '/usr/bin:/bin';
        foreach (['/opt/homebrew/bin', '/usr/local/bin'] as $dir) {
            if (!str_contains(':' . $path . ':', ':' . $dir . ':')) {
                $path = $dir . ':' . $path;
            }
        }
        $env['PATH'] = $path;
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GCM_INTERACTIVE'] = 'Never';
        return $env;
    }

    private function fetchFailure(array $fetch): string
    {
        $err = strtolower((string)($fetch['stderr'] ?? '') . "\n" . (string)($fetch['stdout'] ?? ''));
        $again = ' Run danaco update in a terminal on this computer.';
        if ((int)($fetch['code'] ?? 1) === 124 || str_contains($err, 'timed out')) {
            return 'Checking GitHub took too long.' . $again;
        }
        if (str_contains($err, 'authentication') || str_contains($err, 'could not read username') || str_contains($err, '403') || str_contains($err, '401') || str_contains($err, 'terminal prompts disabled')) {
            return 'GitHub did not allow the update check.' . $again;
        }
        if (str_contains($err, 'dubious ownership') || str_contains($err, 'safe.directory')) {
            return 'Git will not update this folder from the background service.' . $again;
        }
        return 'Could not check GitHub for a new version.' . $again;
    }

    private function saveSetup(): void
    {
        $body = $this->input();
        $previousMode = (string)$this->db->getSetting('model_mode', '');
        $previousLocal = (string)$this->db->getSetting('local_model', '');
        if (array_key_exists('model_mode', $body)) {
            $mode = (string)$body['model_mode'];
            if (!in_array($mode, ['cloud', 'local', 'cpu_failsafe'], true)) {
                $this->json(['error' => 'Unknown model mode.'], 422);
                return;
            }
            $previous = (string)$this->db->getSetting('model_mode', '');
            $this->db->setSetting('model_mode', $mode);
            if ($mode !== $previous && $mode !== 'cpu_failsafe') {
                $this->db->setSetting('cpu_fallback', '0');
            }
        }
        if (array_key_exists('cloud_api_use', $body)) {
            $use = (string)$body['cloud_api_use'];
            if (!in_array($use, ['', 'main', 'consultant'], true)) {
                $this->json(['error' => 'Choose whether the API is for the main agent or the Consultant.'], 422);
                return;
            }
            $this->db->setSetting('cloud_api_use', $use);
        }
        $requestedMode = array_key_exists('model_mode', $body) ? (string)$body['model_mode'] : null;
        $requestedUse = array_key_exists('cloud_api_use', $body) ? (string)$body['cloud_api_use'] : null;
        if ($requestedMode === 'cloud' && ($requestedUse === null || $requestedUse === 'main')) {
            $this->db->setSetting('cloud_api_use', 'main');
        } elseif (in_array($requestedUse, ['', 'consultant'], true)
            || ($requestedUse === null && in_array((string)$this->db->getSetting('cloud_api_use', 'main'), ['', 'consultant'], true))) {
            if ((string)$this->db->getSetting('model_mode', '') === 'cloud') {
                $this->db->setSetting('model_mode', 'local');
            }
        }
        foreach (['ollama_url', 'cloud_base_url'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $url = trim((string)$body[$key]);
            if (!preg_match('#^https?://\S+$#', $url)) {
                $this->json(['error' => 'Enter an http or https URL for ' . $key . '.'], 422);
                return;
            }
            $this->db->setSetting($key, rtrim($url, '/'));
        }
        foreach (['local_model', 'cpu_failsafe_model', 'cloud_model'] as $key) {
            if (array_key_exists($key, $body)) {
                $value = trim((string)$body[$key]);
                if ($value === '' || strlen($value) > 120 || str_contains($value, "\n")) {
                    $this->json(['error' => 'Invalid model name.'], 422);
                    return;
                }
                $this->db->setSetting($key, $value);
            }
        }
        if (!empty($body['clear_cloud_api_key'])) {
            $this->db->setSetting('cloud_api_key', '');
        } elseif (array_key_exists('cloud_api_key', $body)) {
            $this->db->setSetting('cloud_api_key', $this->keepSecret((string)$body['cloud_api_key'], (string)$this->db->getSetting('cloud_api_key', '')));
        }
        if (array_key_exists('cloud_max_turns', $body)) {
            $turns = (int)$body['cloud_max_turns'];
            $this->db->setSetting('cloud_max_turns', (string)max(10, min(500, $turns)));
        }
        if (array_key_exists('chat_font_size', $body)) {
            $size = (int)$body['chat_font_size'];
            if ($size < 13 || $size > 22) {
                $this->json(['error' => 'Chat text size must be between 13 and 22.'], 422);
                return;
            }
            $this->db->setSetting('chat_font_size', (string)$size);
        }
        if (array_key_exists('ai_act_applies', $body)) {
            $value = (string)$body['ai_act_applies'];
            if (!in_array($value, ['', 'yes', 'no', 'unsure'], true)) {
                $this->json(['error' => 'Choose yes, no, or not sure for EU AI Act applicability.'], 422);
                return;
            }
            $this->db->setSetting('ai_act_applies', $value);
        }
        $modeNow = (string)$this->db->getSetting('model_mode', '');
        $localNow = (string)$this->db->getSetting('local_model', '');
        if ($localNow !== '' && $localNow !== $previousLocal) {
            $this->db->setSetting('gpu_split_model', '');
        }
        if ($modeNow === 'local' && $localNow !== '' && ($modeNow !== $previousMode || $localNow !== $previousLocal)) {
            $this->db->setSetting('model_hold', '0');
            $this->clearModelLoad();
            app_services()['llm']->releaseOthers($localNow);
        }
        $this->json(['settings' => $this->publicSettings(), 'hardware' => app_services()['hardware']->snapshot()]);
    }

    private function changePassword(): void
    {
        $body = $this->input();
        $current = (string)($body['current'] ?? '');
        $next = (string)($body['next'] ?? '');
        $confirm = (string)($body['confirm'] ?? '');
        $hash = (string)$this->db->getSetting('password_hash', '');
        if ($hash === '' || !password_verify($current, $hash)) {
            $this->json(['error' => 'Current password is wrong.'], 401);
            return;
        }
        if (strlen($next) < 8 || strlen($next) > 200) {
            $this->json(['error' => 'Use at least 8 characters.'], 422);
            return;
        }
        if (!hash_equals($next, $confirm)) {
            $this->json(['error' => 'New passwords do not match.'], 422);
            return;
        }
        $this->db->setSetting('password_hash', password_hash($next, PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $this->json(['ok' => true]);
    }

    private function setSystemControl(): void
    {
        $body = $this->input();
        $enabled = !empty($body['enabled']);
        if ($enabled) {
            $password = (string)($body['password'] ?? '');
            $hash = (string)$this->db->getSetting('password_hash', '');
            if ($hash === '' || !password_verify($password, $hash)) {
                $this->json(['error' => 'Wrong password.'], 422);
                return;
            }
            $error = $this->enableSudo($password);
            if ($error !== null) {
                $this->json(['error' => $error], 422);
                return;
            }
            $this->db->setSetting('full_system_control', '1');
        } else {
            $this->disableSudo();
            $this->db->setSetting('full_system_control', '0');
        }
        $this->json(['settings' => $this->publicSettings(), 'hardware' => app_services()['hardware']->snapshot()]);
    }

    private function enableSudo(string $password): ?string
    {
        $user = $this->currentUser();
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,31}$/', $user)) {
            return 'Could not tell which user is running the agent.';
        }
        $sudo = is_file('/usr/bin/sudo') ? '/usr/bin/sudo' : 'sudo';
        $already = Process::run([$sudo, '-k', '-n', 'true'], null, null, 8);
        if ($already['code'] === 0) {
            $this->db->setSetting('full_system_sudoers', '0');
            return null;
        }
        $check = Process::run([$sudo, '-S', '-k', '-p', '', '-v'], null, $password . "\n", 15);
        if ($check['code'] !== 0) {
            return 'The computer did not accept that password for administrator commands.';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'danaco-sudo-');
        if ($tmp === false) {
            return 'Could not prepare administrator access.';
        }
        $rule = "Defaults:" . $user . " !requiretty\n" . $user . " ALL=(ALL) NOPASSWD: ALL\n";
        file_put_contents($tmp, $rule);
        chmod($tmp, 0440);
        $visudo = is_file('/usr/sbin/visudo') ? '/usr/sbin/visudo' : 'visudo';
        $checked = Process::run([$sudo, '-n', $visudo, '-cf', $tmp], null, null, 10);
        if ($checked['code'] !== 0) {
            @unlink($tmp);
            return 'Could not prepare administrator access.';
        }
        $dest = '/etc/sudoers.d/danaco';
        $copy = Process::run([$sudo, '-n', 'cp', $tmp, $dest], null, null, 10);
        @unlink($tmp);
        if ($copy['code'] !== 0) {
            return 'Could not turn on administrator commands.';
        }
        Process::run([$sudo, '-n', 'chmod', '440', $dest], null, null, 8);
        $confirm = Process::run([$sudo, '-k', '-n', 'true'], null, null, 8);
        if ($confirm['code'] !== 0) {
            Process::run([$sudo, '-n', 'rm', '-f', $dest], null, null, 8);
            return 'Administrator commands are still asking for a password.';
        }
        $this->db->setSetting('full_system_sudoers', '1');
        return null;
    }

    private function disableSudo(): void
    {
        if ($this->db->getSetting('full_system_sudoers', '0') !== '1') {
            return;
        }
        $sudo = is_file('/usr/bin/sudo') ? '/usr/bin/sudo' : 'sudo';
        Process::run([$sudo, '-n', 'rm', '-f', '/etc/sudoers.d/danaco'], null, null, 8);
        $this->db->setSetting('full_system_sudoers', '0');
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name']) && is_string($info['name'])) {
                return $info['name'];
            }
        }
        $result = Process::run(['id', '-un'], null, null, 5);
        return trim($result['stdout']);
    }

    private function ollamaModels(): void
    {
        $result = $this->ollamaRequest('GET', '/api/tags', null, 8);
        if ($result['error'] !== null) {
            $this->json(['models' => [], 'error' => $result['error']]);
            return;
        }
        $models = [];
        $llm = app_services()['llm'];
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $active = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $loaded = [];
        foreach ($llm->runningModels() as $live) {
            $loaded[] = $live;
        }
        foreach (($result['json']['models'] ?? []) as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $size = (int)($row['size'] ?? 0);
            $name = (string)$row['name'];
            $place = '';
            foreach ($loaded as $live) {
                if ($llm->modelMatches((string)$live['name'], $name)) {
                    $place = (string)$live['place'];
                    break;
                }
            }
            $models[] = [
                'name' => $name,
                'size_label' => $this->formatBytes($size),
                'modified' => isset($row['modified_at']) ? substr((string)$row['modified_at'], 0, 10) : '',
                'parameter_size' => (string)($row['details']['parameter_size'] ?? ''),
                'protected' => $this->isProtectedModel($name),
                'place' => $place,
                'selected' => $active !== '' && $llm->modelMatches($name, $active),
            ];
        }
        $this->json(['models' => $models, 'error' => null]);
    }

    private function deleteModel(): void
    {
        $name = $this->modelName((string)($this->input()['name'] ?? ''));
        if ($name === null) {
            $this->json(['error' => 'Enter a model name such as qwen3.6.'], 422);
            return;
        }
        if ($this->isProtectedModel($name)) {
            $this->json(['error' => 'The CPU failsafe model stays installed.'], 422);
            return;
        }
        $result = $this->ollamaRequest('DELETE', '/api/delete', ['model' => $name], 60);
        if ($result['error'] !== null) {
            $this->json(['error' => $result['error']], 502);
            return;
        }
        $this->json(['ok' => true]);
    }

    private function pullModel(): void
    {
        $target = self::pullTarget((string)($this->input()['name'] ?? ''));
        if (isset($target['error'])) {
            $this->json(['error' => $target['error']], 422);
            return;
        }
        $base = $this->ollamaBase();
        if ($base === null) {
            $this->json(['error' => 'The Ollama URL in Setup is not valid.'], 422);
            return;
        }
        @set_time_limit(0);
        session_write_close();
        $this->beginSse();
        if (isset($target['file'])) {
            $this->pullHuggingFaceFile($base, $target['file']);
            return;
        }
        $this->pullNamedModel($base, $target['name']);
    }

    private function pullNamedModel(string $base, string $name): void
    {
        $failed = null;
        $buffer = '';
        $take = function (string $line) use (&$failed): bool {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                return true;
            }
            if (!empty($row['error'])) {
                $failed = (string)$row['error'];
                return false;
            }
            $this->sse('progress', [
                'status' => (string)($row['status'] ?? 'Working'),
                'completed' => isset($row['completed']) ? (int)$row['completed'] : null,
                'total' => isset($row['total']) ? (int)$row['total'] : null,
            ]);
            return true;
        };
        $ch = curl_init($base . '/api/pull');
        if ($ch === false) {
            $this->sse('error', ['error' => 'Could not reach Ollama.']);
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $name, 'name' => $name, 'stream' => true]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, $take): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '' && !$take($line)) {
                        return 0;
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($buffer !== '' && $failed === null) {
            $take(trim($buffer));
        }
        if ($failed !== null) {
            $this->sse('error', ['error' => $failed]);
            return;
        }
        if ($ok === false || $errno !== 0 || $code >= 400) {
            $this->sse('error', ['error' => 'Could not download the model.']);
            return;
        }
        $this->sse('done', ['name' => $name]);
    }

    // A file at the root of main is hf.co/user/repo:file.gguf. A file in a folder
    // keeps that folder in front of the name, which Ollama cannot use as a tag,
    // so that file is fetched from Hugging Face and registered locally.
    private static function pullTarget(string $raw): array
    {
        $raw = trim($raw);
        $invalid = 'Enter a model name such as qwen3.6, or a Hugging Face link to a GGUF file.';
        if ($raw === '' || str_contains($raw, "\n") || str_contains($raw, "\r") || strlen($raw) > 500) {
            return ['error' => $invalid];
        }
        if (preg_match('#^https?://#i', $raw)) {
            return self::pullTargetFromUrl($raw);
        }
        if (preg_match('#^([A-Za-z0-9.-]+)/([^/]+)/([^/:]+):(.+)$#', $raw, $match)) {
            $host = strtolower($match[1]);
            if (in_array($host, ['hf.co', 'huggingface.co', 'hf-mirror.com'], true)) {
                $registry = $host === 'hf-mirror.com' ? 'hf-mirror.com' : 'hf.co';
                $downloadHost = $host === 'hf-mirror.com' ? 'hf-mirror.com' : 'huggingface.co';
                $tag = $match[4];
                if (preg_match('#^(main|master)/(.+)$#i', $tag, $revision)) {
                    return self::fromRepoFile($registry, $downloadHost, $match[2], $match[3], $revision[1], $revision[2]);
                }
                if (str_contains($tag, '/')) {
                    return self::fromRepoFile($registry, $downloadHost, $match[2], $match[3], 'main', $tag);
                }
                $name = $registry . '/' . $match[2] . '/' . $match[3] . ':' . $tag;
                if (!self::ollamaNameOk($name)) {
                    return ['error' => 'That model name is not one Ollama can install.'];
                }
                return ['name' => $name];
            }
        }
        if (!self::ollamaNameOk($raw)) {
            return ['error' => $invalid];
        }
        return ['name' => $raw];
    }

    private static function pullTargetFromUrl(string $raw): array
    {
        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return ['error' => 'That link is not a Hugging Face model.'];
        }
        $host = strtolower((string)$parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (!in_array($host, ['huggingface.co', 'hf.co', 'hf-mirror.com'], true)) {
            return ['error' => 'Paste a Hugging Face link, or a model name such as qwen3.6.'];
        }
        $registry = $host === 'hf-mirror.com' ? 'hf-mirror.com' : 'hf.co';
        $downloadHost = $host === 'hf.co' ? 'huggingface.co' : $host;
        $path = rawurldecode((string)$parts['path']);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
        if (count($segments) < 2 || !self::hfName($segments[0]) || !self::hfName($segments[1])) {
            return ['error' => 'That link is not a Hugging Face model.'];
        }
        $user = $segments[0];
        $repo = $segments[1];
        if (count($segments) === 2) {
            $name = $registry . '/' . $user . '/' . $repo;
            if (!self::ollamaNameOk($name)) {
                return ['error' => 'That model name is not one Ollama can install.'];
            }
            return ['name' => $name];
        }
        $kind = strtolower($segments[2]);
        if (!in_array($kind, ['resolve', 'blob', 'raw', 'tree'], true) || count($segments) < 4 || !self::hfRevision($segments[3])) {
            return ['error' => 'That link is not a Hugging Face model file.'];
        }
        if ($kind === 'tree') {
            $name = $registry . '/' . $user . '/' . $repo;
            if (!self::ollamaNameOk($name)) {
                return ['error' => 'That model name is not one Ollama can install.'];
            }
            return ['name' => $name];
        }
        if (count($segments) < 5) {
            return ['error' => 'That link does not point at a GGUF file.'];
        }
        $file = implode('/', array_slice($segments, 4));
        if (!str_ends_with(strtolower($file), '.gguf')) {
            return ['error' => 'That link does not point at a GGUF file.'];
        }
        return self::fromRepoFile($registry, $downloadHost, $user, $repo, $segments[3], $file);
    }

    private static function fromRepoFile(string $registry, string $downloadHost, string $user, string $repo, string $revision, string $file): array
    {
        if (!self::hfName($user) || !self::hfName($repo) || !self::hfRevision($revision)) {
            return ['error' => 'That link is not a Hugging Face model file.'];
        }
        $file = str_replace('\\', '/', $file);
        if (self::encodeHfPath($file) === '') {
            return ['error' => 'That link is not a Hugging Face model file.'];
        }
        if (self::splitGguf($file)) {
            return ['error' => 'Ollama cannot install one piece of a split GGUF. Choose a single file.'];
        }
        $tag = $file;
        if (!in_array(strtolower($revision), ['main', 'master'], true)) {
            $tag = $revision . '/' . $file;
        }
        if (!str_contains($tag, '/')) {
            $name = $registry . '/' . $user . '/' . $repo . ':' . $tag;
            if (self::ollamaNameOk($name)) {
                return ['name' => $name];
            }
        }
        $short = self::shortModelName($file);
        if ($short === null) {
            return ['error' => 'That file name cannot be installed.'];
        }
        $encodedRevision = self::encodeHfPath($revision);
        $encodedFile = self::encodeHfPath($file);
        if ($encodedRevision === '' || $encodedFile === '') {
            return ['error' => 'That link is not a Hugging Face model file.'];
        }
        return ['file' => [
            'host' => $downloadHost,
            'user' => $user,
            'repo' => $repo,
            'tag' => $tag,
            'model' => $short,
            'resolve' => 'https://' . $downloadHost . '/' . $user . '/' . $repo . '/resolve/' . $encodedRevision . '/' . $encodedFile,
        ]];
    }

    private function pullHuggingFaceFile(string $base, array $file): void
    {
        $manifest = $this->huggingfaceManifest($file);
        if (isset($manifest['error']) && !str_contains(strtolower($manifest['error']), 'split') && !str_contains(strtolower($manifest['error']), 'shard')) {
            $this->pullResolvedGguf($base, $file, $manifest['error']);
            return;
        }
        if (isset($manifest['error'])) {
            $this->sse('error', ['error' => $manifest['error']]);
            return;
        }
        $layers = $manifest['layers'];
        $total = 0;
        foreach ($layers as $layer) {
            $total += (int)$layer['size'];
        }
        $files = [];
        $template = '';
        $parameters = null;
        $done = 0;
        $temps = [];
        foreach ($layers as $layer) {
            $digest = (string)$layer['digest'];
            $kind = (string)$layer['kind'];
            $size = (int)$layer['size'];
            if ($kind === 'template' || $kind === 'params') {
                $body = $this->downloadString($this->blobUrl($file, $digest), $size, $done, $total);
                $done += $size;
                if ($body === null) {
                    $this->sse('error', ['error' => 'Could not download the model.']);
                    $this->removeTempFiles($temps);
                    return;
                }
                if ($kind === 'template' && preg_match('//u', $body)) {
                    $template = $body;
                }
                if ($kind === 'params') {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && !array_is_list($decoded)) {
                        $parameters = $decoded;
                    }
                }
                continue;
            }
            $temp = tempnam(sys_get_temp_dir(), 'danaco-gguf-');
            if ($temp === false) {
                $this->sse('error', ['error' => 'Could not prepare the download.']);
                $this->removeTempFiles($temps);
                return;
            }
            $temps[] = $temp;
            $saved = $this->downloadFile($this->blobUrl($file, $digest), $temp, $size, $done, $total);
            $done += $size;
            if ($saved !== null) {
                $this->sse('error', ['error' => $saved]);
                $this->removeTempFiles($temps);
                return;
            }
            $hash = hash_file('sha256', $temp);
            if ($hash === false || !hash_equals(substr($digest, 7), $hash)) {
                $this->sse('error', ['error' => 'The download did not match Hugging Face.']);
                $this->removeTempFiles($temps);
                return;
            }
            $stored = $this->ollamaStoreBlob($base, $digest, $temp);
            if ($stored !== null) {
                $this->sse('error', ['error' => $stored]);
                $this->removeTempFiles($temps);
                return;
            }
            $files[$kind === 'projector' ? 'mmproj.gguf' : basename((string)$file['tag'])] = $digest;
        }
        $this->removeTempFiles($temps);
        if ($files === []) {
            $this->sse('error', ['error' => 'Hugging Face did not offer a model file.']);
            return;
        }
        $this->ollamaCreate($base, (string)$file['model'], $files, $template, $parameters);
    }

    private function pullResolvedGguf(string $base, array $file, string $manifestError): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'danaco-gguf-');
        if ($temp === false) {
            $this->sse('error', ['error' => 'Could not prepare the download.']);
            return;
        }
        $saved = $this->downloadFile((string)$file['resolve'], $temp, 0, 0, 0);
        if ($saved !== null) {
            @unlink($temp);
            $this->sse('error', ['error' => $manifestError !== '' ? $manifestError : $saved]);
            return;
        }
        $hash = hash_file('sha256', $temp);
        if ($hash === false) {
            @unlink($temp);
            $this->sse('error', ['error' => 'Could not check the download.']);
            return;
        }
        $digest = 'sha256:' . $hash;
        $stored = $this->ollamaStoreBlob($base, $digest, $temp);
        @unlink($temp);
        if ($stored !== null) {
            $this->sse('error', ['error' => $stored]);
            return;
        }
        $this->ollamaCreate($base, (string)$file['model'], [basename((string)$file['resolve']) => $digest], '', null);
    }

    private function huggingfaceManifest(array $file): array
    {
        $tag = self::encodeHfPath((string)$file['tag']);
        if ($tag === '') {
            return ['error' => 'That link is not a Hugging Face model file.'];
        }
        $url = 'https://' . $file['host'] . '/v2/' . $file['user'] . '/' . $file['repo'] . '/manifests/' . $tag;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'Could not reach Hugging Face.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.docker.distribution.manifest.v2+json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($raw === false || $errno !== 0) {
            return ['error' => 'Could not reach Hugging Face.'];
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            return ['error' => 'Hugging Face did not return the model.'];
        }
        if ($code >= 400 || !empty($json['error'])) {
            $message = isset($json['error']) ? (string)$json['error'] : 'Hugging Face did not return the model.';
            return ['error' => $message];
        }
        $layers = [];
        foreach (($json['layers'] ?? []) as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $type = (string)($layer['mediaType'] ?? '');
            $digest = (string)($layer['digest'] ?? '');
            if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
                continue;
            }
            $kind = '';
            if (str_contains($type, 'image.model')) {
                $kind = 'model';
            } elseif (str_contains($type, 'projector')) {
                $kind = 'projector';
            } elseif (str_contains($type, 'template')) {
                $kind = 'template';
            } elseif (str_contains($type, 'params')) {
                $kind = 'params';
            }
            if ($kind === '') {
                continue;
            }
            $layers[] = [
                'kind' => $kind,
                'digest' => $digest,
                'size' => max(0, (int)($layer['size'] ?? 0)),
            ];
        }
        $hasModel = false;
        foreach ($layers as $layer) {
            if ($layer['kind'] === 'model') {
                $hasModel = true;
                break;
            }
        }
        if (!$hasModel) {
            return ['error' => 'Hugging Face did not offer a model file.'];
        }
        return ['layers' => $layers];
    }

    private function blobUrl(array $file, string $digest): string
    {
        return 'https://' . $file['host'] . '/v2/' . $file['user'] . '/' . $file['repo'] . '/blobs/' . $digest;
    }

    private function downloadFile(string $url, string $dest, int $size, int $done, int $total): ?string
    {
        $out = fopen($dest, 'wb');
        if ($out === false) {
            return 'Could not prepare the download.';
        }
        $last = 0;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($out);
            return 'Could not download the model.';
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $out,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $downloadTotal, $downloaded) use (&$last, $done, $total, $size): int {
                $downloaded = (int)$downloaded;
                if ($downloaded < $last + 1048576 && ($size <= 0 || $downloaded < $size)) {
                    return 0;
                }
                $last = $downloaded;
                $overall = $total > 0 ? $total : max($size, (int)$downloadTotal);
                if ($overall > 0) {
                    $this->sse('progress', [
                        'status' => 'Downloading',
                        'completed' => $done + $downloaded,
                        'total' => $overall,
                    ]);
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        fclose($out);
        if ($ok === false || $errno !== 0 || $code >= 400) {
            return 'Could not download the model.';
        }
        return null;
    }

    private function downloadString(string $url, int $size, int $done, int $total): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($raw === false || $errno !== 0 || $code >= 400) {
            return null;
        }
        if ($total > 0) {
            $this->sse('progress', [
                'status' => 'Downloading',
                'completed' => $done + $size,
                'total' => $total,
            ]);
        }
        return (string)$raw;
    }

    private function ollamaStoreBlob(string $base, string $digest, string $path): ?string
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            return 'Could not store the model.';
        }
        $head = curl_init($base . '/api/blobs/' . $digest);
        if ($head !== false) {
            curl_setopt_array($head, [
                CURLOPT_NOBODY => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($head);
            $headCode = (int)curl_getinfo($head, CURLINFO_HTTP_CODE);
            unset($head);
            if ($headCode === 200) {
                return null;
            }
        }
        $in = fopen($path, 'rb');
        if ($in === false) {
            return 'Could not store the model.';
        }
        $size = filesize($path);
        $ch = curl_init($base . '/api/blobs/' . $digest);
        if ($ch === false) {
            fclose($in);
            return 'Could not reach Ollama.';
        }
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $in,
            CURLOPT_INFILESIZE => $size === false ? 0 : $size,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        fclose($in);
        if ($ok === false || $errno !== 0 || $code >= 400) {
            return 'Could not store the model in Ollama.';
        }
        return null;
    }

    private function ollamaCreate(string $base, string $model, array $files, string $template, ?array $parameters): void
    {
        $body = [
            'model' => $model,
            'name' => $model,
            'files' => $files,
            'stream' => true,
        ];
        if ($template !== '') {
            $body['template'] = $template;
        }
        if ($parameters !== null) {
            $body['parameters'] = $parameters;
        }
        $failed = null;
        $buffer = '';
        $take = function (string $line) use (&$failed): bool {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                return true;
            }
            if (!empty($row['error'])) {
                $failed = (string)$row['error'];
                return false;
            }
            if (!empty($row['status'])) {
                $this->sse('progress', ['status' => (string)$row['status']]);
            }
            return true;
        };
        $ch = curl_init($base . '/api/create');
        if ($ch === false) {
            $this->sse('error', ['error' => 'Could not reach Ollama.']);
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, $take): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '' && !$take($line)) {
                        return 0;
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($buffer !== '' && $failed === null) {
            $take(trim($buffer));
        }
        if ($failed !== null) {
            $this->sse('error', ['error' => $failed]);
            return;
        }
        if ($ok === false || $errno !== 0 || $code >= 400) {
            $this->sse('error', ['error' => 'Could not install the model.']);
            return;
        }
        $this->sse('done', ['name' => $model]);
    }

    private function removeTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                @unlink($path);
            }
        }
    }

    private static function hfName(string $part): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,95}$/', $part);
    }

    private static function hfRevision(string $part): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $part);
    }

    private static function encodeHfPath(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return '';
            }
            $out[] = rawurlencode($part);
        }
        return implode('/', $out);
    }

    private static function splitGguf(string $file): bool
    {
        return (bool)preg_match('/-\d+-of-\d+\.gguf$/i', basename($file));
    }

    private static function shortModelName(string $file): ?string
    {
        $base = basename(str_replace('\\', '/', $file));
        if (str_ends_with(strtolower($base), '.gguf')) {
            $base = substr($base, 0, -5);
        }
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $base) ?? '';
        $base = trim($base, '.-');
        if ($base === '' || !preg_match('/^[A-Za-z0-9_]/', $base)) {
            $base = 'm-' . $base;
        }
        if (strlen($base) > 80) {
            $base = rtrim(substr($base, 0, 80), '.-');
        }
        if (!self::ollamaPart($base, 'model')) {
            return null;
        }
        return $base;
    }

    private static function ollamaNameOk(string $name): bool
    {
        if ($name === '' || strlen($name) > 500) {
            return false;
        }
        $rest = $name;
        $colon = strrpos($name, ':');
        $slash = strrpos($name, '/');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $tag = substr($name, $colon + 1);
            $rest = substr($name, 0, $colon);
            if (!self::ollamaPart($tag, 'tag')) {
                return false;
            }
        } elseif ($colon !== false) {
            return false;
        }
        if ($rest === '') {
            return false;
        }
        $bits = explode('/', $rest);
        $count = count($bits);
        if ($count < 1 || $count > 3) {
            return false;
        }
        $kinds = $count === 1 ? ['model'] : ($count === 2 ? ['namespace', 'model'] : ['host', 'namespace', 'model']);
        foreach ($bits as $index => $bit) {
            if (!self::ollamaPart($bit, $kinds[$index])) {
                return false;
            }
        }
        return true;
    }

    private static function ollamaPart(string $part, string $kind): bool
    {
        $max = $kind === 'host' ? 350 : 80;
        if ($part === '' || strlen($part) > $max || !preg_match('/^[A-Za-z0-9_]/', $part)) {
            return false;
        }
        if ($kind === 'namespace') {
            return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]*$/', $part);
        }
        if ($kind === 'host') {
            return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:-]*$/', $part);
        }
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $part);
    }

    private function modelLoad(): void
    {
        $status = $this->modelLoadStatus();
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $status['cpu'] = $mode === 'cpu_failsafe' || $this->db->getSetting('cpu_fallback', '0') === '1';
        $this->json($status);
    }

    private function modelLoadStatus(): array
    {
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if ($mode === 'cloud') {
            return [
                'ready' => true,
                'percent' => 100,
                'model' => (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']),
                'error' => '',
                'scratch' => false,
            ];
        }
        $model = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $model = $this->modelName($model);
        if ($model === null) {
            return ['ready' => false, 'percent' => 0, 'model' => '', 'error' => 'No local model is selected.', 'scratch' => true];
        }
        $running = $this->ollamaModelRunning($model);
        if ($running === true && $mode === 'local') {
            $place = app_services()['llm']->placement($model);
            if ($place === 'gpu') {
                $this->db->setSetting('cpu_fallback', '0');
            } elseif ($place === 'cpu') {
                $this->db->setSetting('cpu_fallback', '1');
                $tried = (int)$this->db->getSetting('gpu_reload_at', '0');
                if (!$this->db->modelInUse() && time() - $tried >= 180) {
                    $this->db->setSetting('gpu_reload_at', (string)time());
                    app_services()['llm']->releaseModel($model);
                    $running = false;
                }
            }
        }
        if ($running === true) {
            $this->db->setSetting('model_hold', '0');
            $this->rememberModelWarm($model);
            $this->clearModelLoad();
            return ['ready' => true, 'percent' => 100, 'model' => $model, 'error' => '', 'scratch' => false];
        }
        if ($this->db->getSetting('model_hold', '0') === '1') {
            return ['ready' => false, 'percent' => 0, 'model' => $model, 'error' => '', 'scratch' => false];
        }
        $warm = $this->modelWarm($model);
        $busy = $this->db->modelInUse();
        // /api/ps timed out, or the model dropped while a reply is already running.
        // A second load restarts the runner and cuts that reply off.
        if ($busy || ($running === null && $warm)) {
            return ['ready' => true, 'percent' => 100, 'model' => $model, 'error' => '', 'scratch' => false];
        }
        $state = $this->ensureModelLoading($model);
        $error = (string)($state['error'] ?? '');
        // Gone between messages. Load it again, but keep the bar off. The chat waits.
        if ($warm && $error === '') {
            return [
                'ready' => false,
                'percent' => (int)($state['shown'] ?? 0),
                'model' => $model,
                'error' => '',
                'scratch' => false,
            ];
        }
        return [
            'ready' => false,
            'percent' => (int)($state['shown'] ?? 0),
            'model' => $model,
            'error' => $error,
            'scratch' => true,
        ];
    }

    private function ollamaModelRunning(string $model): ?bool
    {
        $result = $this->ollamaRequest('GET', '/api/ps', null, 4);
        if ($result['error'] !== null) {
            return null;
        }
        foreach ($result['json']['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string)($row['name'] ?? $row['model'] ?? '');
            if ($name === $model || $name === $model . ':latest') {
                return true;
            }
        }
        return false;
    }

    private function ensureModelLoading(string $model): array
    {
        $path = app_config()['data_dir'] . '/model-load.json';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            return ['shown' => 0, 'error' => 'Could not prepare the model loader.'];
        }
        flock($fh, LOCK_EX);
        $state = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($state) || ($state['model'] ?? '') !== $model) {
            $state = $this->startModelLoad($model, is_array($state) ? $state : null);
        } elseif ($this->pidRunning((int)($state['pid'] ?? 0))) {
            $state = $this->tickModelLoad($state);
        } elseif ((int)($state['pid'] ?? 0) > 0 && (int)($state['retry_at'] ?? 0) === 0) {
            $state['pid'] = 0;
            $error = $this->modelLoadError();
            if (empty($state['split']) && app_services()['llm']->gpuOverflow($error)) {
                app_services()['llm']->keepSplit($model);
                $state = $this->startModelLoad($model, $state);
                $state['split'] = 1;
            } else {
                $state['error'] = $error;
                $state['retry_at'] = time() + 12;
            }
        } elseif (empty($state['split']) && app_services()['llm']->gpuOverflow((string)($state['error'] ?? ''))) {
            app_services()['llm']->keepSplit($model);
            $state = $this->startModelLoad($model, $state);
            $state['split'] = 1;
        } elseif (time() >= (int)($state['retry_at'] ?? 0)) {
            $state = $this->startModelLoad($model, $state);
        }
        $encoded = json_encode($state);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $encoded === false ? '{}' : $encoded);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $state;
    }

    private function startModelLoad(string $model, ?array $previous): array
    {
        $shown = (int)($previous['shown'] ?? 0);
        $blank = [
            'model' => $model,
            'started' => time(),
            'baseline' => 0,
            'size' => 0,
            'shown' => $shown,
            'pid' => 0,
            'error' => '',
            'retry_at' => time() + 12,
        ];
        if ((string)$this->db->getSetting('model_mode', '') === 'local' && !app_services()['llm']->releaseOthers($model)) {
            $blank['error'] = 'The previous model is still on the GPU.';
            $blank['retry_at'] = time() + 5;
            return $blank;
        }
        $base = $this->ollamaBase();
        if ($base === null) {
            $blank['error'] = 'The Ollama URL in Setup is not valid.';
            return $blank;
        }
        $show = $this->ollamaRequest('POST', '/api/show', ['name' => $model], 8);
        if ($show['error'] !== null) {
            $blank['error'] = $show['error'];
            return $blank;
        }
        if (!Process::commandExists('curl')) {
            $blank['error'] = 'Could not start loading the model.';
            return $blank;
        }
        $options = ['num_predict' => 1];
        if ((string)$this->db->getSetting('model_mode', '') === 'local') {
            $wanted = app_services()['hardware']->effectiveContext('local');
            $gpu = app_services()['llm']->gpuOptions($model, $wanted);
            if ($gpu !== []) {
                $options = array_merge($options, $gpu);
            }
        }
        $payload = json_encode([
            'model' => $model,
            'prompt' => 'hi',
            'stream' => true,
            'keep_alive' => '1h',
            'options' => $options,
        ]);
        $log = app_config()['data_dir'] . '/model-load.log';
        $cmd = 'curl -sN --max-time 1800 -H ' . escapeshellarg('Content-Type: application/json')
            . ' --data-binary ' . escapeshellarg((string)$payload)
            . ' ' . escapeshellarg($base . '/api/generate');
        $lines = [];
        exec($cmd . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!', $lines);
        $pid = (int)trim((string)($lines[0] ?? '0'));
        if ($pid <= 0) {
            $blank['error'] = 'Could not start loading the model.';
            return $blank;
        }
        $blank['size'] = (int)($show['json']['size'] ?? 0);
        $blank['baseline'] = $this->memoryInUse();
        $blank['pid'] = $pid;
        $blank['error'] = '';
        $blank['retry_at'] = 0;
        $blank['started'] = time();
        return $blank;
    }

    private function tickModelLoad(array $state): array
    {
        $used = $this->memoryInUse() - (int)($state['baseline'] ?? 0);
        if ($used < 0) {
            $used = 0;
        }
        $size = (int)($state['size'] ?? 0);
        $mem = $size > 0 ? (int)floor(($used * 100) / $size) : 0;
        if ($mem > 96) {
            $mem = 96;
        }
        $elapsed = max(0, time() - (int)($state['started'] ?? time()));
        // ~1 GB/s. The old half-percent clock was still near 10% when the model was already running.
        $expect = 18;
        if ($size > 0) {
            $expect = (int)max(8, min(75, (int)ceil($size / (1024 * 1048576))));
        }
        $creep = (int)floor($elapsed * 96 / $expect);
        if ($creep > 96) {
            $creep = 96;
        }
        $target = max($mem, $creep);
        $shown = (int)($state['shown'] ?? 0);
        if ($target > $shown) {
            $shown = $target;
        }
        $state['shown'] = min(96, $shown);
        $state['error'] = '';
        return $state;
    }

    private function modelLoadError(): string
    {
        $log = @file_get_contents(app_config()['data_dir'] . '/model-load.log');
        if (!is_string($log) || trim($log) === '') {
            return 'Ollama is not reachable.';
        }
        if (preg_match('/"error"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $log, $match)) {
            return stripcslashes($match[1]);
        }
        return 'The model did not start.';
    }

    private function memoryInUse(): int
    {
        $pids = [];
        $rss = 0;
        $ps = Process::run(['ps', '-ax', '-o', 'pid=,rss=,command='], null, null, 4);
        if ($ps['code'] === 0) {
            foreach (explode("\n", $ps['stdout']) as $line) {
                if (!preg_match('/^\s*(\d+)\s+(\d+)\s+.*\bollama\b/i', $line, $match)) {
                    continue;
                }
                $pids[] = (int)$match[1];
                $rss += (int)$match[2];
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $foot = $this->darwinFootprint($pids);
            if ($foot > 0) {
                return $foot;
            }
        }
        $vram = 0;
        if (Process::commandExists('nvidia-smi')) {
            $gpu = Process::run(['nvidia-smi', '--query-gpu=memory.used', '--format=csv,noheader,nounits'], null, null, 4);
            if ($gpu['code'] === 0) {
                foreach (preg_split('/\s+/', trim($gpu['stdout'])) ?: [] as $part) {
                    if (is_string($part) && ctype_digit($part)) {
                        $vram += (int)$part;
                    }
                }
            }
        }
        return ($rss * 1024) + ($vram * 1048576);
    }

    private function darwinFootprint(array $pids): int
    {
        if ($pids === [] || !is_executable('/usr/bin/footprint')) {
            return 0;
        }
        $command = ['/usr/bin/footprint', '-f', 'bytes', '--noCategories'];
        $added = 0;
        foreach ($pids as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $command[] = '-p';
            $command[] = (string)$pid;
            $added++;
            if ($added >= 12) {
                break;
            }
        }
        if ($added === 0) {
            return 0;
        }
        $result = Process::run($command, null, null, 3);
        if (!preg_match_all('/phys_footprint:\s+(\d+)\s+B/', $result['stdout'], $matches)) {
            return 0;
        }
        $bytes = 0;
        foreach ($matches[1] as $value) {
            $bytes += (int)$value;
        }
        return $bytes;
    }

    private function pidRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $result = Process::run(['ps', '-p', (string)$pid], null, null, 3);
        return $result['code'] === 0;
    }

    private function clearModelLoad(): void
    {
        $path = app_config()['data_dir'] . '/model-load.json';
        if (!is_file($path)) {
            return;
        }
        $state = json_decode((string)file_get_contents($path), true);
        $pid = is_array($state) ? (int)($state['pid'] ?? 0) : 0;
        if ($pid > 0 && $this->pidRunning($pid) && $this->modelLoadCurl($pid)) {
            Process::run(['kill', (string)$pid], null, null, 3);
            $deadline = microtime(true) + 3;
            while ($this->pidRunning($pid) && microtime(true) < $deadline) {
                usleep(100000);
            }
            if ($this->pidRunning($pid)) {
                Process::run(['kill', '-9', (string)$pid], null, null, 3);
            }
        }
        file_put_contents($path, '');
    }

    private function modelLoadCurl(int $pid): bool
    {
        $result = Process::run(['ps', '-ww', '-p', (string)$pid, '-o', 'command='], null, null, 3);
        return str_contains((string)($result['stdout'] ?? ''), '/api/generate');
    }

    private function rememberModelWarm(string $model): void
    {
        $json = json_encode(['model' => $model, 'seen' => time()]);
        if (!is_string($json)) {
            return;
        }
        file_put_contents(app_config()['data_dir'] . '/model-warm.json', $json, LOCK_EX);
    }

    private function modelWarm(string $model): bool
    {
        $raw = @file_get_contents(app_config()['data_dir'] . '/model-warm.json');
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['model'] ?? '') !== $model) {
            return false;
        }
        return (time() - (int)($state['seen'] ?? 0)) < 600;
    }

    private function ollamaBase(): ?string
    {
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        if (!preg_match('#^https?://\S+$#', $base)) {
            return null;
        }
        return $base;
    }

    private function isProtectedModel(string $name): bool
    {
        $default = strtolower((string)app_config()['cpu_failsafe_model']);
        $name = strtolower(trim($name));
        if (str_ends_with($name, ':latest')) {
            $name = substr($name, 0, -strlen(':latest'));
        }
        return $name === $default;
    }

    private function modelName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9_.:\/-]+$/', $name)) {
            return null;
        }
        return $name;
    }

    private function ollamaRequest(string $method, string $path, ?array $body, int $timeout): array
    {
        $base = $this->ollamaBase();
        if ($base === null) {
            return ['json' => null, 'error' => 'The Ollama URL in Setup is not valid.'];
        }
        $ch = curl_init($base . $path);
        if ($ch === false) {
            return ['json' => null, 'error' => 'Could not reach Ollama.'];
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($raw === false || $errno !== 0) {
            return ['json' => null, 'error' => 'Ollama is not reachable.'];
        }
        $json = json_decode((string)$raw, true);
        if ($code >= 400) {
            $message = is_array($json) && isset($json['error']) ? (string)$json['error'] : 'Ollama returned an error.';
            return ['json' => null, 'error' => $message];
        }
        return ['json' => is_array($json) ? $json : [], 'error' => null];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                $rounded = $value >= 10 ? (string)round($value) : (string)round($value, 1);
                return $rounded . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }

    private function publicSettings(): array
    {
        $settings = $this->db->allSettings();
        $cloud = (string)($settings['cloud_api_key'] ?? '');
        $extra = (string)($settings['extra_api_key_value'] ?? '');
        unset($settings['password_hash'], $settings['tick_lock'], $settings['agent_status'], $settings['tools_default_on'], $settings['tools_noted'], $settings['max_turns_raised'], $settings['full_system_sudoers']);
        $settings['full_system_control'] = ($settings['full_system_control'] ?? '0') === '1' ? '1' : '0';
        $settings['search_provider'] = ($settings['search_provider'] ?? '') === 'bing' ? 'bing' : 'duckduckgo';
        foreach (array_keys($settings) as $key) {
            if (str_starts_with($key, 'chat_stop_') || str_starts_with($key, 'chat_gen_') || str_starts_with($key, 'context_packed_') || str_starts_with($key, 'context_summary_') || str_starts_with($key, 'context_compress_') || str_starts_with($key, 'reread_desc_') || str_starts_with($key, 'recovery_') || str_starts_with($key, 'install_ask_') || str_starts_with($key, 'action_ask_') || str_starts_with($key, 'action_meta_') || $key === 'subagent_lock') {
                unset($settings[$key]);
            }
        }
        $settings['cloud_api_key_set'] = $cloud !== '';
        $settings['cloud_api_key'] = $this->mask($cloud);
        $settings['extra_api_key_set'] = $extra !== '';
        $settings['extra_api_key_value'] = $this->mask($extra);
        $settings['compliance_logging'] = ($settings['compliance_logging'] ?? '0') === '1' ? '1' : '0';
        $settings['hitl_external_actions'] = ($settings['hitl_external_actions'] ?? '0') === '1' ? '1' : '0';
        $applies = (string)($settings['ai_act_applies'] ?? '');
        $settings['ai_act_applies'] = in_array($applies, ['yes', 'no', 'unsure'], true) ? $applies : '';
        $settings['terms_accepted'] = (string)($settings['terms_accepted_version'] ?? '') === ComplianceDocs::TERMS_VERSION;
        $settings['show_compliance_box'] = $settings['ai_act_applies'] === 'yes' || $settings['ai_act_applies'] === 'unsure';
        return $settings;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 4) {
            return '••••';
        }
        return '••••' . substr($value, -4);
    }

    private function keepSecret(string $incoming, string $current): string
    {
        if ($incoming === '' || str_contains($incoming, '•')) {
            return $current;
        }
        return $incoming;
    }

    private function projectFromQuery(): array
    {
        $project = $this->db->project((int)($_GET['project_id'] ?? 0));
        if (!$project) {
            $this->json(['error' => 'Project not found.'], 404);
            exit;
        }
        return $project;
    }

    private function requireAuth(): void
    {
        if (empty($_SESSION['authed'])) {
            $this->json(['error' => 'Unauthorized'], 401);
            exit;
        }
    }

    private function assertJson(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (!str_contains($type, 'application/json')) {
            $this->json(['error' => 'Expected JSON.'], 415);
            exit;
        }
    }

    private function exportBackup(): void
    {
        $folders = $this->input()['folders'] ?? null;
        if (!is_array($folders)) {
            $this->json(['error' => 'Choose at least one project.'], 422);
            return;
        }
        $path = '';
        try {
            $path = (new ProjectBackup($this->db))->export($folders);
            $name = 'danaco-backup-' . date('Ymd-Hi') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            $size = filesize($path);
            if ($size !== false) {
                header('Content-Length: ' . $size);
            }
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
        } catch (InvalidArgumentException $e) {
            if (!headers_sent()) {
                $this->json(['error' => $e->getMessage()], 422);
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            if (!headers_sent()) {
                $this->json(['error' => $e->getMessage()], 500);
            }
        }
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function previewBackup(): void
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            $this->json(['error' => 'Choose a backup zip.'], 422);
            return;
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $this->json(['error' => 'That backup is too large to upload. Run danaco restore in a terminal.'], 422);
            return;
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            $this->json(['error' => 'Choose a backup zip.'], 422);
            return;
        }
        try {
            $info = (new ProjectBackup($this->db))->stageUpload((string)$file['tmp_name']);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->json(['error' => $e->getMessage()], 500);
            return;
        }
        $this->json($info);
    }

    private function importBackup(): void
    {
        $body = $this->input();
        $choices = $body['choices'] ?? [];
        if (!is_array($choices)) {
            $this->json(['error' => 'Choose what to do with the duplicate projects.'], 422);
            return;
        }
        try {
            $result = (new ProjectBackup($this->db))->restoreToken((string)($body['token'] ?? ''), $choices);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->json(['error' => $e->getMessage()], 500);
            return;
        }
        $this->json($result);
    }

    private function cancelBackup(): void
    {
        (new ProjectBackup($this->db))->discardToken((string)($this->input()['token'] ?? ''));
        $this->json(['ok' => true]);
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->json(['error' => 'Invalid JSON.'], 400);
            exit;
        }
        return $data;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            http_response_code(500);
            echo '{"error":"Could not read that response."}';
            return;
        }
        echo $json;
    }

    private function beginSse(): void
    {
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        ignore_user_abort(true);
        echo ": stream\n\n";
        flush();
    }

    private function sse(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }
}
