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
        $skills = $this->skills->summariesForPrompt();
        $skillLine = $skills === '- none'
            ? 'No extra skills are active.'
            : "These are the tools at your disposal. Read each for further instructions\n" . $skills;
        $scope = $this->db->fullSystemControl()
            ? 'Paths are relative to the project folder. A path that starts with / reaches any file on this computer, and run_command may use sudo.'
            : 'Paths are relative to the project folder.';
        return <<<TXT
Team. Delegate by the kind of work. Every subagent can use: {$shared}.
Those tools cover any file in the project, including the wiki. {$scope} A new or replaced skill, PHP or Python, goes to the coder through write_skill. That registers it in the Skills library, outside the project, so later runs and scheduled tasks can call it by name. You have write_skill when the coder cannot finish it. test_skill runs a skill and returns the exit code, stdout, and stderr. You have that too. Do not write a skill into the project.
{$capLine}
- researcher: find and check facts with web_search, fetch_url, and browse_url, parse documents, then log them with research_pipeline in their own wiki file. Do not put research in the project description.
- writer: draft long-form text, documentation, and communication copy, and save it in the wiki. An article runs write_article and follows every step, including humanizer and a spelling pass. Other prose runs humanizer. A figure, icon, or infographic runs make_graphic, then the file is written and its path comes back. A PDF is the writer's job too. Delegate the brief and the file name. The writer calls create_pdf, and only the file path comes back, so a long document does not fill this chat. Do not give a PDF to the coder.
- coder: write and debug software, run code_verifier on each changed file, and use download_large for a download over 100 MB. Create or replace a skill with write_skill only when the instruction explicitly asks for that skill, then test_skill and read the exit code and output. A file in the project is not a skill. A database is sqlite_helper, not a new skill. A page icon or graphic runs make_graphic before it is drawn.
- analyst: process data, calculate, forecast, and make charts. An svg chart or infographic runs make_graphic.
- marketer: campaigns, press copy, brand, and visual direction. An icon or infographic runs make_graphic.
The Consultant always exists. When it is available it can use the same project file tools. It has no write_skill or test_skill. It is paid and only for a hard multi-step problem.
{$skillLine}
TXT;
    }

    private function systemPrompt(string $agent, string $projectFolder, int $projectId): string
    {
        $toolLines = [
            '- read_file: args path. Any file in this project, including the wiki.',
            '- read_pdf: args path, optional question. Reads the text of a PDF in this project. A scan or a page with no text is read from the page image, including amounts and totals. Pass question when you need one fact, such as the total to pay. Do not ask the user to type the page out.',
            '- read_image: args path, optional question. Looks at a png, jpg, gif, or webp in this project. The GPU model can see the picture, including text in it. Use it for a screenshot, photo, or image upload. A scanned PDF is read_pdf. An svg is text, so use read_file. Cite the path alone on a line when the user should see the picture.',
            '- screenshot: args path or url, optional save, width, height. Takes a picture of a page in this project or a public page. path is an html, htm, or php file, or a folder with an index page. url is a public http or https address. Saves a png under screenshots/ unless save is set. Cite that path alone on a line so the user sees the picture. Call read_image on it when you need to see what is wrong.',
            '- create_pdf: args path, text. Writes a PDF in this project. Put the file in docs/ unless another folder was requested. path ends in .pdf and is not in wiki/. Headings and asterisks are typeset for you. An existing file is kept, and the new one is saved as the next free name, such as document2.pdf. Cite the path the tool wrote, alone on a line. Do not repeat the document.',
            '- write_file: args path, content. Creates or overwrites a file in this project. It can create folders in the path. Markdown belongs in wiki/. A skill is not a project file. Do not create one unless the main agent explicitly asked. The coder uses write_skill.',
            '- delete_file: args path. Moves a file or folder out of the project into the trash above it. path is relative, such as projects. Cannot delete backups or trash.',
            '- move_file: args from, to. Moves a file or folder to another path in this project. It can create folders in the path.',
            '- list_files: args path, optional',
            '- list_vault: no args. Backups and trash you can read or restore, with exact size_bytes for each item.',
            '- read_vault: args kind (backup or trash), stored_as',
            '- restore_file: args kind, stored_as',
            '- wiki_search: args query',
            '- wiki_write: args content, append, and optional name. name is a markdown filename in wiki/. Omit it only to update the general description: what the project is, contributors, credentials, and main goals. Pass a new name for research, findings, and specific data.',
            '- kanban: args action (list or result). list shows the board. result needs id and result, and that is the only change you can make. You cannot create, move, edit, or delete a card. The main agent checks the result and then moves or updates the card.',
        ];
        if ($agent === 'coder') {
            $toolLines[] = '- write_skill: Do not call this unless the main agent explicitly asked you to create or replace a skill. args name, description, filename, source, overwrite. filename is a lowercase .php or .py file. source reads JSON from stdin and prints the result. This is the only way to create a skill. It registers the skill in the Skills library, outside the project, so the Skills page lists it and later runs, including scheduled tasks, can call it by name. A PHP skill has a YAML header in a /* */ block and no closing PHP tag. A Python skill uses the same header as # comments. If that name already exists, the tool names the skill and does not change it. Pass overwrite true to replace a skill the agent created. A built-in skill cannot be replaced. It then checks that the file parses. If the check fails, the skill is not kept. Send the complete source again. Do not write_file a skill into the project, and do not create a skills or data/skills folder there.';
            $toolLines[] = '- test_skill: args name, input. Runs that skill in this project and returns the exit code, stdout, and stderr. name is the skill. input is the JSON object it reads on stdin. The skill really runs, so files it writes stay. It also runs a skill that is turned off. Call it after write_skill. A skill registered in this run is not callable by its own name until a later run. Do not finish while the test result is wrong.';
        }
        $toolLines = array_merge($toolLines, $this->capabilities->descriptions($projectId));
        $toolLines[] = $this->skills->summariesForPrompt();
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

These are the tools at your disposal. Read each for further instructions
TXT;
        if ($this->db->fullSystemControl()) {
            $rules .= "\nFull system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer {$projectFolder}/ unless the task needs something outside it.\n";
        }
        $tail = Prompts::forAgent($agent) . "\n\n" . $rules . "\n" . implode("\n", $toolLines);
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
                $name = (string)($args['name'] ?? '');
                $filename = (string)($args['filename'] ?? ($name !== '' ? $name . '.php' : ''));
                $overwrite = in_array(strtolower(trim((string)($args['overwrite'] ?? ''))), ['1', 'true', 'yes'], true);
                $before = $this->db->skillByName($name);
                try {
                    $skill = $this->skills->saveSource(
                        $filename,
                        (string)($args['source'] ?? ''),
                        $name,
                        (string)($args['description'] ?? ''),
                        true,
                        'coder',
                        $overwrite
                    );
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
                $verb = $before ? 'Replaced' : 'Registered';
                return $verb . ' skill ' . $skill['name'] . ' (' . $skill['script_path'] . ') in the Skills library. It is listed on the Skills page and can be called by name, including from a scheduled task. Syntax check passed. Call test_skill with name ' . $skill['name'] . ' and input set to the JSON that skill reads. Check the exit code, stdout, and stderr. This run cannot call the skill by its own name yet.';
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
                    $sql = trim((string)($args['sql'] ?? ''));
                    $destructive = in_array($action, ['drop', 'delete'], true)
                        || ($action === 'execute' && preg_match('/^\s*DROP\s+TABLE\b/i', $sql));
                    if ($destructive) {
                        return 'SQLite table drops require approval from the main agent. Do not retry this action.';
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
