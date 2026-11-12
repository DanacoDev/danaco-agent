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

    public function handle(int $projectId, callable $emit, ?callable $shouldStop = null, bool $scheduled = false): string
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        $this->db->waitContextCompress($projectId, $shouldStop);
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        $waitingOnTask = $this->db->taskRunning();
        if ($waitingOnTask) {
            $emit(['type' => 'phase', 'phase' => 'scheduled']);
        }
        $packed = $this->prepare($projectId, $project, true, $shouldStop, false, $emit);
        if (!empty($packed['updated'])) {
            $this->db->addMessage($projectId, 'notice', ContextWindow::NOTICE, 'orchestrator');
            $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        }
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $messages = $packed['messages'];
        if (!empty($packed['updated'])) {
            $this->db->loop('orchestrator', 'context', count($messages) . ' msgs · summary updated', $projectId > 0 ? $projectId : null);
        }
        $last = $messages[count($messages) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $this->db->loop('orchestrator', 'steer', 'cutoff · carried into this run', $projectId > 0 ? $projectId : null);
            $messages[] = ['role' => 'user', 'content' => 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.'];
        }
        if ($this->db->taskRunning()) {
            $emit(['type' => 'phase', 'phase' => 'scheduled']);
        } elseif ($waitingOnTask) {
            $emit(['type' => 'phase', 'phase' => '']);
        }
        return $this->runtime->run(
            'orchestrator',
            $messages,
            $this->tools($project, $emit, $projectId, $shouldStop, $scheduled),
            $emit,
            $this->runtime->maxTurns(),
            $projectId > 0 ? $projectId : null,
            $shouldStop,
            false,
            $scheduled,
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

    private function prepare(int $projectId, ?array $project, bool $writeSummary, ?callable $shouldStop, bool $activate = false, ?callable $emit = null): array
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
        $history = ContextWindow::stripLookups($history);
        $total = $this->contextTotal();
        $system = $this->systemPrompt($project);
        $pendingKey = 'context_compress_pending_' . max(0, $projectId);
        $pending = $this->db->getSetting($pendingKey, '0') === '1';
        $manual = $activate || ($writeSummary && $pending);
        $last = $history[count($history) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $manual = false;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $summary = $this->memory->readContextSummary($folder);
        $until = (int)$this->db->getSetting('context_summary_until_' . max(0, $projectId), '0');
        $live = [];
        foreach ($history as $message) {
            if (trim($summary) === '' || (int)($message['id'] ?? 0) > $until) {
                $live[] = $message;
            }
        }
        $measureSystem = $system;
        if (trim($summary) !== '') {
            $measureSystem .= "\n\nEarlier messages were summarized below. Treat that summary as the record of them. Do not invent details that are not in the summary or the later messages. The same summary is saved in the wiki.\n\n" . $summary;
        }
        $split = ContextWindow::split($measureSystem, $live, $total, $manual);
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            $keep = !empty($split['compressed']) ? $split['kept'] : $live;
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
        if ($split['compressed'] && $writeSummary) {
            $fresh = [];
            foreach ($split['dropped'] as $message) {
                if ((int)($message['id'] ?? 0) > $until) {
                    $fresh[] = $message;
                }
            }
            if ($fresh) {
                if ($emit) {
                    $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
                }
                $packed = $this->finishCompression($projectId, $folder, $system, $fresh, $split['kept'], $summary, $total, $shouldStop, $emit);
                if ($packed !== null) {
                    $this->db->setSetting($pendingKey, '0');
                    return $packed;
                }
            }
        }
        if ($writeSummary && $pending) {
            $this->db->setSetting($pendingKey, '0');
            if ($emit) {
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
        }
        if (trim($summary) !== '') {
            $packed = ContextWindow::apply($system, $summary, $live, $total, $split['used']);
            $packed['compressed'] = false;
            $packed['updated'] = false;
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
        $before = count($messages);
        $pendingKey = 'context_compress_pending_' . max(0, $projectId);
        $pending = $this->db->getSetting($pendingKey, '0') === '1';
        $last = $messages[count($messages) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            return $messages;
        }
        $system = (string)($messages[0]['content'] ?? '');
        $rest = array_slice($messages, 1);
        $plain = 0;
        foreach ($rest as $message) {
            $plain += strlen((string)($message['content'] ?? ''));
        }
        $rest = ContextWindow::stripLookups($rest);
        $stripped = 0;
        foreach ($rest as $message) {
            $stripped += strlen((string)($message['content'] ?? ''));
        }
        $messages = array_merge([['role' => 'system', 'content' => $system]], $rest);
        $total = $this->contextTotal();
        $split = ContextWindow::split($system, $rest, $total, $pending);
        if (!$split['compressed']) {
            if ($pending) {
                $this->db->setSetting($pendingKey, '0');
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
            if ($stripped < $plain) {
                $emit(['type' => 'context', 'used' => ContextWindow::measure($system, $rest), 'total' => $total]);
            }
            return $messages;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $packed = $this->finishCompression($projectId, $folder, $system, $split['dropped'], $split['kept'], $this->memory->readContextSummary($folder), $total, $shouldStop, $emit);
        if ($packed === null) {
            if ($pending) {
                $this->db->setSetting($pendingKey, '0');
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
            return $messages;
        }
        $this->db->setSetting($pendingKey, '0');
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $after = count($packed['messages']);
        if ($after !== $before) {
            $this->db->loop('orchestrator', 'context', $before . ' → ' . $after . ' msgs', $projectId > 0 ? $projectId : null);
        }
        return $packed['messages'];
    }

    private function repackForFailsafe(array $messages, ?array $project, callable $emit, ?callable $shouldStop): array
    {
        $before = count($messages);
        $system = (string)($messages[0]['content'] ?? '');
        $rest = ContextWindow::stripLookups(array_slice($messages, 1));
        $messages = array_merge([['role' => 'system', 'content' => $system]], $rest);
        $total = $this->contextTotal();
        $split = ContextWindow::fit($system, $rest, $total);
        if (empty($split['compressed'])) {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $projectId = is_array($project) ? (int)($project['id'] ?? 0) : 0;
        $packed = $this->finishCompression($projectId, $folder, $system, $split['dropped'], $split['kept'], $this->memory->readContextSummary($folder), $total, $shouldStop, $emit);
        if ($packed === null) {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $after = count($packed['messages']);
        if ($after !== $before) {
            $this->db->loop('orchestrator', 'context', 'failsafe · ' . $before . ' → ' . $after . ' msgs');
        }
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

    private function finishCompression(
        int $projectId,
        ?string $folder,
        string $system,
        array $dropped,
        array $kept,
        string $summary,
        int $total,
        ?callable $shouldStop,
        ?callable $emit
    ): ?array {
        $kept = ContextWindow::stripLookups($kept);
        $dropped = ContextWindow::stripLookups($dropped);
        $this->db->holdContextCompress($projectId);
        try {
            $summary = $this->summarize($summary, $dropped, $shouldStop, $emit);
            if (trim($summary) === '' && $dropped !== [] && ContextWindow::summarySource($dropped) === []) {
                $summary = 'Earlier listings were omitted.';
            }
            if (trim($summary) === '') {
                return null;
            }
            $packed = ContextWindow::apply($system, $summary, $kept, $total, 0);
            $limit = (int)floor(max(512, $total) * 0.60);
            $start = ContextWindow::tailStart($kept);
            if ((int)$packed['used'] > $limit && $start > 0 && $start < count($kept)) {
                $extra = array_slice($kept, 0, $start);
                $kept = array_slice($kept, $start);
                $folded = $this->summarize($summary, $extra, $shouldStop, null);
                if (trim($folded) !== '') {
                    $summary = $folded;
                }
                $dropped = array_merge($dropped, $extra);
                $packed = ContextWindow::apply($system, $summary, $kept, $total, 0);
            }
            $this->memory->writeContextSummary($folder, $summary);
            $lastId = 0;
            foreach ($dropped as $message) {
                $id = (int)($message['id'] ?? 0);
                if ($id > $lastId) {
                    $lastId = $id;
                }
            }
            if ($lastId > 0) {
                $this->db->setSetting('context_summary_until_' . max(0, $projectId), (string)$lastId);
            }
            $packed['updated'] = true;
            $packed['summary'] = $summary;
            if ($emit) {
                $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
                $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
            }
            return $packed;
        } finally {
            $this->db->releaseContextCompress($projectId);
        }
    }

    private function summarize(string $previous, array $fresh, ?callable $shouldStop, ?callable $emit = null): string
    {
        if ($emit) {
            $emit(['type' => 'phase', 'phase' => 'compressing']);
        }
        $fresh = ContextWindow::summarySource($fresh);
        if (ContextWindow::isShort($fresh)) {
            return $this->mechanicalSummary($previous, $fresh);
        }
        return $this->summarizeForWindow($previous, $fresh, $shouldStop, $this->contextTotal());
    }

    private function mechanicalSummary(string $previous, array $fresh): string
    {
        $lines = [];
        foreach ($fresh as $message) {
            $text = trim((string)($message['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $who = ($message['role'] ?? '') === 'assistant' ? 'Agent' : 'User';
            $lines[] = $who . ': ' . $text;
        }
        $body = trim(implode("\n\n", $lines));
        $previous = trim($previous);
        if ($body === '') {
            return $previous;
        }
        if ($previous === '') {
            return clip_text($body, 6000);
        }
        return clip_text($previous . "\n\n" . $body, 6000);
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

    private function projectIndex(): string
    {
        $path = app_config()['data_dir'] . '/wiki/projects_index.md';
        if (!is_file($path)) {
            return '';
        }
        $text = trim((string)file_get_contents($path));
        if ($text === '') {
            return '';
        }
        return "Projects that already exist. This is a short index, not the full description of each project:\n" . clip_text($text, 4000);
    }

    private function systemPrompt(?array $project): string
    {
        if ($project === null) {
            $context = <<<TXT
This is General chat. There is no project tag.
Answer the user directly. Do not invent a project tag.
The user creates projects manually from the menu. You cannot create one. If the user asks, tell them to do it from the menu.
Files the user attaches are stored under uploads/. Read an attached file before you answer about it. When you cite one, put its path alone on a line, such as uploads/photo.png. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat.
TXT;
        } else {
            $folder = $project['folder_name'];
            $rename = Projects::renameLine($project);
            if ($rename !== '') {
                $rename .= "\n";
            }
            $context = <<<TXT
Assigned project folder: {$folder}
Assigned project name: {$project['name']}
{$rename}Store project markdown only in {$folder}/wiki/. The description file is wiki/PROJECT.md. It holds only the general description: what the project is, contributors, credentials, and main goals. Deep research, findings, and specific data get their own markdown file in that folder, such as wiki/research.md, or a SQLite database in database/. wiki/NOTES.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file. The board has no backlog. An idea that is not scheduled does not get a kanban card.
When you create or cite a file, put its path alone on a line, such as wiki/NOTES.md or report.pdf. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat.
When this chat settles conclusions, decisions, or facts worth keeping, read the matching wiki file and then write or edit it with wiki_memory action write before you finish. Keep doing this as the chat continues and those conclusions change. Do not wait for the end of a long task, and do not leave the record only in the chat.
Tag every delegation exactly as project: {$folder}
TXT;
            $brief = Projects::promptContext((string)$folder, true);
            if ($brief !== '') {
                $context .= "\n" . $brief . "\n";
            }
            $index = $this->projectIndex();
            if ($index !== '') {
                $context .= "\n" . $index;
            }
        }
        $now = app_local_stamp();
        $state = $this->db->consultantState();
        if ($state['active']) {
            $consultNote = "The Consultant is on. Delegate to it with agent consultant only as a last resort. Each call costs the user money.\n";
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
        $pdfNote = $project !== null
            ? "A PDF is not written in this chat. Delegate it to the writer with the file name and the brief. The coder does not make PDFs.\n"
            : '';
        $rules = <<<TXT
{$context}
{$controlNote}{$installNote}Local time now: {$now}
That is the server clock. Use it for schedules and for any time you say. Do not name a timezone, such as CET or UTC, and do not convert the hour, unless the user asks.
Each message from the user starts with the local time it was sent, in brackets. Trust that time. Do not repeat the brackets in your reply.

{$team}

{$consultNote}When you need a tool, emit exactly this and stop so the system can run it. Put nothing after <<end>> in the same turn:
<<tool>>
{"name":"filesystem","args":{"action":"list"}}
<<end>>
Other correct examples:
<<tool>>
{"name":"wiki_memory","args":{"action":"read"}}
<<end>>
<<tool>>
{"name":"kanban","args":{"action":"list"}}
<<end>>
Never invent another call format. These do nothing:
: filesystem
: wiki/NOTES.md
filesystem
<task_breakdown>...</task_breakdown>
<delegate><agent>writer</agent></delegate>
Naming a tool in a list or sentence does nothing. Only the <<tool>> ... <<end>> block runs it.
Do not print a tool call in the reply. Draft XML such as <delegate> is thinking, not something the user should read.
Do not say you wrote, updated, or created a file until a tool result confirms it. Showing the planned markdown in chat is not a write. Call wiki_memory with action write first, then answer. A project file is written by a subagent with filesystem action write.

{$pdfNote}These are the skills at your disposal. Read each for further instructions
TXT;
        $projectId = $project ? (int)$project['id'] : 0;
        $omit = ['create_pdf', 'run_command'];
        if ($projectId <= 0 || !$this->db->emailAccounts($projectId)) {
            $omit[] = 'send_email';
        }
        if ($projectId <= 0 || !$this->db->ftpAccounts($projectId)) {
            $omit[] = 'ftp';
        }
        $catalog = $this->skills->promptCatalog($omit, [
            'web_search' => $this->researchToolLine('web_search'),
            'fetch_url' => $this->researchToolLine('fetch_url'),
            'browse_url' => $this->researchToolLine('browse_url'),
        ]);
        $lines = [];
        $sawEmail = false;
        foreach ((new CapabilityTools($this->db))->descriptions($projectId) as $line) {
            if (!preg_match('/^- (send_email|ftp|mcp):/', $line, $match) || in_array($match[1], $omit, true)) {
                continue;
            }
            $lines[] = $line;
            if ($match[1] === 'send_email') {
                $sawEmail = true;
            }
        }
        if (!$sawEmail) {
            $lines[] = '- send_email: this chat has no mailbox. Say that a mailbox is added on the Setup page, under Email, and linked to this project. There is no Tools tab. Do not describe it as a missing capability.';
        }
        $tail = Prompts::ORCHESTRATOR . "\n\n" . $rules . "\n" . $catalog;
        if ($lines !== []) {
            $tail .= "\n\n" . implode("\n\n", $lines);
        }
        $notes = $this->memory->toolNotesForPrompt();
        if ($notes !== '') {
            $tail .= "\n\n" . $notes;
        }
        if ($project !== null) {
            if ($this->db->getSetting('reread_desc_' . (int)$project['id'], '0') === '1') {
                $desc = 'wiki/' . Projects::descFile((string)$project['folder_name']);
                $tail .= "\n\nThe chat was just cleared. Before you answer or do other work, read {$desc} so you know what this project is. Use wiki_memory with action read and no name.\n";
            }
        }
        return $tail;
    }

    private function clearDescReread(int $projectId, string $folder, string $path): void
    {
        if ($projectId <= 0 || $folder === '') {
            return;
        }
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path !== 'wiki/' . Projects::descFile($folder)) {
            return;
        }
        $this->db->setSetting('reread_desc_' . $projectId, '0');
    }

    private function stamped(array $row): string
    {
        $content = (string)($row['content'] ?? '');
        if (($row['role'] ?? '') === 'assistant') {
            $content = strip_chat_tags($content);
        }
        $content = clip_text($content, 8000);
        if (($row['role'] ?? '') !== 'user' || str_starts_with($content, 'Scheduled task:')) {
            return $content;
        }
        return '[' . app_local_stamp((string)($row['created_at'] ?? '')) . "]\n" . $content;
    }

    private function confirmInstall(string $command, callable $emit, ?callable $shouldStop, ?int $projectId = null): bool
    {
        return $this->confirmAction('install', 'Install: ' . $command, $emit, $shouldStop, $projectId, ['command' => $command]);
    }

    private function confirmAction(string $kind, string $summary, callable $emit, ?callable $shouldStop, ?int $projectId = null, array $extra = []): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $id = $this->db->beginActionAsk($kind, $summary, $projectId);
        $payload = array_merge(['type' => 'action_prompt', 'id' => $id, 'kind' => $kind, 'summary' => $summary], $extra);
        if ($kind === 'install' && isset($extra['command'])) {
            $payload['command'] = $extra['command'];
        }
        $emit($payload);
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

    private function gateExternal(string $kind, string $summary, callable $run, callable $emit, ?callable $shouldStop, ?int $projectId = null): string
    {
        if ($this->db->hitlExternalActions()) {
            $scope = $projectId && $projectId > 0 ? $projectId : null;
            if (PHP_SAPI === 'cli') {
                $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · scheduled cannot ask · ' . $summary, $scope);
                return 'This action needs human approval, but a scheduled run cannot ask. Turn off extra approval gates, or run it from chat.';
            }
            $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · needs approval · ' . $summary, $scope);
            if (!$this->confirmAction($kind, $summary, $emit, $shouldStop, $projectId)) {
                $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · denied', $scope);
                return 'The user did not allow this action.';
            }
            $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · allowed', $scope);
        }
        $result = $run();
        $this->db->logCompliance('tool_' . $kind, 'agent', $summary, ['result' => clip_text($result, 500)], $projectId);
        return $result;
    }

    private function acceptsProjectTag(string $tagged, string $folder, string $name, string $former, int $projectId): bool
    {
        if ($tagged === '' || strcasecmp($tagged, $folder) === 0) {
            return true;
        }
        foreach ([$name, $former] as $label) {
            if ($label !== '' && strcasecmp($tagged, $label) === 0) {
                return true;
            }
        }
        try {
            if ($name !== '' && strcasecmp($tagged, Projects::folderFromName($name)) === 0) {
                return true;
            }
            if ($former !== '' && strcasecmp($tagged, Projects::folderFromName($former)) === 0) {
                return true;
            }
        } catch (InvalidArgumentException $e) {
        }
        $other = $this->db->projectByFolder(strtolower($tagged));
        return !$other || (int)$other['id'] === $projectId;
    }

    private function tools(?array $project, callable $emit, int $projectId, ?callable $shouldStop, bool $scheduled = false): array
    {
        $folder = is_array($project) ? (string)$project['folder_name'] : '';
        $projectName = is_array($project) ? trim((string)($project['name'] ?? '')) : '';
        $formerName = is_array($project) ? trim((string)($project['former_name'] ?? '')) : '';
        $shownProject = $projectName !== '' ? $projectName : $folder;
        $createdTaskIds = [];
        $files = function (array $args) use ($folder, $projectId): string {
            $action = strtolower(trim((string)($args['action'] ?? '')));
            if (in_array($action, ['write', 'delete', 'move', 'copy'], true)) {
                return 'Only a subagent changes files. Delegate it. The main agent uses filesystem with action list or read.';
            }
            if ($action !== 'list' && $action !== 'read') {
                return 'filesystem needs action list or read here. A subagent uses write, delete, move, or copy.';
            }
            $path = (string)($args['path'] ?? '');
            if ($action === 'list' && $folder === '' && trim(str_replace('\\', '/', $path)) === '') {
                $path = 'uploads';
            }
            $useFolder = $this->uploadsFolder($folder, $path);
            if ($action === 'list') {
                if ($this->generalUploadsMissing($useFolder)) {
                    return '[]';
                }
                $blocked = $this->needsProject($useFolder, $path, 'Open a project before listing its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $tree = (new SafetyInterceptor(app_config()['data_dir']))->listFiles($useFolder, $path);
                return json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
            if ($this->generalUploadsMissing($useFolder)) {
                return 'File not found.';
            }
            $blocked = $this->needsProject($useFolder, $path, 'Open a project before reading its files.');
            if ($blocked !== null) {
                return $blocked;
            }
            $this->clearDescReread($projectId, $useFolder, $path);
            return (new SafetyInterceptor(app_config()['data_dir']))->readFile($useFolder, $path);
        };
        $tools = [
            'delegate' => function (array $args) use ($folder, $projectName, $formerName, $shownProject, $emit, $projectId, $shouldStop, $scheduled, &$createdTaskIds): string {
                $agent = strtolower(trim((string)($args['agent'] ?? '')));
                $instruction = trim((string)($args['instruction'] ?? ''));
                if ($agent === 'consultant') {
                    if (!$this->db->consultantActive()) {
                        $reason = (string)($this->db->consultantState()['reason'] ?? '');
                        if ($reason === 'no_key') {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · consultant has no key');
                            return 'The Consultant has no API key. Tell the user to add one on the Setup page, under External LLM provider, and use that API for the Consultant.';
                        }
                        $this->db->loop('orchestrator', 'process', 'Delegate rejected · consultant turned off');
                        return 'The Consultant is turned off. Tell the user they can turn it on from the Agents page. It costs tokens while it is on.';
                    }
                    if ($instruction === '') {
                        $this->db->loop('orchestrator', 'process', 'Delegate rejected · no instruction');
                        return 'The Consultant needs an assignment.';
                    }
                    if ($folder !== '') {
                        $tagged = trim((string)($args['project'] ?? $folder));
                        if (!$this->acceptsProjectTag($tagged, $folder, $projectName, $formerName, $projectId)) {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · project tag');
                            return 'Delegation rejected. The project tag must be project: ' . $folder;
                        }
                    }
                    $note = '';
                    if ($folder !== '') {
                        try {
                            $note = $this->startDelegatedTask($projectId, $agent, $instruction, $args, $createdTaskIds);
                        } catch (Throwable $e) {
                            $note = '';
                        }
                    }
                    $runInstruction = $instruction;
                    if (preg_match('/Task #(\d+)/', $note, $card)) {
                        $runInstruction .= "\n\nThis is card #" . $card[1] . ". When you finish, add a short result to that card with the kanban tool. Do not move or edit the card.";
                    }
                    $emit(['type' => 'delegation', 'agent' => 'consultant', 'project' => $shownProject !== '' ? $shownProject : 'general', 'text' => $instruction]);
                    try {
                        return $this->subagents->consult($folder, $runInstruction, $emit, $projectId, $shouldStop, $scheduled) . $note;
                    } catch (ChatStopped $e) {
                        throw $e;
                    } catch (Throwable $e) {
                        $notice = agent_error('consultant', $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    }
                }
                if ($folder === '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no project');
                    return 'General chat has no project. Tell the user to create a project manually from the menu before delegating.';
                }
                $tagged = trim((string)($args['project'] ?? $folder));
                if (!$this->acceptsProjectTag($tagged, $folder, $projectName, $formerName, $projectId)) {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · project tag');
                    return 'Delegation rejected. The project tag must be project: ' . $folder;
                }
                if (!in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer'], true)) {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · unknown agent');
                    return 'Unknown subagent.';
                }
                if ($instruction === '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no instruction');
                    return 'The subagent needs an instruction.';
                }
                $note = '';
                try {
                    $note = $this->startDelegatedTask($projectId, $agent, $instruction, $args, $createdTaskIds);
                } catch (Throwable $e) {
                    $note = '';
                }
                $runInstruction = $instruction;
                if (preg_match('/Task #(\d+)/', $note, $card)) {
                    $runInstruction .= "\n\nThis is card #" . $card[1] . ". When you finish, add a short result to that card with the kanban tool. Do not move or edit the card.";
                }
                $emit(['type' => 'delegation', 'agent' => $agent, 'project' => $shownProject, 'text' => $instruction]);
                $jobId = $this->db->startJob('subagent', $projectId > 0 ? $projectId : null, [
                    'agent' => $agent,
                    'folder' => $folder,
                    'instruction' => $runInstruction,
                    'scheduled' => $scheduled,
                ]);
                $this->db->loopPush($jobId, $projectId > 0 ? $projectId : null);
                $watch = function () use ($shouldStop, $jobId): bool {
                    $this->db->touchJob($jobId);
                    return $shouldStop && $shouldStop();
                };
                try {
                    $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' started');
                    try {
                        $result = $this->subagents->run($agent, $folder, $runInstruction, $emit, $projectId, $watch, $scheduled);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' finished');
                        $this->db->finishJob($jobId);
                        return $result . $note;
                    } catch (ChatStopped $e) {
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' stopped');
                        $this->db->finishJob($jobId);
                        $this->db->acknowledgeRunStop($projectId, $jobId);
                        throw $e;
                    } catch (RuntimeException $e) {
                        if ($e->getMessage() === 'Only one subagent can run at a time.') {
                            $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' closed · one already running');
                            $this->db->finishJob($jobId);
                            return 'Only one subagent can run at a time. Wait for the current one to finish, then delegate the next task.' . $note;
                        }
                        $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' failed · ' . $e->getMessage());
                        $notice = agent_error($agent, $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    } catch (Throwable $e) {
                        $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' failed · ' . $e->getMessage());
                        $notice = agent_error($agent, $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    }
                } finally {
                    $this->db->loopPop();
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
            'read_pdf' => function (array $args) use ($folder, $shouldStop): string {
                $path = (string)($args['path'] ?? '');
                $folder = $this->uploadsFolder($folder, $path);
                if ($this->generalUploadsMissing($folder)) {
                    return 'File not found.';
                }
                $blocked = $this->needsProject($folder, $path, 'Open a project before reading a PDF.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return PdfFile::readProject(new SafetyInterceptor(app_config()['data_dir']), $folder, $path, (string)($args['question'] ?? ''), $shouldStop);
            },
            'read_image' => function (array $args) use ($folder, $shouldStop): string {
                $path = (string)($args['path'] ?? '');
                $folder = $this->uploadsFolder($folder, $path);
                if ($this->generalUploadsMissing($folder)) {
                    return 'File not found.';
                }
                $blocked = $this->needsProject($folder, $path, 'Open a project before looking at an image.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return Sight::look(new SafetyInterceptor(app_config()['data_dir']), $folder, $path, (string)($args['question'] ?? ''), $shouldStop);
            },
            'screenshot' => function (array $args) use ($folder, $shouldStop): string {
                if ($folder === '') {
                    return 'Open a project before taking a screenshot.';
                }
                return Sight::shot(new SafetyInterceptor(app_config()['data_dir']), $folder, $args, $shouldStop);
            },
            'create_pdf' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'Open a project before creating a PDF.';
                }
                return 'Do not write the PDF in this chat. Delegate it to the writer with the file name and the brief. The writer calls create_pdf in its own context, so a long document does not fill this chat. The coder is for software, not for PDFs.';
            },
            'create_project' => function (array $args): string {
                return 'You cannot create a project. Tell the user to create it manually from the menu.';
            },
            'wiki_memory' => function (array $args) use ($folder, $projectId): string {
                $action = strtolower(trim((string)($args['action'] ?? '')));
                if ($action === 'search') {
                    return $this->memory->search((string)($args['query'] ?? ''), $folder !== '' ? $folder : null);
                }
                if ($action === 'read') {
                    if ($folder === '') {
                        return 'General chat has no project wiki.';
                    }
                    try {
                        $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                    } catch (InvalidArgumentException $e) {
                        return $e->getMessage();
                    }
                    $this->clearDescReread($projectId, $folder, $relative);
                    try {
                        return (new SafetyInterceptor(app_config()['data_dir']))->readFile($folder, $relative, 100000);
                    } catch (Throwable $e) {
                        return 'That wiki file does not exist yet.';
                    }
                }
                if ($action === 'write') {
                    if ($folder === '') {
                        return 'General chat has no project wiki. Open a project before writing project notes.';
                    }
                    try {
                        $relative = Projects::wikiRelativeFromArgs($folder, $args);
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
                }
                if ($action === 'reindex') {
                    return $this->memory->update();
                }
                return 'wiki_memory needs action search, read, write, or reindex.';
            },
            'wiki_search' => function (array $args) use ($folder): string {
                return $this->memory->search((string)($args['query'] ?? ''), $folder !== '' ? $folder : null);
            },
            'wiki_read' => function (array $args) use ($folder, $projectId): string {
                if ($folder === '') {
                    return 'General chat has no project wiki.';
                }
                try {
                    $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                $this->clearDescReread($projectId, $folder, $relative);
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
                    $relative = Projects::wikiRelativeFromArgs($folder, $args);
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
                try {
                    return $this->skills->register($args, 'orchestrator');
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
            },
            'test_skill' => function (array $args) use ($folder): string {
                return $this->skills->testCall($args, $folder);
            },
            'send_email' => function (array $args) use ($projectId, $emit, $shouldStop): string {
                $to = trim((string)($args['to'] ?? ''));
                $subject = trim((string)($args['subject'] ?? ''));
                $summary = 'Send email to ' . ($to !== '' ? $to : '(unknown)') . ($subject !== '' ? ' — ' . $subject : '');
                return $this->gateExternal(
                    'email',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->sendForProject($projectId, $args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'ftp' => function (array $args) use ($projectId, $folder, $emit, $shouldStop): string {
                $action = strtolower(trim((string)($args['action'] ?? '')));
                $summary = 'FTP ' . ($action !== '' ? $action : 'action');
                return $this->gateExternal(
                    'ftp',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->ftpForProject($projectId, $folder, $args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'mcp' => function (array $args) use ($projectId, $emit, $shouldStop): string {
                $server = trim((string)($args['server'] ?? ''));
                $tool = trim((string)($args['tool'] ?? ''));
                $summary = 'MCP' . ($server !== '' ? ' ' . $server : '') . ($tool !== '' ? ' / ' . $tool : '');
                return $this->gateExternal(
                    'mcp',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->mcp($args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'remember' => function (array $args): string {
                return $this->memory->remember((string)($args['tool'] ?? ''), (string)($args['note'] ?? ''));
            },
            'web_search' => function (): string {
                return $this->researchDelegationNotice('web_search');
            },
            'search' => function (): string {
                return $this->researchDelegationNotice('web_search');
            },
            'fetch_url' => function (): string {
                return $this->researchDelegationNotice('fetch_url');
            },
            'browse_url' => function (): string {
                return $this->researchDelegationNotice('browse_url');
            },
        ];
        if ($folder !== '') {
            foreach ($this->skills->activeHandlers($folder) as $name => $handler) {
                if (!isset($tools[$name])) {
                    if ($name === 'sqlite_helper') {
                        $tools[$name] = function (array $args) use ($handler, $emit, $shouldStop, $projectId): string {
                            $action = strtolower(trim((string)($args['action'] ?? '')));
                            if ($action === '') {
                                $action = strtolower(trim((string)($args['command'] ?? '')));
                            }
                            $sql = trim((string)($args['sql'] ?? ''));
                            $destructive = in_array($action, ['drop', 'delete'], true)
                                || ($action === 'execute' && preg_match('/^\s*DROP\s+TABLE\b/i', $sql));
                            if (!$destructive) {
                                return $handler($args);
                            }
                            $database = trim((string)($args['database'] ?? ''));
                            $table = trim((string)($args['table'] ?? ''));
                            $summary = 'Drop SQLite table ' . ($table !== '' ? $table : '(unspecified)') . ' from ' . ($database !== '' ? $database : '(unspecified)');
                            if (!$this->confirmAction('database_drop', $summary, $emit, $shouldStop, $projectId > 0 ? $projectId : null)) {
                                return 'The user did not approve this database table drop.';
                            }
                            return $handler($args);
                        };
                        continue;
                    }
                    $tools[$name] = $handler;
                }
            }
        }
        foreach (array_keys($tools) as $name) {
            if (!$this->skills->offered($name)) {
                unset($tools[$name]);
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
                $held = (int)($task['held'] ?? 0) === 1 ? ' The card stays held and will not start.' : '';
                return 'Moved task #' . $task['id'] . ' to ' . $board->column($task) . ': ' . $task['title'] . $held;
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

    private function uploadsFolder(string $folder, string $path): string
    {
        if ($folder !== '') {
            return $folder;
        }
        $raw = str_replace('\\', '/', trim($path));
        if (str_starts_with($raw, '/')) {
            return '';
        }
        $norm = ltrim($raw, '/');
        if ($norm === 'uploads' || str_starts_with($norm, 'uploads/')) {
            return Projects::generalFolder();
        }
        return '';
    }

    private function generalUploadsMissing(string $folder): bool
    {
        return $folder === Projects::generalFolder() && !is_dir(Projects::root($folder) . '/uploads');
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

    private function researchToolLine(string $tool): string
    {
        return match ($tool) {
            'web_search' => '- web_search: args query. You know this tool. It searches the web. Only the researcher can call it. Delegate the search to the researcher. Do not call it yourself, and do not say you cannot look something up.',
            'fetch_url' => '- fetch_url: args url. You know this tool. It opens a public web page. Only the researcher can call it. Delegate the visit to the researcher. Do not say you cannot visit a website.',
            'browse_url' => '- browse_url: args url. You know this tool. It opens a public page in the headless browser, so scripts on the page run. Only the researcher can call it. Delegate the visit to the researcher.',
            default => '',
        };
    }

    private function researchDelegationNotice(string $tool): string
    {
        return 'Error: ' . $tool . ' must be delegated to the researcher. You know this tool. Only the researcher can call it. Delegate with a clear instruction.';
    }
}
