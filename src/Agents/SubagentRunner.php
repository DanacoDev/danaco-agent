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

    public function run(string $agent, string $projectFolder, string $instruction, callable $emit, int $projectId, ?callable $shouldStop = null): string
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
            return $this->finishSubagent($this->runtime->run($agent, $messages, $this->tools($agent, $projectFolder, $projectId, $emit, $watch), $emit, $this->runtime->maxTurns(), $projectId, $watch));
        } finally {
            $this->db->unlock('subagent_lock');
        }
    }

    public function consult(string $projectFolder, string $instruction, callable $emit, int $projectId, ?callable $shouldStop = null): string
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
            return $this->finishSubagent($this->runtime->run('consultant', $messages, $tools, $emit, $this->runtime->maxTurns(), $projectId > 0 ? $projectId : null, $watch, true));
        } finally {
            $this->db->unlock('consultant_lock');
        }
    }

    public function teamBrief(int $projectId): string
    {
        $names = ['list_files', 'read_file', 'read_pdf', 'create_pdf', 'write_file', 'delete_file', 'move_file', 'wiki_search', 'wiki_write', 'list_vault', 'read_vault', 'restore_file'];
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
        $skills = $this->skills->summariesForPrompt();
        $skillLine = $skills === '- none'
            ? 'No extra skills are active.'
            : "Active skills, available to every subagent:\n" . $skills;
        $scope = $this->db->fullSystemControl()
            ? 'Paths are relative to the project folder. A path that starts with / reaches any file on this computer, and run_command may use sudo.'
            : 'Paths are relative to the project folder.';
        return <<<TXT
Team. Delegate by the kind of work. Every subagent can use: {$shared}.
Those tools cover any file in the project, including the wiki. {$scope} A new or replaced skill goes to the coder. You have write_skill when the coder cannot finish it.
{$capLine}
- researcher: find and check facts with web_search, fetch_url, and browse_url, parse documents, then log them with research_pipeline in their own wiki file. Do not put research in the project description.
- writer: draft long-form text, documentation, and communication copy, and save it in the wiki. An article runs write_article and follows every step, including humanizer and a spelling pass. Other prose runs humanizer. A figure, icon, or infographic runs make_graphic, then the file is written and its path comes back. A PDF is the writer's job too. Delegate the brief and the file name. The writer calls create_pdf, and only the file path comes back, so a long document does not fill this chat. Do not give a PDF to the coder.
- coder: write and debug software, run code_verifier on each changed file, use download_large for a download over 100 MB, and create a reusable PHP skill with write_skill. Skills are not project files. A page icon or graphic runs make_graphic before it is drawn.
- analyst: process data, calculate, forecast, and make charts. An svg chart or infographic runs make_graphic.
- marketer: campaigns, press copy, brand, and visual direction. An icon or infographic runs make_graphic.
The Consultant always exists. When it is available it can use the same project file tools. It has no write_skill. It is paid and only for a hard multi-step problem.
{$skillLine}
TXT;
    }

    private function systemPrompt(string $agent, string $projectFolder, int $projectId): string
    {
        $toolLines = [
            '- read_file: args path. Any file in this project, including the wiki.',
            '- read_pdf: args path. Reads the text of a PDF in this project.',
            '- create_pdf: args path, text. Writes a PDF in this project. path ends in .pdf and is not in wiki/. Headings and asterisks are typeset for you. An existing file is kept, and the new one is saved as the next free name, such as document2.pdf. Cite the path the tool wrote, alone on a line. Do not repeat the document.',
            '- write_file: args path, content. Creates or overwrites a file in this project. Markdown belongs in wiki/.',
            '- delete_file: args path. Moves a file or folder out of the project into the trash above it. path is relative, such as projects. Cannot delete backups or trash.',
            '- move_file: args from, to. Moves a file or folder to another path in this project.',
            '- list_files: args path, optional',
            '- list_vault: no args. Backups and trash you can read or restore.',
            '- read_vault: args kind (backup or trash), stored_as',
            '- restore_file: args kind, stored_as',
            '- wiki_search: args query',
            '- wiki_write: args content, append, and optional name. name is a markdown filename in wiki/. Omit it only to update the general description: what the project is, contributors, credentials, and main goals. Pass a new name for research, findings, and specific data.',
        ];
        if ($agent === 'coder') {
            $toolLines[] = '- write_skill: args name, description, filename, source. filename is a lowercase .php file. source is PHP that reads JSON from stdin. Saves to data/skills/, not the project. Cannot replace a built-in skill. It then checks that the file parses. If the check fails, the skill is not kept. Send the complete source again. Do not end the file with a closing PHP tag.';
        }
        $toolLines = array_merge($toolLines, $this->capabilities->descriptions($projectId));
        $toolLines[] = "Skills, one line each:\n" . $this->skills->summariesForPrompt();
        $rules = <<<TXT
Assigned project folder: {$projectFolder}
Stay inside {$projectFolder}/. You can read, edit, delete, and move any file there, including the wiki. Paths are relative to {$projectFolder}/. Store every project markdown file in {$projectFolder}/wiki/. The description file is wiki/{$projectFolder}_desc.md. It holds only the general description: what the project is, contributors, credentials, and main goals. Deep research, findings, and specific data get their own markdown file there, or a SQLite database in database/. wiki/notes.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file and not on the kanban. When your work reaches conclusions, decisions, or facts worth keeping, write or edit the matching wiki file before you finish, and update it again if later conclusions change it. You may read and restore backups and trash. You cannot delete anything in those folders. When you create or cite a file, put its path alone on a line, such as wiki/notes.md or report.pdf. The chat adds a download button at the top right of that line. A download over 100 MB uses download_large so the chat is not held up. Do not edit soul.md. Only the main agent changes how to act in this project.

Do not install software or dependencies. If something is missing, say what it is and stop. The main agent asks the user and installs it.

When you need a tool, emit exactly this and stop so the system can run it. Put nothing after <<end>> in the same turn:
<<tool>>
{"name":"list_files","args":{}}
<<end>>
Other correct examples:
<<tool>>
{"name":"read_file","args":{"path":"wiki/notes.md"}}
<<end>>
<<tool>>
{"name":"write_file","args":{"path":"wiki/notes.md","content":"..."}}
<<end>>
Never invent another call format. These do nothing:
: list_files
: delete_file
: wiki/notes.md
list_files
Naming a tool in a list or sentence does nothing. Only the <<tool>> ... <<end>> block runs it.

Tools:
TXT;
        if ($this->db->fullSystemControl()) {
            $rules .= "\nFull system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer {$projectFolder}/ unless the task needs something outside it.\n";
        }
        $tail = Prompts::forAgent($agent) . "\n\n" . $rules . "\n" . implode("\n", $toolLines);
        $notes = $this->memory->toolNotesForPrompt();
        if ($notes !== '') {
            $tail .= "\n\n" . $notes;
        }
        $soul = Projects::soulPrompt($projectFolder);
        if ($soul !== '') {
            $tail .= "\n\n" . $soul;
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

    private function tools(string $agent, string $projectFolder, int $projectId, callable $emit, ?callable $shouldStop = null): array
    {
        $tools = [
            'read_file' => function (array $args) use ($projectFolder): string {
                return $this->safety->readFile($projectFolder, (string)($args['path'] ?? ''));
            },
            'read_pdf' => function (array $args) use ($projectFolder): string {
                return PdfFile::readProject($this->safety, $projectFolder, (string)($args['path'] ?? ''));
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
                $name = (string)($args['name'] ?? '');
                $filename = (string)($args['filename'] ?? ($name !== '' ? $name . '.php' : ''));
                try {
                    $skill = $this->skills->saveSource(
                        $filename,
                        (string)($args['source'] ?? ''),
                        $name,
                        (string)($args['description'] ?? ''),
                        true,
                        'coder'
                    );
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
                return 'Saved skill ' . $skill['name'] . ' to data/skills/' . $skill['script_path'] . '. Syntax check passed.';
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
            if (!isset($tools[$name])) {
                $tools[$name] = $handler;
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
