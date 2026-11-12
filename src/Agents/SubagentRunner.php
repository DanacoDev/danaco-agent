<?php

class SubagentRunner
{
    private CapabilityTools $capabilities;

    public function __construct(
        private AgentRuntime $runtime,
        private Database $db,
        private MemoryManager $memory,
        private SkillEngine $skills,
        private SafetyInterceptor $safety
    ) {
        $this->capabilities = new CapabilityTools($db);
    }

    public function run(string $agent, string $projectFolder, string $instruction, callable $emit, int $projectId, ?callable $shouldStop = null, bool $scheduled = false): string
    {
        $allowed = ['researcher', 'writer', 'coder', 'analyst', 'marketer'];
        if (!in_array($agent, $allowed, true)) {
            throw new RuntimeException('Unknown subagent.');
        }
        $instruction = trim($instruction);
        if ($instruction === '') {
            throw new RuntimeException('The subagent needs an instruction.');
        }
        if (!$this->db->tryLock('subagent_lock', 900)) {
            throw new RuntimeException('Only one subagent can run at a time.');
        }
        $this->capabilities->blockedInstalls = [];
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($agent, $projectFolder, $projectId)],
            ['role' => 'user', 'content' => "project: {$projectFolder}\n\n{$instruction}"],
        ];
        $watch = function () use ($shouldStop): bool {
            $this->db->setSetting('subagent_lock', (string)time());
            return $shouldStop && $shouldStop();
        };
        try {
            $result = $this->runtime->run($agent, $messages, $this->tools($agent, $projectFolder, $projectId, $emit, $watch), $emit, $this->runtime->maxTurns(), $projectId, $watch, false, $scheduled);
            $excerpt = trim(preg_replace('/\s+/', ' ', $result) ?? '');
            $this->db->loop($agent, 'process', 'Context released · ' . $excerpt);
            return $this->finishSubagent($result);
        } finally {
            $this->runtime->releaseLocalModel();
            $this->db->unlock('subagent_lock');
        }
    }

    public function consult(string $projectFolder, string $instruction, callable $emit, int $projectId, ?callable $shouldStop = null, bool $scheduled = false): string
    {
        if (!$this->db->consultantActive()) {
            $reason = (string)($this->db->consultantState()['reason'] ?? '');
            if ($reason === 'no_key') {
                return 'The Consultant has no API key. Tell the user to add one on the Setup page, under External LLM provider, and use that API for the Consultant.';
            }
            return 'The Consultant is turned off. Tell the user they can turn it on from the Agents page. It costs tokens while it is on.';
        }
        $instruction = trim($instruction);
        if ($instruction === '') {
            return 'The Consultant needs an assignment.';
        }
        if (!$this->db->tryLock('consultant_lock', 900)) {
            return 'The Consultant is already working. Wait, then ask again.';
        }
        $this->capabilities->blockedInstalls = [];
        $watch = function () use ($shouldStop): bool {
            $this->db->setSetting('consultant_lock', (string)time());
            return $shouldStop && $shouldStop();
        };
        $tools = [];
        $prompt = Prompts::CONSULTANT;
        if ($projectFolder !== '') {
            $prompt = $this->systemPrompt('consultant', $projectFolder, $projectId);
            $tools = $this->tools('consultant', $projectFolder, $projectId, $emit, $watch);
        }
        $user = $projectFolder !== '' ? "project: {$projectFolder}\n\n{$instruction}" : $instruction;
        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $user],
        ];
        try {
            return $this->finishSubagent($this->runtime->run('consultant', $messages, $tools, $emit, $this->runtime->maxTurns(), $projectId > 0 ? $projectId : null, $watch, true, $scheduled));
        } finally {
            $this->db->unlock('consultant_lock');
        }
    }

    public function teamBrief(int $projectId): string
    {
        $names = ['list_files', 'read_file', 'read_image', 'read_pdf', 'screenshot', 'create_pdf', 'write_file', 'delete_file', 'move_file', 'wiki_search', 'wiki_write', 'list_vault', 'read_vault', 'restore_file'];
        $own = [];
        $research = [];
        $teamCaps = [];
        foreach ($this->capabilities->descriptions($projectId) as $line) {
            if (!preg_match('/^- ([a-z_]+):/', $line, $match)) {
                continue;
            }
            $name = $match[1];
            if (in_array($name, ['web_search', 'fetch_url', 'browse_url'], true)) {
                $research[] = $name;
            } elseif (in_array($name, ['send_email', 'ftp', 'mcp', 'remember'], true)) {
                $own[] = $name;
            } else {
                $teamCaps[] = $name;
            }
        }
        $shared = implode(', ', $names);
        $capLine = '';
        if ($own !== []) {
            $capLine .= 'You can call these yourself: ' . implode(', ', $own) . ".\n";
        }
        if ($research !== []) {
            $capLine .= 'You know ' . implode(', ', $research) . '. They search the web and open public pages. Only the researcher can call them. Delegate that work to the researcher with a clear instruction. Do not say you cannot visit a website.' . "\n";
        }
        if ($teamCaps !== []) {
            $capLine .= 'Subagents can also use: ' . implode(', ', $teamCaps) . ".\n";
        }
        if (in_array('send_email', $own, true)) {
            $capLine .= "Call send_email when mail should go out. Do not delegate a send, and do not say the tool is missing. The mailbox on that tool is who the message is from. to is who it is written to.\n";
        }
        $scope = $this->db->fullSystemControl()
            ? 'Paths are relative to the project folder. A path that starts with / reaches any file on this computer, and run_command may use sudo.'
            : 'Paths are relative to the project folder.';
        return <<<TXT
Team. Delegate by the kind of work. Every subagent can use: {$shared}.
Those skills cover any file in the project, including the wiki. {$scope} A new or replaced skill goes to the coder through write_skill. That registers it in the Skills library, outside the project, so later runs and scheduled tasks can call it by name. You have write_skill when the coder cannot finish it. test_skill runs a skill and returns what it printed. You have that too. Do not write a skill into the project.
{$capLine}
- researcher: find and check facts with web_search, fetch_url, and browse_url, parse documents, then log them with research_pipeline in their own wiki file. Do not put research in the project description.
- writer: draft long-form text, documentation, and communication copy, and save it in the wiki. An article runs write_article and follows every step, including humanizer and a spelling pass. Other prose runs humanizer. A figure, icon, or infographic runs make_graphic, then the file is written and its path comes back. A PDF is the writer's job too. Delegate the brief and the file name. The writer calls create_pdf, and only the file path comes back, so a long document does not fill this chat. Do not give a PDF to the coder.
- coder: write and debug software, run code_verifier on each changed file, and use download_large for a download over 100 MB. Create or replace a skill with write_skill only when the instruction explicitly asks for that skill, then test_skill and read the exit code and output. A file in the project is not a skill. A database is sqlite_helper, not a new skill. A page icon or graphic runs make_graphic before it is drawn.
- analyst: process data, calculate, forecast, and make charts. An svg chart or infographic runs make_graphic.
- marketer: campaigns, press copy, brand, and visual direction. An icon or infographic runs make_graphic.
The Consultant always exists. When it is available it can use the same project file skills. It has no write_skill or test_skill. It is paid and only for a hard multi-step problem.
TXT;
    }

    private function systemPrompt(string $agent, string $projectFolder, int $projectId): string
    {
        $omit = ['delegate', 'consult', 'wiki_read', 'wiki_reindex', 'task_breakdown'];
        if ($agent !== 'coder') {
            $omit[] = 'write_skill';
            $omit[] = 'test_skill';
        }
        if ($projectId <= 0 || !$this->db->emailAccounts($projectId)) {
            $omit[] = 'send_email';
        }
        if ($projectId <= 0 || !$this->db->ftpAccounts($projectId)) {
            $omit[] = 'ftp';
        }
        $catalog = $this->skills->promptCatalog($omit);
        $extra = [];
        foreach ($this->capabilities->descriptions($projectId) as $line) {
            if (!preg_match('/^- (send_email|ftp|mcp):/', $line, $match) || in_array($match[1], $omit, true)) {
                continue;
            }
            $extra[] = $line;
        }
        $identity = 'Assigned project folder: ' . $projectFolder;
        $named = $projectFolder !== '' ? $this->db->projectByFolder($projectFolder) : null;
        if (is_array($named)) {
            $title = trim((string)($named['name'] ?? ''));
            if ($title !== '') {
                $identity .= "\nAssigned project name: " . $title;
            }
            $rename = Projects::renameLine($named);
            if ($rename !== '') {
                $identity .= "\n" . $rename;
            }
        }
        $brief = Projects::promptContext($projectFolder);
        if ($brief !== '') {
            $identity .= "\n\n" . $brief;
        }
        $rules = <<<TXT
{$identity}
Stay inside {$projectFolder}/. You can read, edit, delete, and move any file there, including the wiki. Paths are relative to {$projectFolder}/. Store every project markdown file in {$projectFolder}/wiki/. The description file is wiki/PROJECT.md. It holds only the general description: what the project is, contributors, credentials, and main goals. Deep research, findings, and specific data get their own markdown file there, or a SQLite database in database/. wiki/NOTES.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file. The board has no backlog. An idea that is not scheduled does not get a kanban card. You cannot create, move, edit, or delete a kanban card, and you do not call task_breakdown. When the instruction names a card, add a short result to it with the kanban tool before you finish. The main agent checks that work and then moves or updates the card. When your work reaches conclusions, decisions, or facts worth keeping, write or edit the matching wiki file before you finish. After a tool result says the file was written, do not write that path again unless a later tool changed the text. Then reply with the path. You may read and restore backups and trash. You cannot delete anything in those folders. When you create or cite a file, put its path alone on a line, such as wiki/NOTES.md or report.pdf. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat. A download over 100 MB uses download_large so the chat is not held up.

Do not install software or dependencies. If something is missing, say what it is and stop. The main agent asks the user and installs it.

Never create or replace a skill unless the instruction from the main agent explicitly asks for that new or replaced skill. A database, a folder, or a script the task needs is not that request. Use a tool or skill that is already listed.

When you need a tool, emit exactly this and stop so the system can run it. Put nothing after <<end>> in the same turn:
<<tool>>
{"name":"list_files","args":{}}
<<end>>
Other correct examples:
<<tool>>
{"name":"read_file","args":{"path":"wiki/NOTES.md"}}
<<end>>
<<tool>>
{"name":"write_file","args":{"path":"wiki/NOTES.md","content":"..."}}
<<end>>
Never invent another call format. These do nothing:
: list_files
: delete_file
: wiki/NOTES.md
list_files
<task_breakdown>...</task_breakdown>
<delegate><agent>writer</agent></delegate>
Naming a tool in a list or sentence does nothing. Only the <<tool>> ... <<end>> block runs it.
Do not print a tool call in the reply. Draft XML such as <delegate> is thinking, not something the user should read.
Do not say you wrote, updated, or created a file until a tool result confirms it. Showing the planned markdown in chat is not a write. Call wiki_write or write_file first, then answer.

These are the skills at your disposal. Read each for further instructions
TXT;
        if ($this->db->fullSystemControl()) {
            $rules .= "\nFull system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer {$projectFolder}/ unless the task needs something outside it.\n";
        }
        $tail = Prompts::forAgent($agent) . "\n\n" . $rules . "\n" . $catalog;
        if ($extra !== []) {
            $tail .= "\n\n" . implode("\n\n", $extra);
        }
        $notes = $this->memory->toolNotesForPrompt();
        if ($notes !== '') {
            $tail .= "\n\n" . $notes;
        }
        return $tail;
    }

    private function confirmAction(string $kind, string $summary, callable $emit, ?callable $shouldStop, ?int $projectId = null): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $id = $this->db->beginActionAsk($kind, $summary, $projectId);
        $emit(['type' => 'action_prompt', 'id' => $id, 'kind' => $kind, 'summary' => $summary]);
        $deadline = time() + 180;
        try {
            while (time() < $deadline) {
                if ($shouldStop && $shouldStop()) {
                    return false;
                }
                $answer = (string)$this->db->getSetting('action_ask_' . $id, '');
                if ($answer === 'yes') {
                    return true;
                }
                if ($answer === 'no') {
                    return false;
                }
                usleep(300000);
            }
            return false;
        } finally {
            $this->db->clearActionAsk($id);
        }
    }

    private function gateExternal(string $kind, string $summary, callable $run, callable $emit, ?callable $shouldStop, int $projectId): string
    {
        if ($this->db->hitlExternalActions()) {
            if (PHP_SAPI === 'cli') {
                return 'This action needs human approval, but a scheduled run cannot ask. Turn off extra approval gates, or run it from chat.';
            }
            if (!$this->confirmAction($kind, $summary, $emit, $shouldStop, $projectId > 0 ? $projectId : null)) {
                return 'The user did not allow this action.';
            }
        }
        $result = $run();
        $this->db->logCompliance('tool_' . $kind, 'agent', $summary, ['result' => clip_text($result, 500)], $projectId > 0 ? $projectId : null);
        return $result;
    }

    private function kanbanResult(int $projectId, array $args): string
    {
        $board = new Kanban($this->db);
        $action = strtolower(trim((string)($args['action'] ?? 'list')));
        if ($projectId <= 0) {
            return $action === 'list' ? 'Open a project to see its tasks.' : 'Tasks belong to a project.';
        }
        if ($action === 'list') {
            return $board->listing($projectId);
        }
        if ($action === 'result') {
            try {
                $task = $board->recordResult($projectId > 0 ? (int)($args['id'] ?? 0) : 0, $projectId, (string)($args['result'] ?? ''));
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
            return 'Added a result to task #' . $task['id'] . ': ' . $task['title'] . '. The main agent checks this and moves or updates the card.';
        }
        return 'Only the main agent can move or edit a card. You can list the board, or add a result with action result, id, and result.';
    }

    private function tools(string $agent, string $projectFolder, int $projectId, callable $emit, ?callable $shouldStop = null): array
    {
        $tools = [
            'read_file' => function (array $args) use ($projectFolder): string {
                return $this->safety->readFile($projectFolder, (string)($args['path'] ?? ''));
            },
            'read_pdf' => function (array $args) use ($projectFolder, $shouldStop): string {
                return PdfFile::readProject($this->safety, $projectFolder, (string)($args['path'] ?? ''), (string)($args['question'] ?? ''), $shouldStop);
            },
            'read_image' => function (array $args) use ($projectFolder, $shouldStop): string {
                return Sight::look($this->safety, $projectFolder, (string)($args['path'] ?? ''), (string)($args['question'] ?? ''), $shouldStop);
            },
            'screenshot' => function (array $args) use ($projectFolder, $shouldStop): string {
                return Sight::shot($this->safety, $projectFolder, $args, $shouldStop);
            },
            'create_pdf' => function (array $args) use ($projectFolder): string {
                return PdfFile::writeProject($this->safety, $projectFolder, (string)($args['path'] ?? ''), (string)($args['text'] ?? ''));
            },
            'write_file' => function (array $args) use ($projectFolder): string {
                $path = (string)($args['path'] ?? '');
                $content = (string)($args['content'] ?? '');
                $written = $this->safety->writeFile($projectFolder, $path, $content);
                return 'Wrote ' . $written['path'] . ($written['backed_up'] ? ' (previous version backed up).' : '.');
            },
            'delete_file' => function (array $args) use ($projectFolder): string {
                $meta = $this->safety->deleteFile($projectFolder, (string)($args['path'] ?? ''));
                $kind = !empty($meta['is_dir']) ? 'folder ' : '';
                $where = str_starts_with((string)$meta['relative_path'], '/') ? 'the trash.' : 'the trash above the project.';
                return 'Moved ' . $kind . $meta['relative_path'] . ' to ' . $where;
            },
            'move_file' => function (array $args) use ($projectFolder): string {
                $moved = $this->safety->moveFile($projectFolder, (string)($args['from'] ?? ''), (string)($args['to'] ?? ''));
                return 'Moved ' . $moved['from'] . ' to ' . $moved['to'] . '.';
            },
            'list_files' => function (array $args) use ($projectFolder): string {
                $tree = $this->safety->listFiles($projectFolder, (string)($args['path'] ?? ''));
                return json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            },
            'list_vault' => function (): string {
                return $this->safety->vaultListing();
            },
            'read_vault' => function (array $args): string {
                return $this->safety->readVault((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
            },
            'restore_file' => function (array $args): string {
                $result = $this->safety->restore((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
                return 'Restored ' . $result['restored'] . ' into ' . $result['project_folder'] . '.';
            },
            'wiki_search' => function (array $args) use ($projectFolder): string {
                return $this->memory->search((string)($args['query'] ?? ''), $projectFolder);
            },
            'kanban' => function (array $args) use ($projectId): string {
                return $this->kanbanResult($projectId, $args);
            },
            'wiki_write' => function (array $args) use ($projectFolder): string {
                try {
                    $relative = Projects::wikiRelativeFromArgs($projectFolder, $args);
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                $content = (string)($args['content'] ?? '');
                if (!empty($args['append'])) {
                    try {
                        $existing = $this->safety->readFile($projectFolder, $relative, 100000);
                    } catch (Throwable $e) {
                        $existing = '';
                    }
                    $content = rtrim($existing) . "\n\n" . $content;
                }
                $written = $this->safety->writeFile($projectFolder, $relative, $content);
                return 'Updated ' . $written['path'];
            },
        ];
        if ($agent === 'coder') {
            $tools['write_skill'] = function (array $args): string {
                try {
                    return $this->skills->register($args, 'coder');
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
            };
            $tools['test_skill'] = function (array $args) use ($projectFolder): string {
                return $this->skills->testCall($args, $projectFolder);
            };
        }
        foreach ($this->capabilities->handlers($projectFolder, $projectId) as $name => $handler) {
            if ($name === 'send_email') {
                $tools[$name] = function (array $args) use ($handler, $emit, $shouldStop, $projectId): string {
                    $to = trim((string)($args['to'] ?? ''));
                    $subject = trim((string)($args['subject'] ?? ''));
                    $summary = 'Send email to ' . ($to !== '' ? $to : '(unknown)') . ($subject !== '' ? ' — ' . $subject : '');
                    return $this->gateExternal('email', $summary, fn(): string => $handler($args), $emit, $shouldStop, $projectId);
                };
                continue;
            }
            if ($name === 'ftp') {
                $tools[$name] = function (array $args) use ($handler, $emit, $shouldStop, $projectId): string {
                    $action = strtolower(trim((string)($args['action'] ?? '')));
                    return $this->gateExternal('ftp', 'FTP ' . ($action !== '' ? $action : 'action'), fn(): string => $handler($args), $emit, $shouldStop, $projectId);
                };
                continue;
            }
            if ($name === 'mcp') {
                $tools[$name] = function (array $args) use ($handler, $emit, $shouldStop, $projectId): string {
                    $server = trim((string)($args['server'] ?? ''));
                    $tool = trim((string)($args['tool'] ?? ''));
                    $summary = 'MCP' . ($server !== '' ? ' ' . $server : '') . ($tool !== '' ? ' / ' . $tool : '');
                    return $this->gateExternal('mcp', $summary, fn(): string => $handler($args), $emit, $shouldStop, $projectId);
                };
                continue;
            }
            $tools[$name] = $handler;
        }
        foreach ($this->skills->activeHandlers($projectFolder) as $name => $handler) {
            if ($name === 'sqlite_helper') {
                $tools[$name] = function (array $args) use ($handler): string {
                    $action = strtolower(trim((string)($args['action'] ?? '')));
                    if ($action === '') {
                        $action = strtolower(trim((string)($args['command'] ?? '')));
                    }
                    $sql = trim((string)($args['sql'] ?? ''));
                    $destructive = in_array($action, ['drop', 'delete'], true)
                        || ($action === 'execute' && preg_match('/^\s*DROP\s+TABLE\b/i', $sql));
                    if ($destructive) {
                        return 'SQLite table drops are approved by the user through the main agent. Stop. Report the database filename and the table name. The main agent calls sqlite_helper with action drop. Do not retry, do not use sqlite3 or run_command, and do not tell the user to run a command.';
                    }
                    return $handler($args);
                };
                continue;
            }
            if ($name === 'task_breakdown') {
                $tools[$name] = function (): string {
                    return 'Only the main agent can add kanban cards. Add a result to the card you were given.';
                };
                continue;
            }
            if (!isset($tools[$name])) {
                $tools[$name] = $handler;
            }
        }
        foreach (array_keys($tools) as $name) {
            if (!$this->skills->offered($name)) {
                unset($tools[$name]);
            }
        }
        return $tools;
    }

    private function finishSubagent(string $result): string
    {
        if (!$this->capabilities->blockedInstalls) {
            return $result;
        }
        $lines = [];
        foreach ($this->capabilities->blockedInstalls as $command) {
            $lines[] = '- ' . $command;
        }
        $this->capabilities->blockedInstalls = [];
        $note = "Install stopped. A subagent tried to install dependencies. Only you can install them. Run the install yourself. The chat asks the user before it runs.\n" . implode("\n", $lines);
        return rtrim($result) . "\n\n" . $note;
    }
}
