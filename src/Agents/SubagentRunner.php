<?php

class SubagentRunner
{
    private CapabilityTools $capabilities;

    private array $runTrace = [];

    private int $stepTaskId = 0;

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
        $this->capabilities->missingPython = [];
        $this->runTrace = [];
        $this->bindStepTask($instruction);
        $stored = false;
        $user = $this->withPriorRun($projectId, $agent, "project: {$projectFolder}\n\n{$instruction}");
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($agent, $projectFolder, $projectId, $this->skills->usedNames([['content' => $user]]))],
            ['role' => 'user', 'content' => $user],
        ];
        $watch = function () use ($shouldStop): bool {
            $this->db->setSetting('subagent_lock', (string)time());
            return $shouldStop && $shouldStop();
        };
        try {
            $result = $this->runtime->run($agent, $messages, $this->traced($this->tools($agent, $projectFolder, $projectId, $emit, $watch)), $emit, $this->runtime->maxTurns(), $projectId, $watch, false, $scheduled, fn(array $running): array => $this->refreshSystem($running, $agent, $projectFolder, $projectId));
            $this->settleWrittenTask($projectId, $instruction);
            $excerpt = trim(preg_replace('/\s+/', ' ', $result) ?? '');
            $this->db->loop($agent, 'process', 'Context released · ' . $excerpt);
            $result = $this->finishSubagent($result);
            $this->storeRun($projectId, $agent, $result);
            $stored = true;
            return $result;
        } finally {
            if (!$stored) {
                $this->storeRun($projectId, $agent, '');
            }
            $this->runTrace = [];
            $this->stepTaskId = 0;
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
            return 'The Consultant is turned off. Tell the user they can turn it on from the Activity page. It costs tokens while it is on.';
        }
        $instruction = trim($instruction);
        if ($instruction === '') {
            return 'The Consultant needs an assignment.';
        }
        if (!$this->db->tryLock('consultant_lock', 900)) {
            return 'The Consultant is already working. Wait, then ask again.';
        }
        $this->capabilities->blockedInstalls = [];
        $this->capabilities->missingPython = [];
        $this->runTrace = [];
        $this->bindStepTask($instruction);
        $stored = false;
        $watch = function () use ($shouldStop): bool {
            $this->db->setSetting('consultant_lock', (string)time());
            return $shouldStop && $shouldStop();
        };
        $tools = [];
        $prompt = Prompts::CONSULTANT;
        if ($projectFolder !== '') {
            $tools = $this->tools('consultant', $projectFolder, $projectId, $emit, $watch);
        }
        $user = $projectFolder !== '' ? "project: {$projectFolder}\n\n{$instruction}" : $instruction;
        $user = $this->withPriorRun($projectId, 'consultant', $user);
        if ($projectFolder !== '') {
            $prompt = $this->systemPrompt('consultant', $projectFolder, $projectId, $this->skills->usedNames([['content' => $user]]));
        }
        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $user],
        ];
        $refresh = $projectFolder !== ''
            ? fn(array $running): array => $this->refreshSystem($running, 'consultant', $projectFolder, $projectId)
            : null;
        try {
            $result = $this->runtime->run('consultant', $messages, $this->traced($tools), $emit, $this->runtime->maxTurns(), $projectId > 0 ? $projectId : null, $watch, true, $scheduled, $refresh);
            if ($projectId > 0) {
                $this->settleWrittenTask($projectId, $instruction);
            }
            $result = $this->finishSubagent($result);
            $this->storeRun($projectId, 'consultant', $result);
            $stored = true;
            return $result;
        } finally {
            if (!$stored) {
                $this->storeRun($projectId, 'consultant', '');
            }
            $this->runTrace = [];
            $this->stepTaskId = 0;
            $this->db->unlock('consultant_lock');
        }
    }

    public function teamBrief(int $projectId): string
    {
        $names = ['filesystem', 'read_image', 'read_pdf', 'screenshot', 'create_pdf', 'wiki_memory', 'list_vault', 'read_vault', 'restore_file'];
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
Those skills cover any file in the project, including the wiki. {$scope} A new or replaced skill goes to the coder through write_skill. That registers it in the Skills library, outside the project, so later runs and scheduled tasks can call it by name. You have write_skill when the coder cannot finish it. A skill already in the list is called by name. Do not call write_skill for it. test_skill runs a skill and returns what it printed. You have that too. Do not write a skill into the project.
{$capLine}
- researcher: find and check facts with web_search, fetch_url, and browse_url, use arxiv to search and read arXiv papers, and use yahoo_finance for trading research across stocks, bonds, commodities, crypto, currencies, indexes, and futures, parse documents, then save a lookup with wiki_memory. A research mission is research_loop action mission. That call creates database/research-<slug>.db. It shows on the Research tab. action active, on 1 or 0, turns it on or off and pauses every other mission. action status answers how it is going. Do not create a task. Do not call add, next, commit, review, or idea. Do not delete a mission. Do not put research in the project description, and do not create a wiki file per finding.
- writer: draft long-form text, documentation, and communication copy, and save it in the wiki. A poem, book, web content, or article runs write_article and follows every step, including humanizer and a spelling pass. A PDF is the last step of that skill, and only the file path comes back. Pass a font color, a size, or a picture only when the user named one. Otherwise every line stays black. A figure, icon, or infographic runs make_graphic, then the file is written and its path comes back. Delegate the brief and the file name. Do not give a PDF to the coder.
- coder: write and debug software in PHP, run code_verifier on each changed file, and use download_large for a download over 100 MB. Use Python only when a Python library is required, and run that file with run_python. If run_python says a library is missing, stop and name it. Do not install it. Do not use run_command for Python, and do not ask for full system control to run a script. Create or replace a skill with write_skill only when the instruction explicitly asks for that skill, then test_skill and read the exit code and output. A file in the project is not a skill. A skill program is PHP. A database is sqlite_helper, not a new skill. A page icon or graphic runs make_graphic before it is drawn.
- analyst: process data, calculate, forecast, and make charts. An svg chart or infographic runs make_graphic.
- marketer: campaigns, press copy, brand, and visual direction. An icon or infographic runs make_graphic.
- consultant: a subagent for a hard multi-step problem. Delegate with agent consultant. It uses an external paid model and the same project file skills. It can list, create, update, and delete tasks. A new research mission is created by the user or the main agent. The consultant does not call action mission. It does not create a task. It has no write_skill or test_skill. Delegate only when the note says the Consultant is on.
TXT;
    }

    private function refreshSystem(array $messages, string $agent, string $projectFolder, int $projectId): array
    {
        if ($messages === [] || ($messages[0]['role'] ?? '') !== 'system') {
            return $messages;
        }
        $messages[0]['content'] = $this->systemPrompt($agent, $projectFolder, $projectId, $this->skills->usedNames(array_slice($messages, 1)));
        return $messages;
    }

    private function systemPrompt(string $agent, string $projectFolder, int $projectId, array $expand = []): string
    {
        $omit = ['delegate'];
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
        $expand[] = 'wiki_memory';
        if ($agent === 'writer') {
            $expand[] = 'create_pdf';
        }
        if ($agent === 'consultant') {
            $expand[] = 'tasklist';
            $expand[] = 'research_loop';
        }
        if ($agent === 'researcher') {
            $expand[] = 'yahoo_finance';
            $expand[] = 'arxiv';
        } else {
            $omit[] = 'yahoo_finance';
            $omit[] = 'arxiv';
        }
        $catalog = $this->skills->promptCatalog($omit, [], $expand);
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
        $researchRule = match ($agent) {
            'researcher' => ' A research mission is research_loop and one database/research-<slug>.db per question. Stay on the slug in the instruction. A new mission is created by the user or the main agent. Do not call action mission. Read the lessons before a new hypothesis. A child is one full sentence, not a short title, and does not restate a lesson, a finding, or a sibling. An idea that already has children keeps its wording. Do not change it. A later hypothesis has parent_id. A settled hypothesis has insight, and that lesson moves up the tree. Do not call action active, auto, or review. When the packet stage is conclude, one existing idea becomes the conclusion or the design and the other open ideas are parked. Do not add another idea. Do not create a wiki markdown for a finding, a source list, a hypothesis, or a phase. Do not open that database with sqlite_helper. Do not invent research1.db.',
            'consultant' => ' You can see research_loop. Its instructions are written out below. A new mission is created by the user or the main agent. Do not call action mission. action status answers how it is going. Do not create a task. Do not call add, next, commit, review, or idea. Do not delete a mission. Do not create a wiki markdown for a finding, a source list, a hypothesis, or a phase. Do not open that database with sqlite_helper. Do not invent research1.db. Do not run the research packets.',
            default => '',
        };
        $skillLead = $agent === 'consultant'
            ? 'wiki_memory, tasklist, and research_loop are written out in full.'
            : 'wiki_memory is written out in full.';
        $taskRule = $agent === 'consultant'
            ? 'When the assignment names a task, list it with the tasklist tool first. Update the named task so its open steps match the plan. Do not delete it and create another. Delete only when the assignment says to remove that task. Each step needs a description that names the files, lists, and skills, and what to save or return. A title alone is rejected. To replace the open steps, pass replace_steps true and the full new list. Done steps stay. Do not do the research or file work unless the assignment asks for that. End with the task id and the open steps.'
            : 'You cannot create, update, or delete a task. When the instruction names a task, add a short result to it with the tasklist tool before you finish. The main agent checks that work and then updates the task.';
        $rules = <<<TXT
{$identity}
Stay inside {$projectFolder}/. You can read, edit, delete, and move any file there, including the wiki. Paths are relative to {$projectFolder}/. Store every project markdown file in {$projectFolder}/wiki/. The description file is wiki/PROJECT.md. It holds only the general description: what the project is, contributors, credentials, and main goals. Deep research, findings, and specific data get their own markdown file there, or a SQLite database in database/. wiki/NOTES.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file. The task list has no backlog. An idea that is not scheduled does not get a task. {$taskRule}{$researchRule} If the task includes a previous run, that record is what you already found, wrote, or returned. Continue from it. Do not start that work over. A plan, a rule, a decision, or a conclusion from this run is written with wiki_memory before you reply. Read the matching file first. Do not wait to be asked, and do not copy the whole chat into the wiki. After a tool result says the file was written, do not write that path again unless a later tool changed the text. Then reply with the path. You may read and restore backups and trash. You cannot delete anything in those folders. When you create or cite a file, put its path alone on a line, such as wiki/NOTES.md or report.pdf. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat. A download over 100 MB uses download_large so the chat is not held up.

Do not install software or dependencies. If something is missing, say what it is and stop. The main agent asks the user and installs it.

Never create or replace a skill unless the instruction from the main agent explicitly asks for that new or replaced skill. A database, a folder, or a script the task needs is not that request. Use a tool or skill that is already listed.

When you need a tool, emit exactly this and stop so the system can run it. Put nothing after <<end>> in the same turn:
<<tool>>
{"name":"filesystem","args":{"action":"list"}}
<<end>>
Other correct examples:
<<tool>>
{"name":"filesystem","args":{"action":"read","path":"wiki/NOTES.md"}}
<<end>>
<<tool>>
{"name":"filesystem","args":{"action":"write","path":"wiki/NOTES.md","content":"..."}}
<<end>>
Never invent another call format. These do nothing:
: filesystem
: delete
: wiki/NOTES.md
filesystem
<tasklist>...</tasklist>
<delegate><agent>writer</agent></delegate>
Naming a tool in a list or sentence does nothing. Only the <<tool>> ... <<end>> block runs it.
Do not print a tool call in the reply. Do not write a tool name followed by a JSON object. Draft XML such as <delegate> is thinking, not something the user should read.
Do not say you wrote, updated, or created a file until a tool result confirms it. Showing the planned markdown in chat is not a write. Call wiki_memory with action write, or filesystem with action write, first, then answer.

These are the skills at your disposal. {$skillLead} Each other line is the description and the arguments. After a skill is called, its instructions are added here and stay while that call is still in this chat.
TXT;
        if ($this->db->fullSystemControl()) {
            $rules .= "\nFull system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer {$projectFolder}/ unless the task needs something outside it. Python still runs through run_python and stays inside the project.\n";
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

    private function bindStepTask(string $instruction): void
    {
        $this->stepTaskId = 0;
        if (preg_match('/This is task #(\d+)/', $instruction, $found)) {
            $this->stepTaskId = (int)$found[1];
        }
    }

    private function stepKind(string $title): string
    {
        $title = strtolower($title);
        if (preg_match('/\b(report|cross off)\b/', $title) === 1) {
            return 'report';
        }
        if (preg_match('/\bpdf\b/', $title) === 1 && preg_match('/\b(read|check)\b/', $title) === 1) {
            return 'check';
        }
        if (preg_match('/\bpdf\b/', $title) === 1) {
            return 'pdf';
        }
        if (preg_match('/\b(write|save|markdown|draft)\b/', $title) === 1) {
            return 'write';
        }
        return '';
    }

    private function noteFinishedStep(string $name, string $text): void
    {
        if ($this->stepTaskId <= 0) {
            return;
        }
        $text = trim($text);
        if ($text === '' || str_starts_with($text, 'Error')) {
            return;
        }
        try {
            $task = $this->db->task($this->stepTaskId);
            if (!$task) {
                return;
            }
            $current = $this->currentOpenStep($task);
            if (!$current || !$this->stepToolMatches((string)$current['title'], $name, $text)) {
                return;
            }
            $title = trim((string)$current['title']);
            $line = '';
            if (preg_match('/^Wrote\s+(\S+\.(?:md|pdf))\b/', $text, $path) === 1) {
                $line = 'Wrote ' . $path[1];
            } elseif ($name === 'grammar_check') {
                $line = 'Checked spelling and grammar.';
            } elseif ($name === 'read_pdf') {
                $line = 'Checked the PDF.';
            }
            (new Kanban($this->db))->crossOffSteps($this->stepTaskId, false, [$title], $line);
        } catch (Throwable $e) {
        }
    }

    private function currentOpenStep(array $task): ?array
    {
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            if (trim((string)($sub['title'] ?? '')) === '') {
                continue;
            }
            return $sub;
        }
        return null;
    }

    private function stepToolMatches(string $title, string $name, string $text): bool
    {
        $kind = $this->stepKind($title);
        $lower = strtolower($title);
        if ($name === 'filesystem' && preg_match('/^Wrote\s+\S+\.md\b/', $text) === 1) {
            return $kind === 'write';
        }
        if ($name === 'create_pdf' && preg_match('/^Wrote\s+\S+\.pdf\b/', $text) === 1) {
            return $kind === 'pdf' || ($kind === 'report' && preg_match('/\bpdf\b/', $lower) === 1);
        }
        if ($name === 'read_pdf' && !str_starts_with($text, 'No text could be read')) {
            return $kind === 'check';
        }
        if ($name === 'grammar_check') {
            return preg_match('/\b(typo|typos|grammar|spelling|stavning)\b/', $lower) === 1;
        }
        return false;
    }

    private function settleWrittenTask(int $projectId, string $instruction): void
    {
        if ($projectId <= 0 || !preg_match('/This is task #(\d+)/', $instruction, $found)) {
            return;
        }
        $id = (int)$found[1];
        $task = $this->db->task($id);
        if (!$task || (int)$task['project_id'] !== $projectId) {
            return;
        }
        $board = new Kanban($this->db);
        if ($board->column($task) === 'done') {
            return;
        }
        $reports = [];
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            $label = trim((string)($sub['title'] ?? ''));
            if ($this->stepKind($label) !== 'report') {
                return;
            }
            if ($label !== '') {
                $reports[] = $label;
            }
        }
        if ($reports === []) {
            return;
        }
        $pdf = '';
        $markdown = '';
        $reported = false;
        foreach ($this->runTrace as $row) {
            $name = (string)($row['name'] ?? '');
            $text = (string)($row['text'] ?? '');
            if ($name === 'tasklist' && str_starts_with($text, 'Added a result')) {
                $reported = true;
            }
            if ($name === 'create_pdf' && preg_match('/Wrote\s+(\S+\.pdf)/', $text, $path)) {
                $pdf = $path[1];
            }
            if ($name === 'filesystem' && preg_match('/Wrote\s+(\S+\.md)/', $text, $path)) {
                $markdown = $path[1];
            }
        }
        $wantsPdf = preg_match('/\bpdf\b/i', $instruction) === 1
            && preg_match('/\bno pdf\b|\bwithout a pdf\b|\bdo not (?:make|create) a pdf\b|\bdon\'t (?:make|create) a pdf\b/i', $instruction) !== 1;
        $path = $pdf !== '' ? $pdf : (!$wantsPdf ? $markdown : '');
        if ($path === '') {
            return;
        }
        try {
            if (!$reported) {
                $board->recordResult($id, $projectId, 'Wrote ' . $path);
            }
            $board->crossOffSteps($id, false, $reports, 'Wrote ' . $path);
        } catch (Throwable $e) {
        }
    }

    private function kanbanResult(int $projectId, array $args, bool $manage = false): string
    {
        $board = new Kanban($this->db);
        $action = strtolower(trim((string)($args['action'] ?? 'list')));
        if ($projectId <= 0) {
            return $action === 'list' ? 'Open a project to see its tasks.' : 'Tasks belong to a project.';
        }
        if ($action === 'list') {
            return $board->listing($projectId);
        }
        if ($manage && in_array($action, ['create', 'update', 'move', 'delete'], true)) {
            return $this->consultantManageTask($projectId, $action, $args, $board);
        }
        if ($action === 'result') {
            try {
                $reported = (string)($args['result'] ?? '');
                $id = (int)($args['id'] ?? 0);
                if ($id <= 0) {
                    $id = $this->stepTaskId;
                }
                $task = $board->recordResult($id, $projectId, $reported);
                $current = $this->currentOpenStep($task);
                if ($current) {
                    $task = $board->crossOffSteps((int)$task['id'], false, [trim((string)$current['title'])], $reported);
                }
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
            $status = $board->column($task);
            $open = $this->currentOpenStep($task);
            $recurrence = strtolower(trim((string)($task['recurrence_interval'] ?? 'none')));
            $repeats = $recurrence !== '' && $recurrence !== 'none';
            if ($status === 'done') {
                $tail = ' Task is done. Do not delegate it again.';
            } elseif ($open) {
                $tail = ' The task is not done. The open step is: ' . trim((string)$open['title']) . '. Do not tell the user the task is finished.';
            } elseif ($repeats) {
                $tail = ' The steps for this run are finished. Leave the task in progress. Do not delegate it again.';
            } else {
                $tail = ' The main agent checks this and updates the task.';
            }
            return 'Added a result to task #' . $task['id'] . ': ' . $task['title'] . '.' . $tail;
        }
        if ($action === 'stop') {
            return 'Only the main agent can stop a running job.';
        }
        return 'Only the main agent can update a task. You can list the task list, or add a result with action result, id, and result.';
    }

    private function consultantManageTask(int $projectId, string $action, array $args, Kanban $board): string
    {
        try {
            if ($action === 'create') {
                if (array_key_exists('tasks', $args)) {
                    return $this->consultantCreateTasks($projectId, $args['tasks'], $board);
                }
                $task = $board->create($projectId, $args, false);
                return $board->createdLine($task);
            }
            if ($action === 'update' || $action === 'move') {
                $id = $this->taskCardId($projectId, $args);
                if ($id <= 0) {
                    return 'Task not found.';
                }
                $task = $board->move($id, $args, false);
                $held = (int)($task['held'] ?? 0) === 1 ? ' The task stays held and will not start.' : '';
                $stepsNote = '';
                if (array_key_exists('steps', $args) || array_key_exists('subtasks', $args)) {
                    $write = $board->stepWrite();
                    $added = (int)($write['added'] ?? 0);
                    $detailed = (int)($write['detailed'] ?? 0);
                    $removed = (int)($write['removed'] ?? 0);
                    if ($added > 0) {
                        $stepsNote = ' Added ' . $added . ($added === 1 ? ' step.' : ' steps.');
                    }
                    if ($detailed > 0) {
                        $stepsNote .= ' Updated ' . $detailed . ($detailed === 1 ? ' existing step.' : ' existing steps.');
                    }
                    if ($removed > 0) {
                        $stepsNote .= ' Removed ' . $removed . ' open ' . ($removed === 1 ? 'step.' : 'steps.');
                    }
                    $crossed = (int)($write['crossed'] ?? 0);
                    if ($crossed > 0) {
                        $stepsNote .= ' Crossed off ' . $crossed . ($crossed === 1 ? ' step.' : ' steps.');
                    }
                    if ($stepsNote === '') {
                        $stepsNote = $board->unchangedStepsNote();
                    }
                }
                return $board->updatedLine($task) . $held . $stepsNote;
            }
            if ($action === 'delete') {
                $id = $this->taskCardId($projectId, $args);
                $task = $id > 0 ? $this->db->task($id) : null;
                if (!$task || (int)$task['project_id'] !== $projectId) {
                    return 'Task not found.';
                }
                $this->db->deleteTask($id);
                return 'Deleted task #' . $id . ': ' . $task['title'];
            }
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return 'Unknown tasklist action. Use list, create, update, or delete.';
    }

    private function consultantCreateTasks(int $projectId, mixed $tasks, Kanban $board): string
    {
        if (is_string($tasks)) {
            $decoded = json_decode($tasks, true);
            $tasks = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tasks)) {
            return 'Pass tasks. Each task has a title, an agent, and steps.';
        }
        if (array_key_exists('title', $tasks) && !array_is_list($tasks)) {
            $tasks = [$tasks];
        }
        $added = [];
        $duplicates = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $title = trim((string)($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (mb_strlen($title) > 125) {
                $title = rtrim(mb_substr($title, 0, 122)) . '...';
            }
            $steps = $task['steps'] ?? $task['subtasks'] ?? [$title];
            $payload = [
                'title' => $title,
                'agent' => strtolower(trim((string)($task['agent'] ?? $task['assigned_agent'] ?? 'orchestrator'))),
                'steps' => is_array($steps) && $steps !== [] ? $steps : [$title],
                'status' => (string)($task['status'] ?? 'in_progress'),
            ];
            if (array_key_exists('schedule_time', $task)) {
                $payload['schedule_time'] = $task['schedule_time'];
            }
            if (array_key_exists('recurrence', $task) || array_key_exists('recurrence_interval', $task)) {
                $payload['recurrence'] = $task['recurrence'] ?? $task['recurrence_interval'];
            }
            try {
                $saved = $board->create($projectId, $payload, false);
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                if (str_starts_with($message, 'Error: Task #')) {
                    $duplicates[] = $message;
                    continue;
                }
                return $message;
            }
            $added[] = '#' . $saved['id'] . ' ' . $saved['title'];
            if (count($added) >= 40) {
                break;
            }
        }
        $lines = [];
        if ($added !== []) {
            $lines[] = 'Added ' . count($added) . ' ' . (count($added) === 1 ? 'task' : 'tasks') . ' in in_progress: ' . implode('; ', $added) . '.';
        }
        if ($duplicates !== []) {
            $lines[] = 'These tasks are already on the task list and were not added. Use those tasks. ' . implode(' ', $duplicates);
        }
        return $lines === [] ? 'Pass tasks with a title, an agent, and steps.' : implode("\n", $lines);
    }

    private function taskCardId(int $projectId, array $args): int
    {
        foreach (['id', 'task_id', 'task'] as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $raw = $args[$key];
            $id = is_int($raw) || is_float($raw) ? (int)$raw : 0;
            if ($id <= 0 && is_string($raw) && preg_match('/(\d+)/', $raw, $found)) {
                $id = (int)$found[1];
            }
            if ($id > 0) {
                $task = $this->db->task($id);
                if ($task && (int)$task['project_id'] === $projectId) {
                    return $id;
                }
            }
        }
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') {
            return 0;
        }
        foreach ($this->db->tasks($projectId) as $task) {
            if ((string)($task['status'] ?? '') === 'archived') {
                continue;
            }
            if (strcasecmp(trim((string)($task['title'] ?? '')), $title) === 0) {
                return (int)$task['id'];
            }
        }
        return 0;
    }

    private function stepTotal(array $task): int
    {
        $count = 0;
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (is_array($sub) && trim((string)($sub['title'] ?? '')) !== '') {
                $count++;
            }
        }
        return $count;
    }

    private function tools(string $agent, string $projectFolder, int $projectId, callable $emit, ?callable $shouldStop = null): array
    {
        $files = function (array $args) use ($projectFolder, $agent): string {
            $action = strtolower(trim((string)($args['action'] ?? '')));
            if ($action === 'write' && $agent === 'researcher' && $this->researchStoreExists($projectFolder) && $this->newWikiMarkdown($projectFolder, (string)($args['path'] ?? ''))) {
                return 'This project keeps research in database/research-<slug>.db. Call research_loop. Do not create a wiki markdown.';
            }
            if ($action === 'list') {
                $tree = $this->safety->listFiles($projectFolder, (string)($args['path'] ?? ''));
                return json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
            if ($action === 'read') {
                return $this->safety->readFile($projectFolder, (string)($args['path'] ?? ''));
            }
            if ($action === 'write') {
                $path = (string)($args['path'] ?? '');
                $content = (string)($args['content'] ?? '');
                $written = $this->safety->writeFile($projectFolder, $path, $content);
                return 'Wrote ' . $written['path'] . ($written['backed_up'] ? ' (previous version backed up).' : '.');
            }
            if ($action === 'delete') {
                $meta = $this->safety->deleteFile($projectFolder, (string)($args['path'] ?? ''));
                $kind = !empty($meta['is_dir']) ? 'folder ' : '';
                $where = str_starts_with((string)$meta['relative_path'], '/') ? 'the trash.' : 'the trash above the project.';
                return 'Moved ' . $kind . $meta['relative_path'] . ' to ' . $where;
            }
            if ($action === 'move' || $action === 'copy') {
                $moved = $this->safety->moveFile($projectFolder, (string)($args['from'] ?? ''), (string)($args['to'] ?? ''), $action === 'copy');
                return ($action === 'copy' ? 'Copied ' : 'Moved ') . $moved['from'] . ' to ' . $moved['to'] . '.';
            }
            return 'filesystem needs action list, read, write, delete, move, or copy.';
        };
        $wiki = function (array $args) use ($projectFolder, $agent): string {
            $action = strtolower(trim((string)($args['action'] ?? '')));
            if ($action === 'write' && $agent === 'researcher' && $this->researchStoreExists($projectFolder)) {
                return 'This project keeps research in database/research-<slug>.db. Call research_loop. Do not write a wiki file for a finding.';
            }
            if ($action === 'search') {
                return $this->memory->search((string)($args['query'] ?? ''), $projectFolder);
            }
            if ($action === 'read' || $action === 'reindex') {
                return 'Only the main agent can ' . $action . ' the wiki. A subagent uses filesystem to read a file, and wiki_memory with action search or write.';
            }
            if ($action === 'write') {
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
                return Projects::wikiSavedLine($args, $written['path']);
            }
            return 'wiki_memory needs action search or write here. The main agent also uses read and reindex.';
        };
        $tools = [
            'filesystem' => $files,
            'list_files' => function (array $args) use ($files): string {
                $args['action'] = 'list';
                return $files($args);
            },
            'read_file' => function (array $args) use ($files): string {
                $args['action'] = 'read';
                return $files($args);
            },
            'write_file' => function (array $args) use ($files): string {
                $args['action'] = 'write';
                return $files($args);
            },
            'delete_file' => function (array $args) use ($files): string {
                $args['action'] = 'delete';
                return $files($args);
            },
            'move_file' => function (array $args) use ($files): string {
                $copy = $args['copy'] ?? false;
                $args['action'] = ($copy === true || $copy === 1 || in_array(strtolower(trim((string)$copy)), ['1', 'true', 'yes'], true)) ? 'copy' : 'move';
                return $files($args);
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
            'wiki_memory' => $wiki,
            'wiki_search' => function (array $args) use ($wiki): string {
                $args['action'] = 'search';
                return $wiki($args);
            },
            'tasklist' => function (array $args) use ($projectId, $agent): string {
                return $this->kanbanResult($projectId, $args, $agent === 'consultant');
            },
            'task_breakdown' => function (): string {
                return 'Only the main agent can add tasks. Add a result to the task you were given with tasklist action result.';
            },
            'kanban' => function (array $args) use ($projectId, $agent): string {
                return $this->kanbanResult($projectId, $args, $agent === 'consultant');
            },
            'wiki_write' => function (array $args) use ($wiki): string {
                $args['action'] = 'write';
                return $wiki($args);
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
                        return 'SQLite table drops are approved by the user through the main agent. Stop. Report the database filename and the table name. The main agent calls sqlite_helper with action drop. Do not retry, do not use sqlite3, run_command, or run_python, and do not tell the user to run a command.';
                    }
                    return $handler($args);
                };
                continue;
            }
            if ($name === 'research_loop' && $agent !== 'researcher') {
                $tools[$name] = function (array $args) use ($handler): string {
                    $action = strtolower(trim((string)($args['action'] ?? '')));
                    if ($action === 'mission') {
                        return 'A new mission is created by the user or the main agent. Do not call research_loop action mission.';
                    }
                    if (in_array($action, ['add', 'next', 'commit', 'review'], true)) {
                        return 'The research loop runs this pass. Do not call research_loop action ' . $action . '. Use status to answer how it is going, or active to turn it on or off.';
                    }
                    return $handler($args);
                };
                continue;
            }
            if ($name === 'research_loop' && $agent === 'researcher') {
                $tools[$name] = function (array $args) use ($handler, $projectId): string {
                    $action = strtolower(trim((string)($args['action'] ?? '')));
                    if ($action === 'mission') {
                        return 'A new mission is created by the user or the main agent. Stay on the mission in this pass. Do not call research_loop action mission.';
                    }
                    if (in_array($action, ['active', 'auto', 'review'], true)) {
                        return 'The switch and the steering stay outside this pass. Do not call research_loop action ' . $action . '.';
                    }
                    $job = $this->db->runningResearchJob($projectId);
                    $payload = is_array($job) ? json_decode((string)($job['payload'] ?? ''), true) : null;
                    $slug = is_array($payload) ? trim((string)($payload['slug'] ?? '')) : '';
                    $name = strtolower(trim((string)($args['name'] ?? '')));
                    if ($slug !== '' && $name !== '' && $name !== $slug) {
                        return 'Stay on mission ' . $slug . '. Do not open another one.';
                    }
                    return $handler($args);
                };
                continue;
            }
            if (!isset($tools[$name])) {
                $tools[$name] = $handler;
            }
        }
        if ($agent !== 'researcher') {
            unset($tools['yahoo_finance']);
            unset($tools['arxiv']);
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
        $notes = [];
        if ($this->capabilities->blockedInstalls) {
            $lines = [];
            foreach ($this->capabilities->blockedInstalls as $command) {
                $lines[] = '- ' . $command;
            }
            $this->capabilities->blockedInstalls = [];
            $notes[] = "Install stopped. A subagent tried to install dependencies. Only you can install them. Run the install yourself. The chat asks the user before it runs.\n" . implode("\n", $lines);
        }
        if ($this->capabilities->missingPython) {
            $names = array_values(array_unique($this->capabilities->missingPython));
            $this->capabilities->missingPython = [];
            $notes[] = 'A Python library is missing: ' . implode(', ', $names) . '. Call py_install with packages set to ' . implode(' ', $names) . '. The chat asks the user before it installs. The library is saved inside the project. Then run the script again with run_python.';
        }
        if ($notes === []) {
            return $result;
        }
        return rtrim($result) . "\n\n" . implode("\n\n", $notes);
    }

    private function traced(array $tools): array
    {
        $wrapped = [];
        foreach ($tools as $name => $handler) {
            $wrapped[$name] = function (array $args) use ($handler, $name): string {
                $result = (string)$handler($args);
                $this->runTrace[] = [
                    'name' => (string)$name,
                    'hint' => $this->toolHint($args),
                    'text' => $result,
                ];
                $this->noteFinishedStep((string)$name, $result);
                return $result;
            };
        }
        return $wrapped;
    }

    private function researchStoreExists(string $projectFolder): bool
    {
        $dir = Projects::root($projectFolder) . '/database';
        if (!is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^research(?:-[a-z0-9-]{1,40})?\.db$/', strtolower($name)) === 1) {
                return true;
            }
        }
        return false;
    }

    private function newWikiMarkdown(string $projectFolder, string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        if (preg_match('#(^|/)wiki/.+\.md$#i', $path) !== 1) {
            return false;
        }
        $full = Projects::root($projectFolder) . '/' . ltrim($path, '/');
        return !is_file($full);
    }

    private function withPriorRun(int $projectId, string $agent, string $user): string
    {
        if (preg_match('/\bresearch_loop\b/', $user) === 1) {
            return $user;
        }
        $prior = trim((string)$this->db->getSetting($this->runKey($projectId, $agent), ''));
        if ($prior === '') {
            return $user;
        }
        $this->db->loop($agent, 'process', 'Previous run injected · ' . strlen($prior) . ' chars', $projectId > 0 ? $projectId : null);
        return $user . "\n\nPrevious run of yours in this project. Use this record. Continue from what it already found or wrote, and do the part that is still missing. Do not start that work over.\n\n" . $prior;
    }

    private function storeRun(int $projectId, string $agent, string $reply): void
    {
        $record = $this->runRecord($projectId, $agent, $reply);
        if ($record === '') {
            return;
        }
        $this->db->setSetting($this->runKey($projectId, $agent), $record);
        $this->db->loop($agent, 'process', 'Previous run saved · ' . strlen($record) . ' chars', $projectId > 0 ? $projectId : null);
    }

    private function runKey(int $projectId, string $agent): string
    {
        return 'subagent_last_run_' . max(0, $projectId) . '_' . $agent;
    }

    private function runRecord(int $projectId, string $agent, string $reply): string
    {
        $reply = trim($reply);
        $findings = [];
        $other = [];
        $seen = [];
        foreach ($this->runTrace as $row) {
            $name = (string)($row['name'] ?? '');
            $hint = (string)($row['hint'] ?? '');
            $text = trim((string)($row['text'] ?? ''));
            if ($name === '' || $text === '') {
                continue;
            }
            if ($name === 'web_search') {
                $hits = [];
                foreach ($this->searchHits($text) as $hit) {
                    if (!preg_match('#https?://\S+#i', $hit, $match)) {
                        $hits[] = $hit;
                        continue;
                    }
                    $url = strtolower(rtrim($match[0], '.,);'));
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $hits[] = $hit;
                }
                if ($hits !== []) {
                    $findings[] = $this->traceBlock($name, $hint, implode("\n", $hits));
                    continue;
                }
            }
            $other[] = $this->traceBlock($name, $hint, $this->compactTool($name, $text));
        }
        $previous = $this->priorFindings($projectId, $agent, $seen);
        if ($reply === '' && $findings === [] && $other === [] && $previous === '') {
            return '';
        }
        if ($findings === [] && $this->replyIsPromise($reply)) {
            $reply = $this->unfinishedRecord();
        }
        $parts = ["Final reply:\n" . ($reply !== '' ? clip_text($reply, 2500) : '(none)')];
        $foundBody = implode("\n\n", $findings);
        if ($previous !== '' && $foundBody !== '') {
            $foundBody = $previous . "\n\n" . $foundBody;
        } elseif ($previous !== '') {
            $foundBody = $previous;
        }
        $found = $this->fitText($foundBody, 7000);
        if ($found !== '') {
            $parts[] = "Findings:\n" . $found;
        }
        $used = strlen(implode("\n\n", $parts));
        $rest = $this->fitBlocks($other, max(0, 11000 - $used));
        if ($rest !== '') {
            $parts[] = "Tool results:\n" . $rest;
        }
        return implode("\n\n", $parts);
    }

    private function priorFindings(int $projectId, string $agent, array $seen): string
    {
        $prior = (string)$this->db->getSetting($this->runKey($projectId, $agent), '');
        $mark = "Findings:\n";
        $start = strpos($prior, $mark);
        if ($start === false) {
            return '';
        }
        $body = substr($prior, $start + strlen($mark));
        $end = strpos($body, "\n\nTool results:\n");
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }
        $kept = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('#https?://\S+#i', $line, $match)) {
                $url = strtolower(rtrim($match[0], '.,);'));
                if (isset($seen[$url])) {
                    continue;
                }
            }
            $kept[] = $line;
        }
        return trim(implode("\n", $kept));
    }

    private function replyIsPromise(string $reply): bool
    {
        $reply = trim($reply);
        if ($reply === '' || preg_match('#https?://#i', $reply) || preg_match('/\bwiki\/[\w.-]+\.md\b/i', $reply)) {
            return false;
        }
        $intent = 'I\'ll|I will|Let me|I\'m going to|I am going to';
        $verb = 'check|inspect|read|search|find|add|insert|update|write|save|delegate|move|look|query|create|fetch|browse|open|list|verify|confirm|research|restore|restoring';
        return (bool)preg_match('/\b(?:' . $intent . ')\b(?!\s+not\b)/i', $reply)
            && (bool)preg_match('/\b(?:' . $verb . ')\b/i', $reply);
    }

    private function unfinishedRecord(): string
    {
        $queries = [];
        $urls = [];
        foreach ($this->runTrace as $row) {
            $name = (string)($row['name'] ?? '');
            $hint = (string)($row['hint'] ?? '');
            if ($name === 'web_search' && preg_match('/(?:^|\s)query=(.*)$/', $hint, $match)) {
                $query = trim($match[1]);
                if ($query !== '' && !in_array($query, $queries, true)) {
                    $queries[] = $query;
                }
            }
            if (in_array($name, ['fetch_url', 'browse_url'], true) && preg_match('/(?:^|\s)url=(\S+)/', $hint, $match)) {
                $url = trim($match[1]);
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }
        $lines = ['Did not finish. Do not repeat these searches. Fetch a known URL or write the wiki note.'];
        if ($queries !== []) {
            $lines[] = 'Searches already tried:';
            foreach (array_slice($queries, 0, 8) as $query) {
                $lines[] = '- ' . $query;
            }
        }
        if ($urls !== []) {
            $lines[] = 'URLs already fetched:';
            foreach (array_slice($urls, 0, 8) as $url) {
                $lines[] = '- ' . $url;
            }
        }
        return implode("\n", $lines);
    }

    private function searchHits(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $hits = [];
        $title = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Results from ') || str_starts_with($line, 'Read a page') || str_starts_with($line, 'Query:') || str_starts_with($line, 'These pages do not contain') || str_starts_with($line, 'Open the best link') || str_starts_with($line, 'Missing:') || str_starts_with($line, 'The provider returned pages')) {
                $title = '';
                continue;
            }
            if (preg_match('#^https?://\S+#i', $line, $match)) {
                $url = rtrim($match[0], '.,);');
                $hits[] = $title !== '' ? $title . ' ' . $url : $url;
                $title = '';
                if (count($hits) >= 12) {
                    break;
                }
                continue;
            }
            $title = $line;
        }
        return $hits;
    }

    private function compactTool(string $name, string $text): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (str_contains($flat, 'No web results') || str_contains($flat, 'listing omitted') || strlen($flat) < 220) {
            return strlen($flat) > 240 ? substr($flat, 0, 240) . '…' : $flat;
        }
        if ($name === 'fetch_url' || $name === 'browse_url') {
            return clip_text($text, 900);
        }
        return clip_text($text, 1200);
    }

    private function traceBlock(string $name, string $hint, string $body): string
    {
        $head = $hint !== '' ? $name . ' (' . $hint . ')' : $name;
        return $head . ":\n" . $body;
    }

    private function toolHint(array $args): string
    {
        $bits = [];
        foreach (['action', 'symbol', 'query', 'url', 'path', 'database', 'table', 'name'] as $key) {
            $value = trim((string)($args[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
            if (strlen($value) > 160) {
                $value = substr($value, 0, 160) . '…';
            }
            $bits[] = $key . '=' . $value;
            if (count($bits) >= 3) {
                break;
            }
        }
        return implode(' ', $bits);
    }

    private function fitBlocks(array $blocks, int $budget): string
    {
        if ($budget < 80 || $blocks === []) {
            return '';
        }
        $kept = [];
        $used = 0;
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            $block = (string)$blocks[$i];
            $need = strlen($block) + 2;
            if ($used + $need > $budget) {
                $room = $budget - $used - 20;
                if ($room < 160) {
                    break;
                }
                $block = clip_text($block, $room);
                $need = strlen($block) + 2;
            }
            array_unshift($kept, $block);
            $used += $need;
            if ($used >= $budget) {
                break;
            }
        }
        return implode("\n\n", $kept);
    }

    private function fitText(string $text, int $budget): string
    {
        $text = trim($text);
        if ($text === '' || $budget < 80) {
            return '';
        }
        if (strlen($text) <= $budget) {
            return $text;
        }
        $head = (int)floor($budget * 0.55);
        $tail = max(0, $budget - $head - 20);
        return rtrim(substr($text, 0, $head)) . "\n…\n" . ltrim(substr($text, -$tail));
    }
}
