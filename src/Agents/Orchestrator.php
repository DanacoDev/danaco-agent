<?php

class Orchestrator
{
    public function __construct(
        private AgentRuntime $runtime,
        private SubagentRunner $subagents,
        private Database $db,
        private MemoryManager $memory,
        private SkillEngine $skills
    ) {
    }

    public function handle(int $projectId, callable $emit, ?callable $shouldStop = null): string
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        $packed = $this->prepare($projectId, $project, true, $shouldStop);
        if (!empty($packed['updated'])) {
            $this->db->addMessage($projectId, 'notice', ContextWindow::NOTICE, 'orchestrator');
            $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        }
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $messages = $packed['messages'];
        $last = $messages[count($messages) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $messages[] = ['role' => 'user', 'content' => 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.'];
        }
        return $this->runtime->run(
            'orchestrator',
            $messages,
            $this->tools($project, $emit, $projectId, $shouldStop),
            $emit,
            $this->runtime->maxTurns(),
            $projectId > 0 ? $projectId : null,
            $shouldStop,
            false,
            $this->scheduledRequest($messages),
            fn(array $running): array => $this->betweenTurns($running, $project, $emit, $projectId, $shouldStop),
            fn(array $running): array => $this->repackForFailsafe($running, $project, $emit, $shouldStop)
        );
    }

    public function contextUsage(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        $packed = $this->prepare($projectId, $project, false, null);
        return ['used' => $packed['used'], 'total' => $packed['total']];
    }

    public function contextForDownload(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        $packed = $this->prepare($projectId, $project, true, null);
        return $packed['messages'];
    }

    public function compressContext(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        if ($this->db->hasRunningJob($projectId)) {
            $this->db->setSetting('context_compress_pending_' . max(0, $projectId), '1');
            $usage = $this->contextUsage($projectId);
            return [
                'used' => $usage['used'],
                'total' => $usage['total'],
                'compressed' => false,
                'deferred' => true,
            ];
        }
        $packed = $this->prepare($projectId, $project, true, null, true);
        if (!empty($packed['updated'])) {
            $this->db->addMessage($projectId, 'notice', ContextWindow::NOTICE, 'orchestrator');
        }
        return [
            'used' => $packed['used'],
            'total' => $packed['total'],
            'compressed' => !empty($packed['compressed']),
        ];
    }

    private function prepare(int $projectId, ?array $project, bool $writeSummary, ?callable $shouldStop, bool $activate = false): array
    {
        $history = [];
        foreach ($this->db->messages($projectId, 100) as $row) {
            if (!in_array($row['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $history[] = [
                'id' => (int)($row['id'] ?? 0),
                'role' => $row['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $this->stamped($row),
            ];
        }
        $total = $this->contextTotal();
        $system = $this->systemPrompt($project);
        $manual = $activate;
        $last = $history[count($history) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $manual = false;
        }
        $split = ContextWindow::split($system, $history, $total, $manual);
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $keep = !empty($split['compressed']) ? $split['kept'] : $history;
            $messages = [['role' => 'system', 'content' => $system]];
            foreach ($keep as $message) {
                $messages[] = [
                    'role' => $message['role'],
                    'content' => $message['content'],
                ];
            }
            return [
                'messages' => $messages,
                'used' => $split['used'],
                'total' => $split['total'],
                'compressed' => false,
                'updated' => false,
            ];
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $summary = $this->memory->readContextSummary($folder);
        $untilKey = 'context_summary_until_' . max(0, $projectId);
        $until = (int)$this->db->getSetting($untilKey, '0');
        $updated = false;
        if ($split['compressed'] && $writeSummary) {
            $fresh = [];
            foreach ($split['dropped'] as $message) {
                if ((int)($message['id'] ?? 0) > $until) {
                    $fresh[] = $message;
                }
            }
            if ($fresh) {
                $summary = $this->summarize($summary, $fresh, $shouldStop);
                if ($summary !== '') {
                    $this->memory->writeContextSummary($folder, $summary);
                    $last = (int)$fresh[count($fresh) - 1]['id'];
                    $this->db->setSetting($untilKey, (string)$last);
                    $updated = true;
                }
            }
        }
        if ($split['compressed'] && trim($summary) !== '') {
            $packed = ContextWindow::apply($system, $summary, $split['kept'], $total, $split['used']);
            $packed['updated'] = $updated;
            return $packed;
        }
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }
        return [
            'messages' => $messages,
            'used' => $split['used'],
            'total' => $split['total'],
            'compressed' => false,
            'updated' => false,
        ];
    }

    private function betweenTurns(array $messages, ?array $project, callable $emit, int $projectId, ?callable $shouldStop): array
    {
        $pendingKey = 'context_compress_pending_' . max(0, $projectId);
        $pending = $this->db->getSetting($pendingKey, '0') === '1';
        $last = $messages[count($messages) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            return $messages;
        }
        $system = (string)($messages[0]['content'] ?? '');
        $rest = array_slice($messages, 1);
        $total = $this->contextTotal();
        $split = ContextWindow::split($system, $rest, $total, $pending);
        if (!$split['compressed']) {
            if ($pending) {
                $this->db->setSetting($pendingKey, '0');
            }
            return $messages;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $summary = $this->summarize($this->memory->readContextSummary($folder), $split['dropped'], $shouldStop);
        if (trim($summary) === '') {
            return $messages;
        }
        $this->memory->writeContextSummary($folder, $summary);
        $this->db->setSetting($pendingKey, '0');
        $packed = ContextWindow::apply($system, $summary, $split['kept'], $total, $split['used']);
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        return $packed['messages'];
    }

    private function repackForFailsafe(array $messages, ?array $project, callable $emit, ?callable $shouldStop): array
    {
        $system = (string)($messages[0]['content'] ?? '');
        $rest = array_slice($messages, 1);
        $total = $this->contextTotal();
        $split = ContextWindow::fit($system, $rest, $total);
        if (empty($split['compressed'])) {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $summary = $this->summarize($this->memory->readContextSummary($folder), $split['dropped'], $shouldStop);
        if (trim($summary) === '') {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $this->memory->writeContextSummary($folder, $summary);
        $packed = ContextWindow::apply($system, $summary, $split['kept'], $total, $split['used']);
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        return $packed['messages'];
    }

    private function contextTotal(): int
    {
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if (app_services()['llm']->usingFailsafe) {
            $mode = 'cpu_failsafe';
        }
        return app_services()['hardware']->effectiveContext($mode);
    }

    private function toolWasCutOff(string $content): bool
    {
        $markers = ['<<tool>>', '<tool>>', '<<tool>', '<tool>', '[[tool]]', '[tool]]', '[[tool]', '[tool]'];
        $pos = false;
        foreach ($markers as $marker) {
            $at = strrpos($content, $marker);
            if ($at !== false && ($pos === false || $at > $pos)) {
                $pos = $at;
            }
        }
        if ($pos === false) {
            return false;
        }
        $rest = substr($content, $pos);
        foreach (['<<end>>', '<end>>', '<<end>', '<end>', '[[end]]', '[end]]', '[[end]', '[end]', '[[/tool]]', '[/tool]]', '[[/tool]', '[/tool]', '<</tool>>', '</tool>>', '<</tool>', '</tool>'] as $end) {
            if (str_contains($rest, $end)) {
                return false;
            }
        }
        return true;
    }

    private function summarize(string $previous, array $fresh, ?callable $shouldStop): string
    {
        $window = $this->contextTotal();
        if ($window <= 8192) {
            return $this->summarizeForWindow($previous, $fresh, $shouldStop, $window);
        }
        $chunks = [];
        $buffer = '';
        foreach ($fresh as $message) {
            $who = ($message['role'] ?? '') === 'assistant' ? 'Agent' : 'User';
            $line = $who . ': ' . clip_text(trim((string)($message['content'] ?? '')), 1500) . "\n\n";
            if ($buffer !== '' && ContextWindow::tokens($buffer . $line) > 1200) {
                $chunks[] = $buffer;
                $buffer = '';
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $summary = trim($previous);
        foreach ($chunks as $chunk) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $summary = $this->summarizeChunk($summary, $chunk, $shouldStop);
        }
        return trim($summary);
    }

    private function summarizeForWindow(string $previous, array $fresh, ?callable $shouldStop, int $window): string
    {
        $piece = max(160, (int)floor(($window - 700) / 2));
        $chunks = [];
        $buffer = '';
        foreach ($fresh as $message) {
            $who = ($message['role'] ?? '') === 'assistant' ? 'Agent' : 'User';
            $line = $who . ': ' . clip_text(trim((string)($message['content'] ?? '')), 1500) . "\n\n";
            if (ContextWindow::tokens($line) > $piece) {
                $line = clip_text($line, max(200, $piece * 4));
            }
            if ($buffer !== '' && ContextWindow::tokens($buffer . $line) > $piece) {
                $chunks[] = $buffer;
                $buffer = '';
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $partials = [];
        foreach ($chunks as $chunk) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $partials[] = $this->summarizeChunk('', $chunk, $shouldStop);
        }
        $summary = $this->mergePartials($partials, $shouldStop, $piece);
        $previous = trim($previous);
        if ($previous !== '' && ContextWindow::tokens($previous) > $piece) {
            $previous = clip_text($previous, max(200, $piece * 4));
        }
        if ($previous === '') {
            return trim($summary);
        }
        if ($summary === '') {
            return $previous;
        }
        return trim($this->summarizeChunk($previous, $summary, $shouldStop));
    }

    private function mergePartials(array $partials, ?callable $shouldStop, int $piece): string
    {
        $clean = [];
        foreach ($partials as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        if (!$clean) {
            return '';
        }
        if (count($clean) === 1) {
            return $clean[0];
        }
        $groups = [];
        $buffer = '';
        foreach ($clean as $part) {
            $line = $part . "\n\n";
            if ($buffer !== '' && ContextWindow::tokens($buffer . $line) > $piece) {
                $groups[] = $buffer;
                $buffer = '';
            }
            if (ContextWindow::tokens($line) > $piece) {
                $line = clip_text($line, max(200, $piece * 4));
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $groups[] = $buffer;
        }
        if (count($groups) === 1) {
            return trim($this->summarizeChunk('', $groups[0], $shouldStop));
        }
        $merged = [];
        foreach ($groups as $group) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $merged[] = $this->summarizeChunk('', $group, $shouldStop);
        }
        if (count($merged) >= count($clean)) {
            return trim(clip_text(implode("\n\n", $merged), max(200, $piece * 4)));
        }
        return $this->mergePartials($merged, $shouldStop, $piece);
    }

    private function summarizeChunk(string $previous, string $chunk, ?callable $shouldStop): string
    {
        $instructions = <<<TXT
You compress older chat history into a short record the agent can reuse.
Keep decisions, results, names, dates, numbers, file paths, and open questions.
Prefer what was said and what was produced. Leave out the reasoning that led there.
Do not add anything that is not in the text.
Do not call tools.
Use these sections: Decisions, Results, Facts, Open questions.
Stay under 350 words.
TXT;
        $source = $chunk;
        if ($previous !== '') {
            $source = "Previous summary:\n" . $previous . "\n\nNew messages to fold in:\n" . $chunk;
        }
        try {
            $text = app_services()['llm']->stream([
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $source],
            ], static function (): void {
            }, $shouldStop);
            $text = trim($text);
            if ($text !== '') {
                return clip_text($text, 6000);
            }
        } catch (ChatStopped $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->db->logAgent('orchestrator', null, 'error', 'Context summary failed: ' . $e->getMessage());
        }
        $excerpt = trim($previous . "\n\n" . clip_text($chunk, 2500));
        return trim($excerpt);
    }

    private function systemPrompt(?array $project): string
    {
        if ($project === null) {
            $context = <<<TXT
This is General chat. There is no project tag.
Answer the user directly. Do not invent a project tag.
Create a project only when the user asks for one.
TXT;
        } else {
            $folder = $project['folder_name'];
            $context = <<<TXT
Assigned project folder: {$folder}
Assigned project name: {$project['name']}
Store project markdown only in {$folder}/wiki/. The description file is wiki/{$folder}_desc.md. It holds only the general description: what the project is, contributors, credentials, and main goals. Deep research, findings, and specific data get their own markdown file in that folder, such as wiki/research.md, or a SQLite database in database/. wiki/notes.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file and not on the kanban.
When you create or cite a file, put its path alone on a line, such as wiki/notes.md or report.pdf. The chat adds a download button at the top right of that line.
When this chat settles conclusions, decisions, or facts worth keeping, read the matching wiki file and then write or edit it with wiki_write before you finish. Keep doing this as the chat continues and those conclusions change. Do not wait for the end of a long task, and do not leave the record only in the chat.
Tag every delegation exactly as project: {$folder}
TXT;
        }
        $now = app_local_stamp();
        $state = $this->db->consultantState();
        $consultLine = '';
        if ($state['active']) {
            $consultLine = "- consult: args instruction. Paid external Consultant. Last resort only, for a complicated or multi-step assignment the team should not handle alone. It costs the user money.\n";
            $consultNote = "The Consultant is on. Use consult only as a last resort. Each call costs the user money.\n";
        } elseif (($state['reason'] ?? '') === 'no_key') {
            $consultNote = "The Consultant exists, but you cannot call it until an API key is saved. It is a paid external model for a hard multi-step problem the team should not handle alone, and it can use the same project file tools. If that would help, tell the user that adding a key on the Setup page, under External LLM provider, and using that API for the Consultant, gives the agent superpowers on hard work. Do not describe the Consultant as missing.\n";
        } else {
            $consultNote = "The Consultant exists, but it is turned off, so you cannot call it. It is a paid external model for a hard multi-step problem, and it can use the same project file tools. If a task is too hard for the team, tell the user they can turn the Consultant on from the Agents page. It costs tokens while it is on. Do not describe the Consultant as missing.\n";
        }
        $team = $this->subagents->teamBrief($project ? (int)$project['id'] : 0);
        $full = $this->db->fullSystemControl();
        $controlNote = $full
            ? "Full system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer the assigned project when there is one. When this chat is not tied to a project, do not invent paths or settings. Guessing can hallucinate and can break the computer.\n"
            : '';
        $installNote = "Only you may install dependencies on this machine. The chat asks the user before an install runs. Subagents are stopped if they try. If one reports a missing install, run it yourself only after the user agrees.\n";
        $fileTools = '';
        if ($project !== null) {
            $fileTools = <<<TXT
- list_files: args path, optional. Lists this project's files.
- read_file: args path. Any file in this project, including the wiki.
- read_pdf: args path. Reads the text of a PDF in this project.
- write_file: args path, content. Creates or overwrites a file in this project. Markdown belongs in wiki/.
- delete_file: args path. Moves a file or folder out of the project into the trash above it. path is relative, such as projects. Cannot delete backups or trash.
- move_file: args from, to. Moves a file or folder inside this project.
A PDF is not written in this chat. Delegate it to the writer with the file name and the brief. The coder does not make PDFs.

TXT;
            if ($full) {
                $fileTools .= "- run_command: args command. May use sudo. A path that starts with / is anywhere on this computer.\n";
            }
        } elseif ($full) {
            $fileTools = <<<TXT
- list_files: args path. A full path, such as /Users/name. Lists that folder.
- read_file: args path. A full path to a file.
- read_pdf: args path. A full path to a PDF.
- write_file: args path, content. A full path. Creates or overwrites that file.
- delete_file: args path. A full path. Moves that file or folder to the trash.
- move_file: args from, to. Both full paths.
- run_command: args command. May use sudo.

TXT;
        }
        $rules = <<<TXT
{$context}
{$controlNote}{$installNote}Local time now: {$now}
Each message from the user starts with the local time it was sent, in brackets. Trust that time. Do not repeat the brackets in your reply.

{$team}

{$consultNote}When you need a tool, stop after this block so the system can run it:
<<tool>>
{"name":"tool_name","args":{}}
<<end>>
Naming a tool in a list or sentence does nothing. Only the <<tool>> block runs it.

Tools:
- delegate: args agent (researcher, writer, coder, analyst, or marketer), instruction, project, task_id. One subagent at a time. Pick the agent from the team section. Delegating a card moves it to in_progress. Pass task_id when that card already exists. When it returns, list the kanban and update the matching cards before the next step, even if you have no recollection of any tasks.
{$fileTools}
{$consultLine}- kanban: the task board. args action (list, create, move, or delete).
  list: no other args.
  create: title, description, agent, status, schedule_time (YYYY-MM-DD HH:MM), recurrence (none, 5min, 15min, 30min, hourly, daily, weekly, or monthly).
  move: id, plus any of status, description, result, agent, schedule_time, recurrence.
  delete: id. Removes that card from the board. Use it when a card is wrong, stale, or the user asks you to remove it.
  backlog = later agent work with no schedule. in_progress = work happening now, or a card with a time or a repeat. review = finished and waiting for the user; include result. You cannot set done.
  Cards are for an agent, or a reminder that will send something or take an action. Not a static todo for the user.
  Every future event needs its own card with schedule_time before you reply. Saying you will do it later does not schedule it. Split several events into separate cards. When the work repeats, set recurrence and have the coder write a skill for the repeated steps when that is possible. Name that skill in the card description.
  A result is assigned to the subagent who produces it. Planning with the user is assigned to orchestrator.
  Move a task to in_progress when you start it, and to review when you finish. Do not leave finished work in backlog.
  After every delegated job, list the board and update the matching cards before you reply or delegate again, even when this chat does not mention any tasks. A cleared context still has cards. Do not explain the workflow in place of updating them.
- create_project: args name, folder, purpose, type
- wiki_search: args query
- wiki_read: args name. Reads one project wiki file. Omit name to read wiki/<folder>_desc.md, which is only the general description. General chat has no project wiki.
- wiki_write: args name, content, append. Writes wiki/<name>.md in the current project. Omit name only to edit the general description: what the project is, contributors, credentials, and main goals. Pass name for research, findings, and specific data. Read the file first when you are changing it. Use append to add to what is already there. When the chat has reached conclusions, update the matching wiki file in this same turn.
- wiki_reindex: no args
- list_vault: no args. Backups and trash. Read or restore only. Never delete them.
- read_vault: args kind (backup or trash), stored_as
- restore_file: args kind, stored_as
- write_skill: args name, description, filename, source. filename is a lowercase .php file. source is PHP that reads JSON from stdin. Saves to data/skills/, not the project. Cannot replace a built-in skill. It then checks that the file parses. If the check fails, the skill is not kept. Send the complete source again. Do not end the file with a closing PHP tag. Delegate this to the coder. Call it yourself when the coder cannot finish.
- soul: args action (add or remove), text. One behaviour line for this project: a role, a title, a mission, how to present output, or a standing always or never. Not what the project is about. General chat has no soul file. Open a project first.

Skills, one line each:
TXT;
        $lines = [];
        $sawEmail = false;
        foreach ((new CapabilityTools($this->db))->descriptions($project ? (int)$project['id'] : 0) as $line) {
            if (preg_match('/^- (send_email|ftp|mcp|remember):/', $line)) {
                $lines[] = $line;
                if (str_starts_with($line, '- send_email:')) {
                    $sawEmail = true;
                }
            }
        }
        if (!$sawEmail) {
            $lines[] = '- send_email: this chat has no mailbox. Say that a mailbox is added on the Setup page, under Email, and linked to this project. There is no Tools tab. Do not describe it as a missing capability.';
        }
        $extra = $lines ? implode("\n", $lines) . "\n" : '';
        $rules = str_replace("Skills, one line each:", $extra . "Skills, one line each:", $rules);
        $tail = Prompts::ORCHESTRATOR . "\n\n" . $rules . "\n" . $this->skills->summariesForPrompt();
        $notes = $this->memory->toolNotesForPrompt();
        if ($notes !== '') {
            $tail .= "\n\n" . $notes;
        }
        if ($project !== null) {
            $soul = Projects::soulPrompt((string)$project['folder_name']);
            if ($soul !== '') {
                $tail .= "\n\n" . $soul;
            }
        }
        return $tail;
    }

    private function scheduledRequest(array $messages): bool
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            return str_starts_with(ltrim((string)($messages[$i]['content'] ?? '')), 'Scheduled task:');
        }
        return false;
    }

    private function stamped(array $row): string
    {
        $content = clip_text((string)($row['content'] ?? ''), 8000);
        if (($row['role'] ?? '') !== 'user' || str_starts_with($content, 'Scheduled task:')) {
            return $content;
        }
        return '[' . app_local_stamp((string)($row['created_at'] ?? '')) . "]\n" . $content;
    }

    private function confirmInstall(string $command, callable $emit, ?callable $shouldStop): bool
    {
        $id = bin2hex(random_bytes(8));
        $this->db->setSetting('install_ask_' . $id, '');
        $emit(['type' => 'install_prompt', 'id' => $id, 'command' => $command]);
        $deadline = time() + 180;
        while (time() < $deadline) {
            if ($shouldStop && $shouldStop()) {
                return false;
            }
            $answer = (string)$this->db->getSetting('install_ask_' . $id, '');
            if ($answer === 'yes') {
                return true;
            }
            if ($answer === 'no') {
                return false;
            }
            usleep(300000);
        }
        return false;
    }

    private function tools(?array $project, callable $emit, int $projectId, ?callable $shouldStop): array
    {
        $folder = is_array($project) ? (string)$project['folder_name'] : '';
        $createdTaskIds = [];
        $tools = [
            'delegate' => function (array $args) use ($folder, $emit, $projectId, $shouldStop, &$createdTaskIds): string {
                if ($folder === '') {
                    return 'General chat has no project. Create a project before delegating.';
                }
                $agent = strtolower(trim((string)($args['agent'] ?? '')));
                $instruction = trim((string)($args['instruction'] ?? ''));
                $tagged = trim((string)($args['project'] ?? $folder));
                if ($tagged !== $folder) {
                    return 'Delegation rejected. The project tag must be project: ' . $folder;
                }
                if (!in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer'], true)) {
                    return 'Unknown subagent.';
                }
                if ($instruction === '') {
                    return 'The subagent needs an instruction.';
                }
                $note = '';
                try {
                    $note = $this->startDelegatedTask($projectId, $agent, $instruction, $args, $createdTaskIds);
                } catch (Throwable $e) {
                    $note = '';
                }
                $emit(['type' => 'delegation', 'agent' => $agent, 'project' => $folder, 'text' => $instruction]);
                $jobId = $this->db->startJob('subagent', $projectId > 0 ? $projectId : null, [
                    'agent' => $agent,
                    'folder' => $folder,
                    'instruction' => $instruction,
                ]);
                $watch = function () use ($shouldStop, $jobId): bool {
                    $this->db->touchJob($jobId);
                    return $shouldStop && $shouldStop();
                };
                try {
                    $result = $this->subagents->run($agent, $folder, $instruction, $emit, $projectId, $watch);
                    $this->db->finishJob($jobId);
                    return $result . $note;
                } catch (ChatStopped $e) {
                    $this->db->finishJob($jobId);
                    throw $e;
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === 'Only one subagent can run at a time.') {
                        $this->db->finishJob($jobId);
                        return 'Only one subagent can run at a time. Wait for the current one to finish, then delegate the next task.' . $note;
                    }
                    $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                    $notice = agent_error($agent, $e->getMessage());
                    $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                    return $notice . $note;
                } catch (Throwable $e) {
                    $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                    $notice = agent_error($agent, $e->getMessage());
                    $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                    return $notice . $note;
                }
            },
            'consult' => function (array $args) use ($folder, $emit, $projectId, $shouldStop): string {
                if (!$this->db->consultantActive()) {
                    $reason = (string)($this->db->consultantState()['reason'] ?? '');
                    if ($reason === 'no_key') {
                        return 'The Consultant has no API key. Tell the user to add one on the Setup page, under External LLM provider, and use that API for the Consultant.';
                    }
                    return 'The Consultant is turned off. Tell the user they can turn it on from the Agents page. It costs tokens while it is on.';
                }
                $instruction = trim((string)($args['instruction'] ?? ''));
                if ($instruction === '') {
                    return 'The Consultant needs an assignment.';
                }
                $emit(['type' => 'delegation', 'agent' => 'consultant', 'project' => $folder !== '' ? $folder : 'general', 'text' => $instruction]);
                try {
                    return $this->subagents->consult($folder, $instruction, $emit, $projectId, $shouldStop);
                } catch (ChatStopped $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $notice = agent_error('consultant', $e->getMessage());
                    $emit(['type' => 'token', 'agent' => 'consultant', 'text' => $notice]);
                    return $notice;
                }
            },
            'kanban' => function (array $args) use ($projectId, &$createdTaskIds): string {
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'create_task' => function (array $args) use ($projectId, &$createdTaskIds): string {
                $args['action'] = 'create';
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'list_tasks' => function () use ($projectId): string {
                return $this->kanban($projectId, ['action' => 'list']);
            },
            'list_files' => function (array $args) use ($folder): string {
                $path = (string)($args['path'] ?? '');
                $blocked = $this->needsProject($folder, $path, 'Open a project before listing its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $tree = (new SafetyInterceptor(app_config()['data_dir']))->listFiles($folder, $path);
                return json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            },
            'read_file' => function (array $args) use ($folder): string {
                $path = (string)($args['path'] ?? '');
                $blocked = $this->needsProject($folder, $path, 'Open a project before reading its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return (new SafetyInterceptor(app_config()['data_dir']))->readFile($folder, $path);
            },
            'read_pdf' => function (array $args) use ($folder): string {
                $path = (string)($args['path'] ?? '');
                $blocked = $this->needsProject($folder, $path, 'Open a project before reading a PDF.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return PdfFile::readProject(new SafetyInterceptor(app_config()['data_dir']), $folder, $path);
            },
            'create_pdf' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'Open a project before creating a PDF.';
                }
                return 'Do not write the PDF in this chat. Delegate it to the writer with the file name and the brief. The writer calls create_pdf in its own context, so a long document does not fill this chat. The coder is for software, not for PDFs.';
            },
            'write_file' => function (array $args) use ($folder): string {
                $path = (string)($args['path'] ?? '');
                $blocked = $this->needsProject($folder, $path, 'Open a project before writing its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $written = (new SafetyInterceptor(app_config()['data_dir']))->writeFile($folder, $path, (string)($args['content'] ?? ''));
                return 'Wrote ' . $written['path'] . ($written['backed_up'] ? ' (previous version backed up).' : '.');
            },
            'delete_file' => function (array $args) use ($folder): string {
                $path = (string)($args['path'] ?? '');
                $blocked = $this->needsProject($folder, $path, 'Open a project before deleting its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $meta = (new SafetyInterceptor(app_config()['data_dir']))->deleteFile($folder, $path);
                $kind = !empty($meta['is_dir']) ? 'folder ' : '';
                $where = str_starts_with((string)$meta['relative_path'], '/') ? 'the trash.' : 'the trash above the project.';
                return 'Moved ' . $kind . $meta['relative_path'] . ' to ' . $where;
            },
            'move_file' => function (array $args) use ($folder): string {
                $from = (string)($args['from'] ?? '');
                $blocked = $this->needsProject($folder, $from, 'Open a project before moving its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $moved = (new SafetyInterceptor(app_config()['data_dir']))->moveFile($folder, $from, (string)($args['to'] ?? ''));
                return 'Moved ' . $moved['from'] . ' to ' . $moved['to'] . '.';
            },
            'run_command' => function (array $args) use ($folder, $emit, $shouldStop): string {
                $command = trim((string)($args['command'] ?? ''));
                $tools = new CapabilityTools($this->db);
                if ($tools->isInstallCommand($command)) {
                    if (PHP_SAPI === 'cli') {
                        return 'Install stopped. A scheduled run cannot ask the user. Do not install it.';
                    }
                    if (!$this->confirmInstall($command, $emit, $shouldStop)) {
                        return 'The user did not allow this install. Do not install it.';
                    }
                    return $tools->handlers($folder, 0, true)['run_command']($args);
                }
                if (!$this->db->fullSystemControl()) {
                    return 'Full system control is off.';
                }
                return $tools->handlers($folder)['run_command']($args);
            },
            'create_project' => function (array $args): string {
                $created = Projects::create(
                    $this->db,
                    (string)($args['name'] ?? ''),
                    (string)($args['folder'] ?? ''),
                    (string)($args['purpose'] ?? ''),
                    (string)($args['type'] ?? '')
                );
                return 'Created project ' . $created['folder_name'];
            },
            'wiki_search' => function (array $args) use ($folder): string {
                return $this->memory->search((string)($args['query'] ?? ''), $folder !== '' ? $folder : null);
            },
            'wiki_read' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'General chat has no project wiki.';
                }
                try {
                    $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                try {
                    return (new SafetyInterceptor(app_config()['data_dir']))->readFile($folder, $relative, 100000);
                } catch (Throwable $e) {
                    return 'That wiki file does not exist yet.';
                }
            },
            'wiki_write' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'General chat has no project wiki. Open a project before writing project notes.';
                }
                try {
                    $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                $content = (string)($args['content'] ?? '');
                $safety = new SafetyInterceptor(app_config()['data_dir']);
                if (!empty($args['append'])) {
                    try {
                        $existing = $safety->readFile($folder, $relative, 100000);
                    } catch (Throwable $e) {
                        $existing = '';
                    }
                    $content = rtrim($existing) . "\n\n" . $content;
                }
                $written = $safety->writeFile($folder, $relative, $content);
                return 'Updated ' . $written['path'];
            },
            'wiki_reindex' => function (): string {
                return $this->memory->update();
            },
            'list_vault' => function (): string {
                return (new SafetyInterceptor(app_config()['data_dir']))->vaultListing();
            },
            'read_vault' => function (array $args): string {
                return (new SafetyInterceptor(app_config()['data_dir']))->readVault((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
            },
            'restore_file' => function (array $args): string {
                $result = (new SafetyInterceptor(app_config()['data_dir']))->restore((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
                return 'Restored ' . $result['restored'] . ' into ' . $result['project_folder'] . '.';
            },
            'write_skill' => function (array $args): string {
                $name = (string)($args['name'] ?? '');
                $filename = (string)($args['filename'] ?? ($name !== '' ? $name . '.php' : ''));
                try {
                    $skill = $this->skills->saveSource(
                        $filename,
                        (string)($args['source'] ?? ''),
                        $name,
                        (string)($args['description'] ?? ''),
                        true,
                        'orchestrator'
                    );
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
                return 'Saved skill ' . $skill['name'] . ' to data/skills/' . $skill['script_path'] . '. Syntax check passed.';
            },
            'send_email' => function (array $args) use ($projectId): string {
                return (new CapabilityTools($this->db))->sendForProject($projectId, $args);
            },
            'ftp' => function (array $args) use ($projectId, $folder): string {
                return (new CapabilityTools($this->db))->ftpForProject($projectId, $folder, $args);
            },
            'mcp' => function (array $args): string {
                return (new CapabilityTools($this->db))->mcp($args);
            },
            'remember' => function (array $args): string {
                return $this->memory->remember((string)($args['tool'] ?? ''), (string)($args['note'] ?? ''));
            },
            'soul' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'Open a project before saving how you should act.';
                }
                $action = strtolower(trim((string)($args['action'] ?? 'add')));
                $text = (string)($args['text'] ?? '');
                if ($action === 'remove') {
                    return Projects::removeSoulLine($folder, $text);
                }
                if ($action !== 'add') {
                    return 'Use action add or remove.';
                }
                return Projects::addSoulLine($folder, $text);
            },
        ];
        if ($folder !== '') {
            foreach ($this->skills->activeHandlers($folder) as $name => $handler) {
                if (!isset($tools[$name])) {
                    $tools[$name] = $handler;
                }
            }
        }
        return $tools;
    }

    private function startDelegatedTask(int $projectId, string $agent, string $instruction, array $args, array &$createdTaskIds): string
    {
        if ($projectId <= 0) {
            return '';
        }
        $board = new Kanban($this->db);
        $id = (int)($args['task_id'] ?? $args['task'] ?? 0);
        if ($id <= 0 && preg_match_all('/#(\d+)/', $instruction, $found)) {
            $unique = array_values(array_unique(array_map('intval', $found[1])));
            if (count($unique) === 1) {
                $id = $unique[0];
            }
        }
        $open = null;
        if ($id > 0) {
            $task = $this->db->task($id);
            if ($task && (int)$task['project_id'] === $projectId && !in_array($board->column($task), ['review', 'done'], true)) {
                $open = $task;
            }
        }
        if ($open === null && count($createdTaskIds) === 1) {
            $task = $this->db->task((int)$createdTaskIds[0]);
            if ($task && (int)$task['project_id'] === $projectId && !in_array($board->column($task), ['review', 'done'], true)) {
                $open = $task;
            }
        }
        if ($open !== null) {
            $saved = $board->move((int)$open['id'], ['status' => 'in_progress', 'agent' => $agent], false);
            return "\nTask #" . $saved['id'] . ' is in in_progress.';
        }
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '' || strlen($title) > 200) {
            return '';
        }
        foreach ($this->db->tasks($projectId) as $task) {
            if (strcasecmp(trim((string)$task['title']), $title) !== 0) {
                continue;
            }
            if (in_array($board->column($task), ['review', 'done'], true)) {
                continue;
            }
            $saved = $board->move((int)$task['id'], ['status' => 'in_progress', 'agent' => $agent], false);
            return "\nTask #" . $saved['id'] . ' is in in_progress.';
        }
        $saved = $board->create($projectId, [
            'title' => $title,
            'description' => $instruction,
            'agent' => $agent,
            'status' => 'in_progress',
        ], false);
        $createdTaskIds[] = (int)$saved['id'];
        return "\nCreated task #" . $saved['id'] . ' in in_progress.';
    }

    private function kanban(int $projectId, array $args, ?array &$createdTaskIds = null): string
    {
        $board = new Kanban($this->db);
        $action = strtolower(trim((string)($args['action'] ?? 'list')));
        try {
            if ($projectId <= 0) {
                return $action === 'list' ? 'Open a project to see its tasks.' : 'Tasks belong to a project. Create or open a project first.';
            }
            if ($action === 'list') {
                return $board->listing($projectId);
            }
            if ($action === 'create') {
                $task = $board->create($projectId, $args, false);
                if ($createdTaskIds !== null) {
                    $createdTaskIds[] = (int)$task['id'];
                }
                return 'Created task #' . $task['id'] . ' in ' . $board->column($task) . ': ' . $task['title'];
            }
            if ($action === 'move' || $action === 'update') {
                $task = $board->move((int)($args['id'] ?? 0), $args, false);
                return 'Moved task #' . $task['id'] . ' to ' . $board->column($task) . ': ' . $task['title'];
            }
            if ($action === 'delete') {
                $id = (int)($args['id'] ?? 0);
                $task = $this->db->task($id);
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
        return 'Unknown kanban action. Use list, create, move, or delete.';
    }

    private function needsProject(string $folder, string $path, string $message): ?string
    {
        if ($folder !== '') {
            return null;
        }
        if (!$this->db->fullSystemControl()) {
            return $message;
        }
        if (!str_starts_with(str_replace('\\', '/', trim($path)), '/')) {
            return 'Use a full path, such as /Users/name/file.';
        }
        return null;
    }
}
