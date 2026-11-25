<?php

class CapabilityTools
{
    public array $blockedInstalls = [];

    public array $missingPython = [];

    public function __construct(private Database $db)
    {
    }

    public function handlers(string $projectFolder, int $projectId = 0, bool $allowInstall = false): array
    {
        $handlers = [];
        $handlers['web_search'] = fn(array $args): string => $this->webSearch((string)($args['query'] ?? ''), (string)($args['region'] ?? ''));
        $handlers['fetch_url'] = fn(array $args): string => $this->fetchUrl((string)($args['url'] ?? ''));
        $handlers['browse_url'] = fn(array $args): string => $this->browseUrl((string)($args['url'] ?? ''));
        $handlers['yahoo_finance'] = fn(array $args): string => $this->yahooFinance($args);
        $handlers['run_command'] = fn(array $args): string => $this->runCommand((string)($args['command'] ?? ''), $projectFolder, $allowInstall);
        $handlers['run_python'] = fn(array $args): string => $this->runPython((string)($args['path'] ?? $args['file'] ?? ''), $projectFolder);
        if ($projectId > 0 && $this->db->emailAccounts($projectId)) {
            $handlers['send_email'] = fn(array $args): string => $this->sendForProject($projectId, $args);
        }
        if ($projectId > 0 && $this->db->ftpAccounts($projectId)) {
            $handlers['ftp'] = fn(array $args): string => $this->ftpForProject($projectId, $projectFolder, $args);
        }
        $handlers['mcp'] = fn(array $args): string => $this->mcp($args);
        $handlers['remember'] = fn(array $args): string => app_services()['memory']->remember((string)($args['tool'] ?? ''), (string)($args['note'] ?? ''));
        return $handlers;
    }

    public function descriptions(int $projectId = 0): array
    {
        $lines = [];
        $lines[] = '- web_search: args query. Optional region is a country code such as us or se, or a language and country such as en-US. Leave region out to search this server\'s country. One idea, as in a normal search box: two to four plain words. Every word must match, so extra words and synonyms make it miss. Quotes are only for one exact title of several words, plus at most one plain word. Do not quote a single word, do not stack quoted phrases, and do not use OR, AND, NOT, or parentheses to join alternatives. Search the other wording on its own. site:example.com is the only operator. Returns a title, link, and snippet. Then read a result with fetch_url. Rewrite only when the message starts with "This query was not searched" or gives a shorter query, and call once more. If the search provider rejected the request, end the run. Do not call another tool. Do not fetch pages, rewrite the query, or log research. Reply with that failure so the main agent hears it. If the results page could not be read, stop. Do not call web_search again this turn.';
        $lines[] = '- fetch_url: args url. Reads a public page and lists links on it. Open those links to keep crawling.';
        $lines[] = '- browse_url: args url. Opens a public page in the headless browser so scripts run, then returns the text and links. Use it when fetch_url shows an empty or unfinished page.';
        $lines[] = $this->db->fullSystemControl()
            ? '- run_command: args command. May use sudo. Starts in the project folder when there is one. A path that starts with / is anywhere on this computer. Do not install software. Only the main agent can install a dependency, and the user is asked first. Do not run Python. A .py file runs through run_python.'
            : '- run_command: unavailable while full system control is off. A Python file runs through run_python. Use the other tools or a registered skill for anything else.';
        $lines[] = '- run_python: args path. Runs one project .py file inside the project without full system control. It cannot see other folders or a database file. Prefer PHP. Use Python only when a Python library is required.';
        if ($projectId > 0 && ($accounts = $this->db->emailAccounts($projectId))) {
            if (count($accounts) === 1) {
                $row = $accounts[0];
                $lines[] = '- send_email: args to, subject, body. Ready to call. You send as ' . $row['from_name'] . ' <' . $row['from_email'] . '>. That address is yours. It is filled in as From. Never put it in to. to is the other person, such as the address the user gave for themselves. If you do not know who should receive it, ask. Do not email your own address.';
            } else {
                $senders = array_map(fn(array $row): string => $row['label'] . ' sends as ' . $row['from_name'] . ' <' . $row['from_email'] . '>', $accounts);
                $lines[] = '- send_email: args to, subject, body. Optional account is the mailbox label you send as, only when you must choose: ' . implode(', ', $senders) . '. Ready to call. Those addresses are yours and are filled in as From. Never put one of them in to. to is the other person, such as the address the user gave for themselves. If you do not know who should receive it, ask.';
            }
        }
        if ($projectId > 0 && $this->db->ftpAccounts($projectId)) {
            $accounts = $this->db->ftpAccounts($projectId);
            $names = array_map(fn(array $row): string => $row['label'] . ' (' . $row['host'] . ')', $accounts);
            if (count($accounts) === 1) {
                $lines[] = '- ftp: args action (list, download, or upload). list uses path. download uses remote and local. upload uses local and remote. This project has one server, ' . $names[0] . '. Use it. Do not ask which server.';
            } else {
                $lines[] = '- ftp: args action (list, download, or upload) and account. list uses path. download uses remote and local. upload uses local and remote. Servers: ' . implode(', ', $names) . '. Ask which server to use before you call ftp, unless the user already named one or it is otherwise obvious. account is that server\'s name.';
            }
        }
        $servers = $this->db->mcpServers();
        if ($servers) {
            $bits = [];
            foreach ($servers as $server) {
                $names = array_map(static fn(array $tool): string => $tool['name'], $server['tools']);
                $bits[] = $names
                    ? $server['label'] . ' (' . implode(', ', array_slice($names, 0, 12)) . ')'
                    : $server['label'] . ' (tools not loaded; use action list)';
            }
            $which = count($servers) === 1
                ? 'This install has one server, ' . $bits[0] . '. Use it. Do not ask which server.'
                : 'Servers: ' . implode('; ', $bits) . '. Ask which server to use before you call mcp, unless the user already named one.';
            $lines[] = '- mcp: args action (list or call), server. call also needs tool and arguments. ' . $which;
        }
        $lines[] = '- remember: args tool, note. Save a short fact about a tool, such as email, ftp, or the headless browser. The note is added to your instructions. Do not tell the user to open a file for it.';
        return $lines;
    }

    public function sendForProject(int $projectId, array $args): string
    {
        if ($projectId <= 0) {
            return 'General chat cannot send email. Open the project linked to the mailbox.';
        }
        $accounts = $this->db->emailAccounts($projectId, true);
        if (!$accounts) {
            return 'This project has no email account. Add one on the Setup page, under Email, and link it to this project.';
        }
        $pick = strtolower(trim((string)($args['account'] ?? '')));
        $account = null;
        if (count($accounts) === 1 && $pick === '') {
            $account = $accounts[0];
        } else {
            foreach ($accounts as $row) {
                if ($pick !== '' && (strtolower((string)$row['label']) === $pick || strtolower((string)$row['from_email']) === $pick)) {
                    $account = $row;
                    break;
                }
            }
        }
        if (!$account) {
            $names = array_map(fn(array $row): string => $row['label'] . ' <' . $row['from_email'] . '>', $accounts);
            return 'Choose which mailbox you send as. These are your addresses, not recipients: ' . implode(', ', $names) . '. Put the other person in to.';
        }
        $recipients = $this->emailAddresses((string)($args['to'] ?? ''));
        $subject = trim((string)($args['subject'] ?? ''));
        $body = trim((string)($args['body'] ?? ''));
        if (!$recipients) {
            return 'A valid recipient address is required in to. That is the other person, not your own address.';
        }
        $own = strtolower((string)$account['from_email']);
        $onlySelf = true;
        foreach ($recipients as $recipient) {
            if (strtolower($recipient) !== $own) {
                $onlySelf = false;
                break;
            }
        }
        if ($onlySelf) {
            return $account['from_email'] . ' is your own address. send_email fills it in as From. Put the other person in to. If the user told you their address, use that. If you do not know who should receive it, ask. Do not email yourself.';
        }
        if ($subject === '' || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
            return 'Subject is required and cannot contain line breaks.';
        }
        if ($body === '' || strlen($body) > 100000) {
            return 'Email body is required.';
        }
        try {
            SmtpMailer::send($account, $recipients, $subject, $body);
        } catch (Throwable $e) {
            return 'Email was not sent. ' . $e->getMessage();
        }
        return 'Sent to ' . implode(', ', $recipients) . '. You sent it as ' . $account['from_name'] . ' <' . $account['from_email'] . '>. That address is the sender. It is not who received the message.';
    }

    public function ftpForProject(int $projectId, string $projectFolder, array $args): string
    {
        if ($projectId <= 0 || $projectFolder === '') {
            return 'General chat cannot use FTP. Open the project linked to the login.';
        }
        $accounts = $this->db->ftpAccounts($projectId, true);
        if (!$accounts) {
            return 'This project has no FTP login. Add servers on the Setup page, under FTP. A project can have several. Name each one, preferably by its domain.';
        }
        $pick = strtolower(trim((string)($args['account'] ?? '')));
        $account = null;
        if (count($accounts) === 1 && $pick === '') {
            $account = $accounts[0];
        } else {
            foreach ($accounts as $row) {
                if ($pick !== '' && (strtolower((string)$row['label']) === $pick || strtolower((string)$row['host']) === $pick)) {
                    $account = $row;
                    break;
                }
            }
        }
        if (!$account) {
            $names = array_map(fn(array $row): string => $row['label'] . ' (' . $row['host'] . ')', $accounts);
            return 'Ask which server to use. This project has: ' . implode(', ', $names) . '. Do not guess.';
        }
        try {
            return FtpClient::run($account, $args, $projectFolder);
        } catch (Throwable $e) {
            return 'FTP failed. ' . $e->getMessage();
        }
    }

    public function mcp(array $args): string
    {
        $servers = $this->db->mcpServers(true);
        if (!$servers) {
            return 'No MCP servers are saved. Add them on the Setup page, under MCP servers.';
        }
        $pick = strtolower(trim((string)($args['server'] ?? '')));
        $server = null;
        if (count($servers) === 1 && $pick === '') {
            $server = $servers[0];
        } else {
            foreach ($servers as $row) {
                if ($pick !== '' && strtolower((string)$row['label']) === $pick) {
                    $server = $row;
                    break;
                }
            }
        }
        if (!$server) {
            $names = array_map(static fn(array $row): string => $row['label'], $servers);
            return 'Ask which MCP server to use: ' . implode(', ', $names) . '. Do not guess.';
        }
        $action = strtolower(trim((string)($args['action'] ?? 'call')));
        try {
            if ($action === 'list') {
                $tools = McpClient::listTools($server);
                $this->db->saveMcpTools((int)$server['id'], $tools);
                if (!$tools) {
                    return $server['label'] . ' has no tools.';
                }
                $lines = [];
                foreach ($tools as $tool) {
                    $lines[] = '- ' . $tool['name'] . ($tool['description'] !== '' ? ': ' . $tool['description'] : '');
                }
                return $server['label'] . " tools:\n" . implode("\n", $lines);
            }
            if ($action !== 'call') {
                return 'Use action list or call.';
            }
            $arguments = $args['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }
            return McpClient::callTool($server, (string)($args['tool'] ?? ''), $arguments);
        } catch (Throwable $e) {
            return 'MCP failed. ' . $e->getMessage();
        }
    }

    private function emailAddresses(string $raw): array
    {
        $parts = preg_split('/[,\s;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            if (!filter_var($part, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $part)) {
                return [];
            }
            $out[] = $part;
        }
        return count($out) > 10 ? [] : $out;
    }

    private function webSearch(string $query, string $region = ''): string
    {
        $query = str_replace(['“', '”', '„'], '"', trim($query));
        if ($query === '') {
            return 'Missing query.';
        }
        $problem = $this->searchQueryProblem($query);
        if ($problem !== '') {
            return $problem;
        }
        $locale = $this->searchLocale($region);
        if ($locale['error'] !== '') {
            return $locale['error'];
        }
        $refusals = [];
        $sawEmpty = false;
        $sawRows = false;
        $partial = null;
        $terms = $this->queryTerms($query);
        foreach (['DuckDuckGo', 'Bing', 'Google'] as $name) {
            if ($name === 'Bing') {
                $found = $this->bingResults($query, $locale);
            } elseif ($name === 'Google') {
                $found = $this->googleResults($query, $locale);
            } else {
                $found = $this->duckSearch($query, $locale);
            }
            $shown = $this->matchingResults($terms, $found['results']);
            $blocks = $this->searchBlocks($shown);
            if ($blocks) {
                return $this->searchAnswer($query, $name, $locale, $blocks, false, []);
            }
            if ($found['results']) {
                $sawRows = true;
                if ($partial === null) {
                    $ranked = $this->partialResults($terms, $found['results']);
                    $rankedBlocks = $this->searchBlocks($ranked['rows']);
                    if ($rankedBlocks) {
                        $partial = $this->searchAnswer($query, $name, $locale, $rankedBlocks, true, $ranked['missing']);
                    }
                }
                $found['status'] = 'empty';
            } elseif ($found['status'] === 'results') {
                $found['status'] = 'empty';
            }
            if ($found['status'] === 'empty') {
                $sawEmpty = true;
                continue;
            }
            $refusals[] = $this->engineMiss($name, $found);
        }
        if ($partial !== null) {
            return $partial;
        }
        if (!$sawEmpty) {
            $instant = $this->instantAnswer($query, $locale);
            if ($instant !== '') {
                return $instant;
            }
            return $this->providersRejected($refusals);
        }
        if (preg_match('/^site:\s*([a-z0-9.-]+\.[a-z]{2,})\/?$/i', $query, $match) && $this->hostIsPublic($match[1])) {
            $home = 'https://' . strtolower($match[1]) . '/';
            return "No search hits for that query. Opened {$home} instead.\n\n" . $this->fetchUrl($home);
        }
        if ($sawRows) {
            return 'Query: ' . $query . "\nThe provider returned pages, and none contained these words. Open a URL you already have with fetch_url. Do not search again.";
        }
        return $this->emptySearchMessage($query);
    }

    private function searchAnswer(string $query, string $engine, array $locale, array $blocks, bool $partial, array $missing): string
    {
        $head = 'Query: ' . $query;
        if ($partial) {
            $head .= "\nThese pages do not contain every word.";
            if ($missing !== []) {
                $head .= ' Missing: ' . implode(', ', $missing) . '.';
            }
            $head .= "\nOpen the best link with fetch_url. Do not search again.";
        }
        array_unshift($blocks, $head);
        $market = $locale['market'] !== '' ? ' (' . $locale['market'] . ')' : '';
        $blocks[] = 'Results from ' . $engine . $market . '.';
        if (!$partial) {
            $blocks[] = 'Read a page with fetch_url. The result lists links you can open next.';
        }
        return clip_text(implode("\n\n", $blocks), 4000);
    }

    private function searchBlocks(array $results): array
    {
        $blocks = [];
        $n = 0;
        foreach ($results as $row) {
            $host = (string)(parse_url($row['url'], PHP_URL_HOST) ?? '');
            if ($host === '' || !$this->hostIsPublic($host, true)) {
                continue;
            }
            $n++;
            $block = $n . '. ' . $row['title'] . "\n" . $row['url'];
            if ($row['snippet'] !== '') {
                $block .= "\n" . $row['snippet'];
            }
            $blocks[] = $block;
            if ($n >= 8) {
                break;
            }
        }
        return $blocks;
    }

    private function queryTerms(string $query): array
    {
        $skip = ['to', 'for', 'in', 'on', 'at', 'by', 'from', 'as', 'be', 'is', 'it', 'we', 'you', 'your', 'this', 'that', 'with', 'not', 'but', 'if', 'are', 'was', 'into', 'over', 'via', 'per', 'up', 'out'];
        $terms = [];
        foreach ($this->searchWords($query) as $word) {
            $lower = mb_strtolower($word);
            if (mb_strlen($lower) < 3 || in_array($lower, $skip, true) || isset($terms[$lower])) {
                continue;
            }
            $terms[$lower] = $lower;
        }
        return array_values($terms);
    }

    private function resultTermScore(array $terms, array $results): int
    {
        if (!$terms) {
            return 1;
        }
        $hay = '';
        foreach ($results as $row) {
            $hay .= ' ' . ($row['title'] ?? '') . ' ' . ($row['snippet'] ?? '') . ' ' . ($row['url'] ?? '');
        }
        $hay = mb_strtolower($hay);
        $score = 0;
        foreach ($terms as $term) {
            if ($this->hayHasTerm($hay, $term)) {
                $score++;
            }
        }
        return $score;
    }

    private function hayHasTerm(string $hay, string $term): bool
    {
        $quoted = preg_quote($term, '/');
        if (preg_match('/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/u', $hay)) {
            return true;
        }
        if (mb_strlen($term) < 5 || !preg_match_all('/(?<![\p{L}\p{N}])([\p{L}\p{N}]{5,})/u', $hay, $tokens)) {
            return false;
        }
        foreach ($tokens[1] as $token) {
            if (str_starts_with($token, $term) || str_starts_with($term, $token)) {
                return true;
            }
        }
        return false;
    }

    private function matchingResults(array $terms, array $results): array
    {
        if (count($terms) < 2) {
            return $results;
        }
        $later = array_slice($terms, 1);
        $kept = [];
        foreach ($results as $row) {
            $hit = 0;
            $hasLater = false;
            foreach ($terms as $index => $term) {
                if ($this->resultTermScore([$term], [$row]) !== 1) {
                    continue;
                }
                $hit++;
                if ($index > 0) {
                    $hasLater = true;
                }
            }
            if (!$hasLater) {
                continue;
            }
            $row['_hit'] = $hit;
            $kept[] = $row;
        }
        if ($this->resultTermScore($later, $kept) < count($later)) {
            return [];
        }
        usort($kept, static fn(array $a, array $b): int => ($b['_hit'] ?? 0) <=> ($a['_hit'] ?? 0));
        foreach ($kept as &$row) {
            unset($row['_hit']);
        }
        return $kept;
    }

    private function partialResults(array $terms, array $results): array
    {
        $ranked = [];
        foreach ($results as $row) {
            $have = [];
            foreach ($terms as $term) {
                if ($this->resultTermScore([$term], [$row]) === 1) {
                    $have[] = $term;
                }
            }
            if ($have === []) {
                continue;
            }
            $row['_hit'] = count($have);
            $row['_have'] = $have;
            $ranked[] = $row;
        }
        usort($ranked, static fn(array $a, array $b): int => ($b['_hit'] ?? 0) <=> ($a['_hit'] ?? 0));
        $seen = [];
        foreach ($ranked as $row) {
            foreach ($row['_have'] as $term) {
                $seen[$term] = true;
            }
        }
        $missing = [];
        foreach ($terms as $term) {
            if (!isset($seen[$term])) {
                $missing[] = $term;
            }
        }
        $rows = [];
        foreach ($ranked as $row) {
            unset($row['_hit'], $row['_have']);
            $rows[] = $row;
        }
        return ['rows' => $rows, 'missing' => $missing];
    }

    private function engineMiss(string $name, array $found): string
    {
        $reason = (string)($found['reason'] ?? '');
        if ($reason === '' && ($found['status'] ?? '') === 'unparsed') {
            $reason = 'could not be read';
        }
        $detail = $name . ' HTTP ' . (int)($found['code'] ?? 0);
        if ($reason !== '') {
            $detail .= ', ' . $reason;
        }
        $error = trim((string)($found['error'] ?? ''));
        if ($error !== '') {
            $detail .= '. ' . $error;
        }
        return $detail;
    }

    private function searchQueryProblem(string $query): string
    {
        if (preg_match('/\b(?:OR|AND|NOT)\b/', $query)) {
            return $this->operatorProblem($query);
        }
        preg_match_all('/"([^"]*)"/u', $query, $quoted);
        $phrases = [];
        foreach ($quoted[1] ?? [] as $phrase) {
            $phrase = trim((string)$phrase);
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }
        if (count($phrases) > 1) {
            $singles = 0;
            foreach ($phrases as $phrase) {
                if (!preg_match('/\s/u', $phrase)) {
                    $singles++;
                }
            }
            $why = $singles === count($phrases)
                ? 'Quotes around "' . implode('" and "', $phrases) . '" force each single word as an exact token, and stacking them demands every one of those tokens on the same page. That is why this kind of query comes back empty.'
                : 'More than one quoted phrase means each phrase must appear exactly, on the same page. Stacked quotes are why this kind of query comes back empty.';
            return 'This query was not searched. ' . $why . ' Write two to four plain words and do not quote a single word. Quotes are only for one exact title of several words, with at most one plain word beside it. Try: ' . $this->suggestedQuery($query) . '.';
        }
        if (count($phrases) === 1 && !preg_match('/\s/u', $phrases[0])) {
            return 'This query was not searched. Quotes around the single word "' . $phrases[0] . '" force an exact token and hide ordinary pages that use the same word. Drop the quotes. A quoted phrase is only for a title of two or more words. Try: ' . $this->suggestedQuery($query) . '.';
        }
        if (count($phrases) === 1) {
            $outside = trim(preg_replace('/"[^"]*"/u', ' ', $query) ?? $query);
            $extra = $this->searchWords($outside);
            if (count($extra) > 1) {
                return 'This query was not searched. A quoted title already has to match word for word. Put at most one plain word beside it, and save the other words for a later search. Try: ' . $this->suggestedQuery($query) . '.';
            }
            return '';
        }
        $words = $this->searchWords($query);
        if (count($words) > 6) {
            return 'This query was not searched. It has too many words. Every word has to match, so each extra word makes a miss more likely. Keep two to four words for one idea, and save the other idea for a later search. Try: ' . $this->suggestedQuery($query) . '.';
        }
        return '';
    }

    private function operatorProblem(string $query): string
    {
        $text = preg_replace('/\bNOT\s+\S+/u', ' ', $query) ?? $query;
        $alts = [];
        $shared = [];
        foreach (preg_split('/\bOR\b/u', $text) ?: [] as $branch) {
            $words = $this->searchWords($branch);
            if (!$words) {
                continue;
            }
            $alts[] = $words[0];
            foreach (array_slice($words, 1) as $word) {
                $shared[] = $word;
            }
        }
        $focus = '';
        $altKeys = array_map(static fn(string $word): string => mb_strtolower($word), $alts);
        foreach ($shared as $word) {
            if (!in_array(mb_strtolower($word), $altKeys, true)) {
                $focus = $word;
                break;
            }
        }
        $try = $alts[0] ?? 'the main term';
        if ($focus !== '') {
            $try .= ' ' . $focus;
        }
        $other = '';
        if (isset($alts[1])) {
            $other = ' Search that other wording on its own: ' . $alts[1] . ($focus !== '' ? ' ' . $focus : '') . '.';
        }
        return 'This query was not searched. OR, AND, and NOT are not operators here. Every word you send has to appear on the page. OR does not mean either term, and AND is already how a normal search works. Write two to four plain words for one idea, with no quotes around a single word. Try: ' . $try . '.' . $other;
    }

    private function emptySearchMessage(string $query): string
    {
        $pieces = [];
        if (preg_match_all('/"([^"]+)"/u', $query, $quoted)) {
            foreach ($quoted[1] as $phrase) {
                $phrase = trim((string)$phrase);
                if ($phrase !== '') {
                    $pieces[] = '"' . $phrase . '"';
                }
            }
        }
        $outside = trim(preg_replace('/"[^"]*"/u', ' ', $query) ?? $query);
        foreach ($this->searchWords($outside) as $word) {
            $pieces[] = $word;
        }
        if (count($pieces) > 2) {
            array_pop($pieces);
            return 'No web results for that query. Every word has to match, so this is narrower than a normal search. Drop the last word and search once more, with no new quotes: ' . implode(' ', $pieces) . '. If that is also empty, stop and say what you could not find. If you already have the page address, open it with fetch_url.';
        }
        return 'No web results contained every word of that query. The provider answered, so this is not a block and the task continues. Try one other two-word query. Do not add quotes, OR, AND, or NOT, and do not describe the search as blocked.';
    }

    private function suggestedQuery(string $query): string
    {
        preg_match_all('/"([^"]+)"/u', $query, $quoted);
        $title = '';
        $single = '';
        foreach ($quoted[1] ?? [] as $phrase) {
            $phrase = trim((string)$phrase);
            if ($phrase === '') {
                continue;
            }
            if (preg_match('/\s/u', $phrase) && mb_strlen($phrase) > mb_strlen($title)) {
                $title = $phrase;
                continue;
            }
            if ($single === '') {
                $single = $phrase;
            }
        }
        $outside = trim(preg_replace('/"[^"]*"/u', ' ', $query) ?? $query);
        $outsideWords = $this->searchWords($outside);
        if ($title !== '') {
            $extra = $single !== '' ? $single : ($outsideWords[0] ?? '');
            return '"' . $title . '"' . ($extra !== '' ? ' ' . $extra : '');
        }
        $words = $this->searchWords($query);
        if (!$words) {
            return 'the two or three main words';
        }
        return implode(' ', array_slice($words, 0, 3));
    }

    private function searchWords(string $query): array
    {
        $text = implode(' ', $this->quotedWords($query));
        $text .= ' ' . (preg_replace('/"[^"]*"/u', ' ', $query) ?? $query);
        $text = preg_replace('/\b(?:OR|AND|NOT)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\bsite:[^\s]+/iu', ' ', $text) ?? $text;
        $text = str_replace(['"', '(', ')'], ' ', $text);
        $skip = ['och', 'att', 'för', 'med', 'som', 'den', 'det', 'en', 'ett', 'om', 'av', 'på', 'till', 'eller', 'and', 'or', 'the', 'of', 'a', 'an'];
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $part) {
            if ($part === '' || in_array(mb_strtolower($part), $skip, true) || isset($out[mb_strtolower($part)])) {
                continue;
            }
            $out[mb_strtolower($part)] = $part;
        }
        return array_values($out);
    }

    private function quotedWords(string $query): array
    {
        preg_match_all('/"([^"]+)"/u', $query, $quoted);
        $words = [];
        foreach ($quoted[1] ?? [] as $phrase) {
            foreach (preg_split('/\s+/u', trim((string)$phrase)) ?: [] as $part) {
                if ($part !== '') {
                    $words[] = str_replace(['(', ')'], '', $part);
                }
            }
        }
        return $words;
    }

    private function duckSearch(string $query, array $locale): array
    {
        $post = 'q=' . rawurlencode($query) . '&kl=' . rawurlencode($locale['duck']);
        $headers = $locale['accept'] !== '' ? [$locale['accept']] : [];
        $first = $this->searchFetch('https://html.duckduckgo.com/html/', $post, $headers);
        $results = $this->parseSearchResults($first['body']);
        if ($results) {
            return $this->searchOutcome($results, 'results', $first);
        }
        $status = $this->duckPageStatus($first);
        $blocked = $this->isSearchBotWall((string)$first['body']) || ((int)$first['code'] !== 200 && (int)$first['code'] !== 0 && trim((string)$first['body']) !== '');
        if ($blocked) {
            return $this->searchOutcome([], $status, $first);
        }
        $lite = $this->searchFetch('https://lite.duckduckgo.com/lite/', $post, $headers);
        $results = $this->parseSearchResults($lite['body']);
        if ($results) {
            return $this->searchOutcome($results, 'results', $lite);
        }
        return $this->searchOutcome([], $this->duckPageStatus($lite), $lite, true);
    }

    private function bingResults(string $query, array $locale): array
    {
        $headers = ['Referer: https://www.bing.com/'];
        if ($locale['accept'] !== '') {
            $headers[] = $locale['accept'];
        }
        $cookie = $this->bingCookie($locale);
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        $page = 'https://www.bing.com/search?q=' . rawurlencode($query);
        if ($locale['cc'] !== '') {
            $page .= '&cc=' . rawurlencode($locale['cc']);
        }
        if ($locale['lang'] !== '') {
            $page .= '&setlang=' . rawurlencode($locale['lang']);
        }
        if ($locale['market'] !== '') {
            $page .= '&mkt=' . rawurlencode($locale['market']);
        }
        $html = $this->searchFetch($page, null, $headers);
        $parsed = $this->parseBingHtml((string)$html['body']);
        if ($parsed && (int)$html['code'] === 200) {
            return $this->searchOutcome($parsed, 'results', $html);
        }
        $url = 'https://www.bing.com/search?format=rss&count=8&q=' . rawurlencode($query);
        if ($locale['cc'] !== '') {
            $url .= '&cc=' . rawurlencode($locale['cc']);
        }
        if ($locale['lang'] !== '') {
            $url .= '&setlang=' . rawurlencode($locale['lang']);
        }
        if ($locale['market'] !== '') {
            $url .= '&mkt=' . rawurlencode($locale['market']);
        }
        $fetched = $this->searchFetch($url, null, $headers);
        $body = (string)$fetched['body'];
        if ($this->searchFailedToConnect($fetched)) {
            return $this->searchOutcome([], 'transport', $fetched);
        }
        if ((int)$fetched['code'] !== 200 || trim($body) === '' || !preg_match('/<(rss|channel|item)\b/i', $body)) {
            return $this->searchOutcome([], 'rejected', $fetched);
        }
        if (!preg_match_all('/<item\b[^>]*>(.*?)<\/item>/is', $body, $items)) {
            return $this->searchOutcome([], 'empty', $fetched);
        }
        $out = [];
        foreach ($items[1] as $item) {
            $url = $this->searchResultUrl($this->xmlText($item, 'link'));
            $title = trim($this->xmlText($item, 'title'));
            if ($url === '' || $title === '') {
                continue;
            }
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($this->xmlText($item, 'description'))) ?? '');
            $out[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
            if (count($out) >= 8) {
                break;
            }
        }
        if (!$out) {
            return $this->searchOutcome([], 'unparsed', $fetched);
        }
        return $this->searchOutcome($out, 'results', $fetched);
    }

    private function bingCookie(array $locale): string
    {
        static $cookies = [];
        $key = $locale['accept'];
        if (isset($cookies[$key])) {
            return $cookies[$key];
        }
        $meta = ['code' => 0, 'error' => ''];
        $final = '';
        $headers = $locale['accept'] !== '' ? [$locale['accept']] : [];
        $this->request('https://www.bing.com/', 0, null, $final, $meta, $headers);
        $cookies[$key] = $this->setCookieHeader((string)($meta['header'] ?? ''));
        return $cookies[$key];
    }

    private function searchLocale(string $region): array
    {
        $region = str_replace('_', '-', trim($region));
        if ($region !== '') {
            $picked = $this->parseRegion($region);
            if ($picked === null) {
                return $this->blankLocale('Region must be a country code such as se or us, or a language and country such as en-US. Leave region out to search this server\'s country.');
            }
            return $picked;
        }
        $cc = $this->serverCountry();
        if ($cc === '') {
            return $this->blankLocale('');
        }
        return $this->makeLocale($cc, $this->countryLanguage($cc));
    }

    private function parseRegion(string $region): ?array
    {
        if (preg_match('/^[a-z]{2}$/i', $region)) {
            $cc = strtoupper($region);
            if ($cc === 'UK') {
                $cc = 'GB';
            }
            if (!$this->knownCountry($cc)) {
                return null;
            }
            return $this->makeLocale($cc, $this->countryLanguage($cc));
        }
        if (!preg_match('/^([a-z]{2})-([a-z]{2})$/i', $region, $match)) {
            return null;
        }
        $left = strtolower($match[1]);
        $right = strtolower($match[2]);
        $rightCc = strtoupper($right) === 'UK' ? 'GB' : strtoupper($right);
        $leftCc = strtoupper($left) === 'UK' ? 'GB' : strtoupper($left);
        if ($this->knownLanguage($left) && $this->knownCountry($rightCc)) {
            return $this->makeLocale($rightCc, $left);
        }
        if ($this->knownCountry($leftCc) && $this->knownLanguage($right)) {
            return $this->makeLocale($leftCc, $right);
        }
        return null;
    }

    private function makeLocale(string $cc, string $lang): array
    {
        $lang = strtolower($lang);
        if ($lang === '') {
            $lang = 'en';
        }
        $marketLang = $lang;
        if ($cc === 'NO' && ($lang === 'no' || $lang === 'nb' || $lang === 'nn')) {
            $marketLang = 'nb';
        }
        $google = $lang;
        if ($lang === 'zh') {
            $google = 'zh-' . $cc;
        }
        $duckCc = strtolower($cc);
        $duckLang = $lang;
        if ($cc === 'GB') {
            $duckCc = 'uk';
        }
        if ($cc === 'JP' && $lang === 'ja') {
            $duckLang = 'jp';
        } elseif ($cc === 'KR' && $lang === 'ko') {
            $duckLang = 'kr';
        } elseif (($cc === 'TW' || $cc === 'HK') && $lang === 'zh') {
            $duckLang = 'tzh';
        }
        $accept = $lang === 'en'
            ? 'Accept-Language: en-' . $cc . ',en;q=0.9'
            : 'Accept-Language: ' . $lang . '-' . $cc . ',' . $lang . ';q=0.9,en;q=0.8';
        return [
            'error' => '',
            'cc' => $cc,
            'lang' => $lang,
            'market' => $marketLang . '-' . $cc,
            'duck' => $duckCc . '-' . $duckLang,
            'google_hl' => $google,
            'accept' => $accept,
        ];
    }

    private function blankLocale(string $error): array
    {
        return [
            'error' => $error,
            'cc' => '',
            'lang' => '',
            'market' => '',
            'duck' => 'wt-wt',
            'google_hl' => '',
            'accept' => '',
        ];
    }

    private function serverCountry(): string
    {
        $zone = date_default_timezone_get();
        return $this->timezoneCountries()[$zone] ?? '';
    }

    private function timezoneCountries(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (['/usr/share/zoneinfo/zone.tab', '/var/db/timezone/zoneinfo/zone.tab'] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parts = explode("\t", $line);
                if (count($parts) < 3) {
                    continue;
                }
                $code = strtoupper(trim($parts[0]));
                $name = trim($parts[2]);
                if (strlen($code) === 2 && $name !== '') {
                    $map[$name] = $code;
                }
            }
            if ($map !== []) {
                break;
            }
        }
        return $map;
    }

    private function knownCountry(string $cc): bool
    {
        if (isset($this->countryLanguages()[$cc])) {
            return true;
        }
        return in_array($cc, $this->timezoneCountries(), true);
    }

    private function knownLanguage(string $lang): bool
    {
        static $langs = null;
        if ($langs === null) {
            $langs = ['en' => true, 'nb' => true, 'nn' => true];
            foreach ($this->countryLanguages() as $code) {
                $langs[$code] = true;
            }
        }
        return isset($langs[$lang]);
    }

    private function countryLanguage(string $cc): string
    {
        return $this->countryLanguages()[$cc] ?? 'en';
    }

    private function countryLanguages(): array
    {
        return [
            'SE' => 'sv', 'NO' => 'no', 'DK' => 'da', 'FI' => 'fi', 'IS' => 'is', 'FO' => 'fo',
            'DE' => 'de', 'AT' => 'de', 'CH' => 'de', 'LI' => 'de',
            'NL' => 'nl', 'BE' => 'nl',
            'FR' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
            'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CL' => 'es', 'CO' => 'es', 'PE' => 'es', 'VE' => 'es', 'UY' => 'es', 'EC' => 'es', 'BO' => 'es', 'PY' => 'es', 'CR' => 'es', 'PA' => 'es', 'GT' => 'es', 'HN' => 'es', 'NI' => 'es', 'SV' => 'es', 'DO' => 'es', 'CU' => 'es',
            'IT' => 'it', 'SM' => 'it', 'VA' => 'it',
            'PT' => 'pt', 'BR' => 'pt',
            'PL' => 'pl', 'CZ' => 'cs', 'SK' => 'sk', 'HU' => 'hu', 'RO' => 'ro', 'BG' => 'bg', 'GR' => 'el', 'TR' => 'tr',
            'RU' => 'ru', 'UA' => 'uk', 'BY' => 'be', 'EE' => 'et', 'LV' => 'lv', 'LT' => 'lt',
            'SI' => 'sl', 'HR' => 'hr', 'RS' => 'sr', 'BA' => 'bs', 'AL' => 'sq',
            'JP' => 'ja', 'KR' => 'ko', 'CN' => 'zh', 'TW' => 'zh', 'HK' => 'zh', 'MO' => 'zh',
            'TH' => 'th', 'VN' => 'vi', 'ID' => 'id', 'MY' => 'ms',
            'SA' => 'ar', 'AE' => 'ar', 'EG' => 'ar', 'QA' => 'ar', 'KW' => 'ar', 'IL' => 'he', 'IR' => 'fa',
            'US' => 'en', 'GB' => 'en', 'CA' => 'en', 'AU' => 'en', 'NZ' => 'en', 'IE' => 'en', 'IN' => 'en', 'SG' => 'en', 'ZA' => 'en', 'PH' => 'en', 'NG' => 'en',
        ];
    }

    private function setCookieHeader(string $header): string
    {
        if (!preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/mi', $header, $matches, PREG_SET_ORDER)) {
            return '';
        }
        $pairs = [];
        foreach ($matches as $match) {
            $pairs[$match[1]] = $match[1] . '=' . $match[2];
        }
        return implode('; ', $pairs);
    }

    private function parseBingHtml(string $html): array
    {
        if (!preg_match_all('/<h2\b[^>]*>\s*<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches as $match) {
            $href = $this->htmlAttr($match[1][0], 'href');
            if (!str_contains($href, '/ck/a')) {
                continue;
            }
            $url = $this->searchResultUrl($href);
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($url === '' || $title === '' || isset($seen[$url]) || mb_strlen($title) < 2) {
                continue;
            }
            $seen[$url] = true;
            $end = $match[0][1] + strlen($match[0][0]);
            $snippet = '';
            if (preg_match('/<p\b[^>]*>(.*?)<\/p>/is', substr($html, $end, 1400), $paragraph)) {
                $snippet = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($paragraph[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            }
            $out[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private function googleResults(string $query, array $locale): array
    {
        $url = 'https://www.google.com/search?gbv=1&num=8&q=' . rawurlencode($query);
        if ($locale['google_hl'] !== '') {
            $url .= '&hl=' . rawurlencode($locale['google_hl']);
        }
        if ($locale['cc'] !== '') {
            $url .= '&gl=' . rawurlencode(strtolower($locale['cc']));
        }
        $headers = $locale['accept'] !== '' ? [$locale['accept']] : [];
        $fetched = $this->searchFetch($url, null, $headers);
        $body = (string)$fetched['body'];
        if ($this->searchFailedToConnect($fetched)) {
            return $this->searchOutcome([], 'transport', $fetched);
        }
        if ((int)$fetched['code'] !== 200 || trim($body) === '' || $this->isSearchBotWall($body)) {
            return $this->searchOutcome([], 'rejected', $fetched);
        }
        $out = $this->parseGoogleResults($body);
        if ($out) {
            return $this->searchOutcome($out, 'results', $fetched);
        }
        if (stripos($body, 'did not match any documents') !== false || stripos($body, 'no results') !== false) {
            return $this->searchOutcome([], 'empty', $fetched);
        }
        if (stripos($body, '/url?q=') !== false) {
            return $this->searchOutcome([], 'unparsed', $fetched);
        }
        return $this->searchOutcome([], 'rejected', $fetched);
    }

    private function parseGoogleResults(string $html): array
    {
        if (!preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches as $match) {
            $href = $this->htmlAttr($match[1], 'href');
            $url = $this->googleResultUrl($href);
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($url === '' || $title === '' || isset($seen[$url]) || mb_strlen($title) < 2) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['title' => $title, 'url' => $url, 'snippet' => ''];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private function googleResultUrl(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '/url?') || str_starts_with($href, 'url?')) {
            $href = 'https://www.google.com/' . ltrim($href, '/');
        }
        $parts = parse_url($href);
        if (is_array($parts) && isset($parts['query'])) {
            $host = strtolower((string)($parts['host'] ?? ''));
            parse_str($parts['query'], $query);
            if (!empty($query['q']) && is_string($query['q']) && str_contains($host, 'google.')) {
                $href = $query['q'];
            }
        }
        return $this->searchResultUrl($href);
    }

    private function searchFetch(string $url, ?string $post = null, array $headers = []): array
    {
        $meta = ['code' => 0, 'error' => ''];
        $finalUrl = $url;
        $body = $this->request($url, 0, $post, $finalUrl, $meta, $headers);
        return [
            'body' => $body,
            'code' => (int)($meta['code'] ?? 0),
            'error' => (string)($meta['error'] ?? ''),
        ];
    }

    private function searchOutcome(array $results, string $status, array $fetched, bool $allowInstant = false): array
    {
        return [
            'results' => $results,
            'status' => $status,
            'code' => (int)($fetched['code'] ?? 0),
            'error' => (string)($fetched['error'] ?? ''),
            'reason' => $status === 'rejected' || $status === 'transport' ? $this->searchFailureReason($fetched) : '',
            'allowInstant' => $allowInstant,
        ];
    }

    private function duckPageStatus(array $fetched): string
    {
        $body = (string)$fetched['body'];
        if ($this->searchFailedToConnect($fetched)) {
            return 'transport';
        }
        if ((int)$fetched['code'] !== 200 || trim($body) === '') {
            return 'rejected';
        }
        if ($this->isSearchBotWall($body)) {
            return 'rejected';
        }
        if (stripos($body, 'result__a') !== false || stripos($body, 'uddg=') !== false) {
            return 'unparsed';
        }
        if (stripos($body, 'no results') !== false) {
            return 'empty';
        }
        return 'rejected';
    }

    private function searchFailedToConnect(array $fetched): bool
    {
        $code = (int)($fetched['code'] ?? 0);
        $error = (string)($fetched['error'] ?? '');
        $body = (string)($fetched['body'] ?? '');
        return $code === 0 || ($error !== '' && trim($body) === '');
    }

    private function searchFailureReason(array $fetched): string
    {
        $body = (string)($fetched['body'] ?? '');
        if ($this->searchFailedToConnect($fetched)) {
            return 'no response';
        }
        if (trim($body) === '') {
            return 'empty page';
        }
        if ($this->isSearchBotWall($body)) {
            return 'bot check';
        }
        if ((int)($fetched['code'] ?? 0) !== 200) {
            return '';
        }
        return 'not a results page';
    }

    private function isSearchBotWall(string $body): bool
    {
        $hay = strtolower($body);
        foreach (['anomaly', 'bots use duckduckgo', 'challenge-form', 'challenge form', 'unusual traffic', 'g-recaptcha', '/sorry/index', 'our systems have detected'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function providersRejected(array $misses): string
    {
        $detail = $misses ? implode('; ', $misses) : 'no search engine answered';
        return 'The search provider rejected this request. The query was accepted (' . $detail . '). End this run now. Do not call another tool. Do not fetch pages, rewrite the query, or log research. Report this failure to the main agent.';
    }

    private function parseSearchResults(string $html): array
    {
        $html = trim($html);
        if ($html === '' || !preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches as $match) {
            $attrs = $match[1][0];
            $class = $this->htmlAttr($attrs, 'class');
            $href = $this->htmlAttr($attrs, 'href');
            $isResult = str_contains($class, 'result__a') || str_contains($class, 'result-link') || str_contains($href, 'uddg=');
            if (!$isResult) {
                continue;
            }
            $url = $this->searchResultUrl($href);
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($url === '' || $title === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $end = $match[0][1] + strlen($match[0][0]);
            $out[] = ['title' => $title, 'url' => $url, 'snippet' => $this->searchSnippetNear(substr($html, $end, 1800))];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private function searchSnippetNear(string $html): string
    {
        if (!preg_match('/class\s*=\s*(["\'])[^"\']*result(?:__|-)?snippet[^"\']*\1[^>]*>(.*?)<\/(?:a|td|span|div)>/is', $html, $match)) {
            return '';
        }
        $text = html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function htmlAttr(string $attrs, string $name): string
    {
        if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $attrs, $match)) {
            return '';
        }
        return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function xmlText(string $xml, string $tag): string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is', $xml, $match)) {
            return '';
        }
        $text = trim($match[1]);
        if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', $text, $wrapped)) {
            $text = $wrapped[1];
        }
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function searchResultUrl(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        $parts = parse_url($href);
        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['uddg']) && is_string($query['uddg'])) {
                $href = $query['uddg'];
            } elseif (!empty($query['u']) && is_string($query['u']) && str_contains(strtolower((string)($parts['host'] ?? '')), 'bing.com')) {
                $raw = $query['u'];
                if (str_starts_with($raw, 'a1')) {
                    $raw = substr($raw, 2);
                }
                $raw = strtr($raw, '-_', '+/');
                $pad = strlen($raw) % 4;
                if ($pad > 0) {
                    $raw .= str_repeat('=', 4 - $pad);
                }
                $decoded = base64_decode($raw, true);
                if (is_string($decoded) && preg_match('#^https?://#i', $decoded)) {
                    $href = $decoded;
                }
            }
        }
        $parts = parse_url($href);
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || str_ends_with($host, 'duckduckgo.com') || str_ends_with($host, 'bing.com') || str_ends_with($host, 'google.com') || str_contains($host, '.google.')) {
            return '';
        }
        return $this->absoluteUrl($href, $href);
    }

    private function instantAnswer(string $query, array $locale): string
    {
        $body = $this->request('https://api.duckduckgo.com/?format=json&no_html=1&skip_disambig=1&kl=' . rawurlencode($locale['duck']) . '&q=' . rawurlencode($query));
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return '';
        }
        $lines = [];
        if (!empty($json['Heading'])) {
            $lines[] = (string)$json['Heading'];
        }
        if (!empty($json['AbstractText'])) {
            $lines[] = (string)$json['AbstractText'];
        }
        if (!empty($json['AbstractURL'])) {
            $lines[] = (string)$json['AbstractURL'];
        }
        foreach (array_slice($json['RelatedTopics'] ?? [], 0, 5) as $topic) {
            if (is_array($topic) && !empty($topic['Text'])) {
                $lines[] = '- ' . $topic['Text'];
            }
        }
        return $lines ? clip_text(implode("\n", $lines), 4000) : '';
    }

    private function fetchUrl(string $url): string
    {
        $url = $this->normalizeUrl($url);
        $direct = $this->githubDirect($url);
        if ($direct !== null) {
            return $direct;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only public http and https URLs are allowed.';
        }
        if (!$this->hostIsPublic($host)) {
            return 'That address is not a public host.';
        }
        $final = $url;
        $meta = [];
        $body = $this->request($url, 0, null, $final, $meta);
        $rendered = false;
        if ($this->yahooDenied($url, $meta, $body)) {
            if ($this->db->getSetting('headless_browser', '0') !== '1') {
                return 'Yahoo answered HTTP 429 to this client. A browser still receives the JSON. The headless browser is off.';
            }
            $opened = $this->yahooBrowser($url);
            if ($opened === '') {
                return 'Yahoo answered HTTP 429 to this client. A browser still receives the JSON. The headless browser did not return it.';
            }
            $body = $opened;
            $rendered = true;
        }
        if (trim($body) === '') {
            return 'The page returned nothing.';
        }
        $json = $this->jsonBody($body, $meta);
        if ($json !== '') {
            return $json;
        }
        $pageUrl = $final !== '' ? $final : $url;
        $feed = $this->feedText($body, $pageUrl);
        if ($feed !== '') {
            return $feed;
        }
        $text = $this->pageText($body, $pageUrl, $rendered);
        $shell = $this->githubShell($url, $text);
        if ($shell !== null) {
            return $shell;
        }
        return $text;
    }

    private function yahooDenied(string $url, array $meta, string $body): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== 'query1.finance.yahoo.com' && $host !== 'query2.finance.yahoo.com') {
            return false;
        }
        if ((int)($meta['code'] ?? 0) === 429) {
            return true;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
        return $text === 'Too Many Requests' || str_starts_with($text, 'Too Many Requests');
    }

    private function yahooBrowser(string $url): string
    {
        $html = Sight::page($url);
        if ($html === '' || !str_contains($html, '<')) {
            return '';
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($text === '' || $text === 'Too Many Requests' || str_starts_with($text, 'Too Many Requests')) {
            return '';
        }
        return $html;
    }

    private function jsonBody(string $body, array $meta): string
    {
        $body = trim($body);
        if ($body === '' || ($body[0] !== '{' && $body[0] !== '[')) {
            return '';
        }
        $header = (string)($meta['header'] ?? '');
        $typed = (bool)preg_match('/^content-type:\s*[^\r\n]*json/im', $header);
        if (!$typed) {
            json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return '';
            }
        }
        return clip_text($body, 8000);
    }

    private function githubDirect(string $url): ?string
    {
        $repo = $this->githubPath($url);
        if ($repo === null) {
            return null;
        }
        if ($repo['kind'] === 'topic') {
            return $this->githubTopic($repo['topic']);
        }
        if ($repo['kind'] === 'blob') {
            return $this->githubRaw($repo['owner'], $repo['repo'], $repo['branch'], $repo['file']);
        }
        if ($repo['kind'] === 'repo') {
            return $this->githubReadme($repo['owner'], $repo['repo']);
        }
        return null;
    }

    private function githubShell(string $url, string $text): ?string
    {
        if (strlen($text) >= 1800) {
            return null;
        }
        $repo = $this->githubPath($url);
        if ($repo === null || $repo['kind'] !== 'shell') {
            return null;
        }
        return $this->githubReadme($repo['owner'], $repo['repo']);
    }

    private function githubPath(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ($host !== 'github.com') {
            return null;
        }
        $bits = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), static fn(string $bit): bool => $bit !== ''));
        if (count($bits) >= 2 && strtolower($bits[0]) === 'topics' && $this->githubName($bits[1])) {
            return ['kind' => 'topic', 'topic' => $bits[1]];
        }
        if (count($bits) < 2 || !$this->githubName($bits[0]) || !$this->githubName($bits[1]) || $this->githubReserved($bits[0])) {
            return null;
        }
        if (count($bits) === 2) {
            return ['kind' => 'repo', 'owner' => $bits[0], 'repo' => $bits[1]];
        }
        if (count($bits) >= 5 && strtolower($bits[2]) === 'blob' && $this->githubName($bits[3])) {
            return [
                'kind' => 'blob',
                'owner' => $bits[0],
                'repo' => $bits[1],
                'branch' => $bits[3],
                'file' => implode('/', array_slice($bits, 4)),
            ];
        }
        return ['kind' => 'shell', 'owner' => $bits[0], 'repo' => $bits[1]];
    }

    private function githubName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_.-]+$/', $name);
    }

    private function githubReserved(string $name): bool
    {
        return in_array(strtolower($name), [
            'topics', 'features', 'login', 'signup', 'settings', 'explore', 'marketplace', 'sponsors',
            'about', 'pricing', 'security', 'orgs', 'organizations', 'notifications', 'new', 'codespaces',
            'copilot', 'account', 'search', 'trending', 'collections', 'events', 'issues', 'pulls',
            'readme', 'apps', 'customer-stories', 'enterprise', 'team', 'resources', 'solutions',
            'why-github', 'git', 'pages', 'community', 'education', 'nonprofit', 'join', 'users', 'site',
        ], true);
    }

    private function githubReadme(string $owner, string $repo): string
    {
        foreach (['main', 'master'] as $branch) {
            $text = $this->githubFile($owner, $repo, $branch, 'README.md');
            if ($text !== null) {
                return 'README from ' . $owner . '/' . $repo . ' (' . $branch . ').' . "\n\n" . $text;
            }
        }
        return 'The GitHub README could not be read for ' . $owner . '/' . $repo . '.';
    }

    private function githubRaw(string $owner, string $repo, string $branch, string $file): string
    {
        $text = $this->githubFile($owner, $repo, $branch, $file);
        if ($text === null) {
            return 'The GitHub file could not be read: ' . $owner . '/' . $repo . ' ' . $file . '.';
        }
        return 'File from ' . $owner . '/' . $repo . ' (' . $branch . ') ' . $file . '.' . "\n\n" . $text;
    }

    private function githubFile(string $owner, string $repo, string $branch, string $file): ?string
    {
        $segments = array_map('rawurlencode', explode('/', $file));
        $url = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/' . implode('/', $segments);
        $meta = [];
        $final = $url;
        $body = $this->request($url, 0, null, $final, $meta);
        if ((int)($meta['code'] ?? 0) !== 200 || trim($body) === '') {
            return null;
        }
        return clip_text($body, 8000);
    }

    private function githubTopic(string $topic): string
    {
        $url = 'https://api.github.com/search/repositories?q=' . rawurlencode('topic:' . $topic) . '&per_page=10&sort=stars';
        $meta = [];
        $final = $url;
        $body = $this->request($url, 0, null, $final, $meta, ['Accept: application/vnd.github+json']);
        $data = json_decode($body, true);
        $items = is_array($data) ? ($data['items'] ?? null) : null;
        if ((int)($meta['code'] ?? 0) !== 200 || !is_array($items)) {
            return 'The GitHub topic list could not be read for ' . $topic . '.';
        }
        $blocks = ['GitHub topic ' . $topic . '.'];
        $n = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['full_name'] ?? ''));
            $link = trim((string)($item['html_url'] ?? ''));
            if ($name === '' || $link === '') {
                continue;
            }
            $n++;
            $block = $n . '. ' . $name . "\n" . $link;
            $about = trim((string)($item['description'] ?? ''));
            if ($about !== '') {
                $block .= "\n" . $about;
            }
            $blocks[] = $block;
            if ($n >= 10) {
                break;
            }
        }
        if ($n === 0) {
            return 'The GitHub topic ' . $topic . ' had no repositories.';
        }
        return clip_text(implode("\n\n", $blocks), 8000);
    }

    private function looksLikeFeed(string $body): bool
    {
        $start = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        $start = preg_replace('/^(?:<\?xml\b[^>]*\?>\s*|<!--.*?-->\s*)+/is', '', $start) ?? $start;
        return (bool)preg_match('/^<(?:(?:[a-zA-Z0-9]+:)?(?:rss|feed)|rdf:RDF|RDF)\b/i', ltrim($start));
    }

    private function feedText(string $body, string $pageUrl): string
    {
        if (!$this->looksLikeFeed($body)) {
            return '';
        }
        $body = $this->feedCharset($body);
        $xml = preg_replace('/<(\/?)[a-zA-Z0-9]+:/', '<$1', $body) ?? $body;
        if (!preg_match_all('/<(item|entry)\b([^>]*)>(.*?)<\/\1>/is', $xml, $matches, PREG_SET_ORDER)) {
            return '';
        }
        $blocks = [];
        $n = 0;
        foreach ($matches as $match) {
            $item = $match[2] . '>' . $match[3];
            $title = $this->plainFeedText($this->xmlText($match[3], 'title'));
            $link = $this->feedLink($item, $pageUrl);
            if ($title === '' && $link === '') {
                continue;
            }
            if ($title === '') {
                $title = $link;
            }
            $n++;
            $block = $n . '. ' . $title;
            if ($link !== '') {
                $block .= "\n" . $link;
            }
            $date = $this->feedDate($match[3]);
            $summary = $this->feedSummary($match[3]);
            if ($date !== '') {
                $block .= "\n" . $date;
            }
            if ($summary !== '') {
                $block .= "\n" . $summary;
            }
            $blocks[] = $block;
            if ($n >= 15) {
                break;
            }
        }
        if (!$blocks) {
            return '';
        }
        $name = $this->plainFeedText($this->xmlText($xml, 'title'));
        $head = $name !== '' ? 'Items from ' . $name . '.' : 'Items from the feed.';
        array_unshift($blocks, $head);
        return clip_text(implode("\n\n", $blocks), 8000);
    }

    private function feedCharset(string $body): string
    {
        $body = $this->normalizeCharset($body);
        if (!preg_match('/<\?xml\b[^>]*\bencoding\s*=\s*["\']([a-zA-Z0-9_-]+)/i', $body, $match)) {
            return $body;
        }
        $charset = strtoupper($match[1]);
        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            return $body;
        }
        $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
        return is_string($converted) && $converted !== '' ? $converted : $body;
    }

    private function feedLink(string $item, string $pageUrl): string
    {
        $text = trim($this->xmlText($item, 'link'));
        if (preg_match('#^https?://#i', $text) || str_starts_with($text, '/')) {
            $abs = $this->absoluteUrl($text, $pageUrl);
            if (preg_match('#^https?://#i', $abs)) {
                return $abs;
            }
        }
        if (preg_match_all('/<link\b([^>]*)\/?>/i', $item, $matches)) {
            $fallback = '';
            foreach ($matches[1] as $attrs) {
                $href = $this->absoluteUrl(trim($this->htmlAttr($attrs, 'href')), $pageUrl);
                if (!preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $rel = strtolower(trim($this->htmlAttr($attrs, 'rel')));
                if ($rel === '' || $rel === 'alternate') {
                    return $href;
                }
                if ($fallback === '' && !in_array($rel, ['self', 'edit', 'replies'], true)) {
                    $fallback = $href;
                }
            }
            if ($fallback !== '') {
                return $fallback;
            }
        }
        if (preg_match('/\b(?:rdf:)?about\s*=\s*(["\'])(https?:\/\/.*?)\1/i', $item, $match)) {
            return $this->absoluteUrl(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);
        }
        foreach (['guid', 'id'] as $tag) {
            $guid = trim($this->xmlText($item, $tag));
            if (preg_match('#^https?://#i', $guid)) {
                return $this->absoluteUrl($guid, $pageUrl);
            }
        }
        return '';
    }

    private function feedDate(string $item): string
    {
        foreach (['pubDate', 'published', 'updated', 'date'] as $tag) {
            $value = trim($this->xmlText($item, $tag));
            if ($value === '') {
                continue;
            }
            $calendar = preg_replace('/^[A-Za-z]+,\s*/', '', $value) ?? $value;
            $parsed = date_create($calendar);
            if ($parsed instanceof DateTimeInterface) {
                return $parsed->format('Y-m-d H:i');
            }
            return $this->plainFeedText($value);
        }
        return '';
    }

    private function feedSummary(string $item): string
    {
        $raw = '';
        foreach (['description', 'summary', 'encoded', 'content'] as $tag) {
            $raw = $this->xmlText($item, $tag);
            if (trim($raw) !== '') {
                break;
            }
        }
        $text = $this->plainFeedText($raw);
        if (mb_strlen($text) > 240) {
            $text = rtrim(mb_substr($text, 0, 237)) . '...';
        }
        return $text;
    }

    private function plainFeedText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function browseUrl(string $url): string
    {
        if ($this->db->getSetting('headless_browser', '0') !== '1') {
            return 'The headless browser is off, so that page cannot be opened.';
        }
        $url = $this->normalizeUrl($url);
        $html = Sight::page($url);
        if ($html === '' || !str_contains($html, '<')) {
            return $html !== '' ? $html : 'The page returned nothing.';
        }
        return $this->pageText($html, $url, true);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = str_replace('\\/', '/', $url);
        return str_replace('\\', '', $url);
    }

    private function pageText(string $html, string $pageUrl, bool $rendered = false): string
    {
        $html = $this->normalizeCharset($html);
        $links = $this->pageLinks($html, $pageUrl);
        $html = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        $parts = [$rendered
            ? 'Text and links from the page after the headless browser ran its scripts.'
            : 'Text and links from the page. JavaScript on the page is not run.'];
        if ($links) {
            $parts[] = "Links:\n" . implode("\n", $links);
        }
        if ($text !== '') {
            $parts[] = $text;
        }
        return clip_text(implode("\n\n", $parts), 8000);
    }

    private function pageLinks(string $html, string $pageUrl): array
    {
        $baseHost = strtolower((string)(parse_url($pageUrl, PHP_URL_HOST) ?? ''));
        if (!preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
            return [];
        }
        $same = [];
        $other = [];
        foreach ($matches[2] as $href) {
            $abs = $this->absoluteUrl(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);
            if ($abs === '' || $this->skipLink($abs)) {
                continue;
            }
            $host = strtolower((string)(parse_url($abs, PHP_URL_HOST) ?? ''));
            if ($host === $baseHost) {
                $same[$abs] = true;
            } else {
                $other[$abs] = true;
            }
        }
        $urls = array_keys($same);
        if (count($urls) < 12) {
            foreach (array_keys($other) as $url) {
                $urls[] = $url;
                if (count($urls) >= 12) {
                    break;
                }
            }
        }
        return array_slice($urls, 0, 12);
    }

    private function skipLink(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        return (bool)preg_match('/\.(?:css|js|mjs|jpg|jpeg|png|gif|svg|webp|ico|pdf|zip|gz|mp3|mp4|woff2?|ttf)$/', $path);
    }

    private function normalizeCharset(string $html): string
    {
        if (!preg_match('/charset\s*=\s*["\']?\s*([a-zA-Z0-9_-]+)/i', $html, $match)) {
            return $html;
        }
        $charset = strtoupper($match[1]);
        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            return $html;
        }
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        return is_string($converted) && $converted !== '' ? $converted : $html;
    }

    private function absoluteUrl(string $href, string $base): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|data|tel):/i', $href)) {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $href = $scheme . ':' . $href;
        } elseif (!preg_match('#^https?://#i', $href)) {
            $parts = parse_url($base);
            if (!is_array($parts) || empty($parts['host'])) {
                return '';
            }
            $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            if (str_starts_with($href, '/')) {
                $href = $origin . $href;
            } else {
                $path = (string)($parts['path'] ?? '/');
                $dir = str_ends_with($path, '/') ? $path : (preg_replace('#/[^/]*$#', '/', $path) ?? '/');
                $href = $origin . $dir . $href;
            }
        }
        $parts = parse_url($href);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }
        $bits = [];
        foreach (explode('/', (string)($parts['path'] ?? '/')) as $bit) {
            if ($bit === '' || $bit === '.') {
                continue;
            }
            if ($bit === '..') {
                array_pop($bits);
                continue;
            }
            $bits[] = $bit;
        }
        $url = $scheme . '://' . $host;
        if (!empty($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= '/' . implode('/', $bits);
        if (!empty($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        return $url;
    }

    private function request(string $url, int $hops = 0, ?string $post = null, ?string &$finalUrl = null, ?array &$meta = null, array $extraHeaders = []): string
    {
        $finalUrl = $url;
        if ($hops > 4) {
            $this->rememberResponse($meta, 0, 'Too many redirects.');
            return '';
        }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '' || !$this->hostIsPublic($host)) {
            $this->rememberResponse($meta, 0, 'Could not reach the host.');
            return '';
        }
        if ($this->yahooApiHost($host)) {
            $extraHeaders = array_merge($this->yahooBrowserHeaders(), $extraHeaders);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            $this->rememberResponse($meta, 0, 'Could not open a request.');
            return '';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->requestHeaders($extraHeaders),
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $post;
        }
        curl_setopt_array($ch, $opts);
        if ($this->yahooApiHost($host)) {
            $this->yahooCurl($ch);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        unset($ch);
        if (!is_string($raw)) {
            $this->rememberResponse($meta, $code, $error !== '' ? $error : 'The request failed.');
            return '';
        }
        $header = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        if ($code >= 300 && $code < 400 && preg_match('/^Location:\s*(\S+)/im', $header, $match)) {
            $next = $this->absoluteUrl(trim($match[1]), $url);
            if ($next === '' || $next === $url) {
                $this->rememberResponse($meta, $code, '');
                return $body;
            }
            return $this->request($next, $hops + 1, null, $finalUrl, $meta, $extraHeaders);
        }
        $this->rememberResponse($meta, $code, $code === 0 ? ($error !== '' ? $error : 'The request failed.') : '', $header);
        return $body;
    }

    private function requestHeaders(array $extraHeaders): array
    {
        $headers = [];
        $accept = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        $language = 'Accept-Language: en,sv;q=0.8';
        foreach ($extraHeaders as $header) {
            if (stripos($header, 'Accept-Language:') === 0) {
                $language = $header;
                continue;
            }
            if (stripos($header, 'Accept:') === 0) {
                $accept = $header;
                continue;
            }
            $headers[] = $header;
        }
        array_unshift($headers, $accept);
        $headers[] = $language;
        return $headers;
    }

    private function rememberResponse(?array &$meta, int $code, string $error, string $header = ''): void
    {
        if ($meta === null) {
            return;
        }
        $meta['code'] = $code;
        $meta['error'] = $error;
        if ($header !== '') {
            $meta['header'] = $header;
        }
    }

    private function hostIsPublic(string $host, bool $allowUnresolved = false): bool
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ipIsPublic($host);
        }
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.local')) {
            return false;
        }
        $ips = [];
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $fallback = gethostbynamel($host) ?: [];
            $ips = $fallback;
        }
        if (!$ips) {
            return $allowUnresolved && (bool)preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host);
        }
        foreach ($ips as $ip) {
            if (!$this->ipIsPublic($ip)) {
                return false;
            }
        }
        return true;
    }

    private function ipIsPublic(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public function isInstallCommand(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }
        $patterns = [
            '/\b(apt-get|apt|brew|dnf|yum|pacman|apk|zypper|port|choco|winget|snap|flatpak)\s+install\b/i',
            '/\b(npm|pnpm|yarn|bun|pip|pip3|pipx|gem|cargo|conda|mamba)\s+(install|add|i|ci)\b/i',
            '/\bcomposer\s+(install|require|update)\b/i',
            '/\bgo\s+install\b/i',
            '/\b(pecl|poetry)\s+(install|add)\b/i',
            '/\bmake\s+install\b/i',
            '/\bpython[0-9.]*\s+-m\s+pip\s+install\b/i',
            '/\buv\s+(pip\s+)?install\b/i',
            '/\bdotnet\s+add\s+package\b/i',
            '/\bbundle\s+install\b/i',
            '/\b(curl|wget)\b[^|\n]*\|\s*(sudo\s+)?(ba)?sh\b/i',
            '/\b(install|setup)\.sh\b/i',
            '/^\s*(sudo\s+)?yarn\s*$/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }
        return false;
    }

    private function runCommand(string $command, string $projectFolder, bool $allowInstall = false): string
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 2000) {
            return 'Command is empty or too long.';
        }
        if ($this->isInstallCommand($command) && !$allowInstall) {
            $this->blockedInstalls[] = $command;
            return 'Install stopped. Only the main agent can install dependencies, and only after the user agrees. Do not try another install. Tell the main agent what was needed and why.';
        }
        if ($this->isPythonCommand($command)) {
            return 'Python runs through run_python. Pass path as the project .py file. Do not use run_command.';
        }
        if (!$this->db->fullSystemControl()) {
            return 'Full system control is off.';
        }
        if (preg_match('/(?:^|[\/\s\'"])[A-Za-z0-9._-]+\.(?:db|sqlite|sqlite3)(?:$|[\s\'"])/i', $command)) {
            return 'Database commands are blocked in run_command. Use sqlite_helper so the database filename is explicit and a recoverable backup is created before a write.';
        }
        $full = $this->db->fullSystemControl();
        $denied = [
            '/\brm\s+(-[a-zA-Z]*f[a-zA-Z]*\s+)+\/(\s|$)/i',
            '/\bmkfs(\.|\b)/i',
            '/\b(shutdown|reboot|halt|poweroff)\b/i',
            '/\bdd\s+/i',
            '/:\(\)\s*\{/',
            '/>\s*\/dev\//i',
        ];
        if (!$full) {
            $denied[] = '/\bsudo\b/i';
        }
        foreach ($denied as $pattern) {
            if (preg_match($pattern, $command)) {
                return 'Command blocked.';
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $projectFolder)) {
            $cwd = Projects::root($projectFolder);
            if (!is_dir($cwd)) {
                mkdir($cwd, 0775, true);
            }
        } elseif ($full) {
            $home = getenv('HOME');
            $cwd = is_string($home) && $home !== '' && is_dir($home) ? $home : '/';
        } else {
            return 'Invalid project.';
        }
        $bash = is_file('/bin/bash') ? '/bin/bash' : 'bash';
        $result = Process::run([$bash, '-lc', $command], $cwd, null, $full ? 120 : 15);
        $output = $result['stdout'];
        if ($result['stderr'] !== '') {
            $output .= ($output === '' ? '' : "\n") . $result['stderr'];
        }
        return 'exit ' . $result['code'] . "\n" . clip_text($output);
    }

    private function isPythonCommand(string $command): bool
    {
        return (bool)preg_match('/\bpython[0-9.]*(?=\s|$)/i', $command);
    }

    private function runPython(string $path, string $projectFolder): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return 'Name a .py file inside the project.';
        }
        if (!str_ends_with(strtolower($path), '.py')) {
            return 'run_python only runs a .py file. Write the program in PHP when you can. A skill program is PHP and is called by its skill name.';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $projectFolder)) {
            return 'Invalid project.';
        }
        try {
            $root = Projects::root($projectFolder);
        } catch (RuntimeException $e) {
            return 'Invalid project.';
        }
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return 'Invalid project.';
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return 'Invalid project.';
        }
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'database/')) {
            return 'Put the .py file outside database/. A database file is sqlite_helper, not a script.';
        }
        $full = $rootReal . '/' . $relative;
        if (is_link($full)) {
            return 'Symlinks are not allowed.';
        }
        $real = realpath($full);
        if ($real === false || !is_file($real) || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            return 'File not found: ' . $relative;
        }
        $source = file_get_contents($real);
        if ($source === false) {
            return 'File not found: ' . $relative;
        }
        if (strlen($source) > 200000) {
            return 'That Python file is too large to run.';
        }
        if (str_contains($source, "\0")) {
            return 'That Python file cannot be run.';
        }
        $refusal = $this->pythonDatabaseRefusal($source);
        if ($refusal !== '') {
            return $refusal;
        }
        $bwrap = $this->programPath('bwrap');
        if ($bwrap === '') {
            return 'run_python needs bubblewrap (bwrap). It is not installed, so the script was not started. Install or update Agent Danaco, which installs bubblewrap on Linux. Do not use run_command instead.';
        }
        $python = $this->programPath('python3');
        if ($python === '') {
            return 'python3 is not available, so the script was not started.';
        }
        $result = Process::run($this->pythonJailCommand($bwrap, $rootReal, $python, ['-B', $real]), $rootReal, null, 180, $this->pythonEnv($rootReal));
        $output = $result['stdout'];
        if ($result['stderr'] !== '') {
            $output .= ($output === '' ? '' : "\n") . $result['stderr'];
        }
        $missing = $this->missingPythonPackage($output);
        if ($missing !== '') {
            $this->missingPython[] = $missing;
            $output .= "\nMissing Python library: " . $missing . ". Stop. Report that name. Do not install it and do not use run_command.";
        }
        return 'exit ' . $result['code'] . "\n" . clip_text($output);
    }

    public function pythonPackageNames(mixed $value): array
    {
        $items = [];
        if (is_string($value)) {
            $items = preg_split('/[\s,]+/', trim($value)) ?: [];
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    foreach (preg_split('/[\s,]+/', trim($item)) ?: [] as $part) {
                        $items[] = $part;
                    }
                }
            }
        }
        $names = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '' || !$this->validPythonPackage($item) || isset($names[$item])) {
                continue;
            }
            $names[$item] = $item;
            if (count($names) >= 8) {
                break;
            }
        }
        return array_values($names);
    }

    public function installPythonPackages(string $projectFolder, array $names): string
    {
        $names = $this->pythonPackageNames($names);
        if ($names === []) {
            return 'Name the Python library to install, such as yfinance.';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $projectFolder)) {
            return 'Invalid project.';
        }
        try {
            $root = Projects::root($projectFolder);
        } catch (RuntimeException $e) {
            return 'Invalid project.';
        }
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return 'Invalid project.';
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return 'Invalid project.';
        }
        $bwrap = $this->programPath('bwrap');
        if ($bwrap === '') {
            return 'Python libraries need bubblewrap (bwrap). It is not installed. Install or update Agent Danaco, which installs it on Linux.';
        }
        $python = $this->programPath('python3');
        if ($python === '') {
            return 'python3 is not available, so the library was not installed.';
        }
        $probe = Process::run([$python, '-m', 'pip', '--version'], null, null, 15);
        if ($probe['code'] !== 0) {
            return 'python3-pip is not installed, so the library was not installed. Install or update Agent Danaco, which installs it on Linux.';
        }
        $args = ['-m', 'pip', 'install', '--disable-pip-version-check', '--no-cache-dir', '--target', $rootReal . '/.py-packages'];
        foreach ($names as $name) {
            $args[] = $name;
        }
        $result = Process::run($this->pythonJailCommand($bwrap, $rootReal, $python, $args), $rootReal, null, 300, $this->pythonEnv($rootReal));
        $output = $result['stdout'];
        if ($result['stderr'] !== '') {
            $output .= ($output === '' ? '' : "\n") . $result['stderr'];
        }
        if ($result['code'] !== 0) {
            return 'Could not install ' . implode(', ', $names) . ".\n" . clip_text($output);
        }
        return 'Installed ' . implode(', ', $names) . ' inside the project. Run the Python file again with run_python.' . "\n" . clip_text($output);
    }

    private function pythonDatabaseRefusal(string $source): string
    {
        if (preg_match('/sqlite3/i', $source) || preg_match('/\.(?:db|sqlite|sqlite3)\b/i', $source)) {
            return 'This script opens a database. Use sqlite_helper to query or insert, write those rows to a JSON or CSV file in the project, and have the script read that file. Do not import sqlite3.';
        }
        return '';
    }

    private function programPath(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/', $name)) {
            return '';
        }
        $result = Process::run(['/bin/sh', '-c', 'command -v ' . $name], null, null, 5);
        $path = trim($result['stdout']);
        if ($result['code'] !== 0 || !str_starts_with($path, '/') || str_contains($path, "\n") || !is_file($path)) {
            return '';
        }
        $real = realpath($path);
        return $real !== false ? $real : '';
    }

    private function pythonEnv(string $root): array
    {
        return [
            'PATH' => '/usr/bin:/bin',
            'HOME' => '/tmp',
            'TMPDIR' => '/tmp',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONNOUSERSITE' => '1',
            'PYTHONPATH' => $root . '/.py-packages',
            'PIP_DISABLE_PIP_VERSION_CHECK' => '1',
        ];
    }

    private function missingPythonPackage(string $output): string
    {
        if (!preg_match("/No module named ['\"]([^'\"]+)['\"]/", $output, $match)) {
            return '';
        }
        $module = explode('.', $match[1])[0];
        $map = [
            'PIL' => 'Pillow',
            'cv2' => 'opencv-python',
            'sklearn' => 'scikit-learn',
            'yaml' => 'PyYAML',
            'bs4' => 'beautifulsoup4',
            'dateutil' => 'python-dateutil',
        ];
        $package = $map[$module] ?? $module;
        return $this->validPythonPackage($package) ? $package : '';
    }

    private function validPythonPackage(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $name);
    }

    private function pythonJailCommand(string $bwrap, string $root, string $python, array $args): array
    {
        $cmd = [
            $bwrap,
            '--unshare-user',
            '--unshare-pid',
            '--unshare-ipc',
            '--unshare-uts',
            '--die-with-parent',
            '--new-session',
            '--proc', '/proc',
            '--dev', '/dev',
            '--tmpfs', '/tmp',
        ];
        foreach (['/usr', '/lib', '/lib64', '/bin'] as $host) {
            $this->bindSystemPath($cmd, $host);
        }
        foreach (['/etc/ssl', '/etc/ca-certificates', '/etc/ld.so.cache', '/etc/passwd', '/etc/group'] as $host) {
            if (is_file($host) || (is_dir($host) && !is_link($host))) {
                $cmd[] = '--ro-bind';
                $cmd[] = $host;
                $cmd[] = $host;
            }
        }
        foreach (['/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf'] as $host) {
            $source = $this->plainFile($host);
            if ($source === '') {
                continue;
            }
            $cmd[] = '--ro-bind';
            $cmd[] = $source;
            $cmd[] = $host;
        }
        $pythonDir = dirname($python);
        if (!str_starts_with($pythonDir, '/usr/') && !str_starts_with($pythonDir, '/bin/') && $pythonDir !== '/usr' && $pythonDir !== '/bin') {
            $cmd[] = '--ro-bind';
            $cmd[] = $python;
            $cmd[] = $python;
        }
        $cmd[] = '--bind';
        $cmd[] = $root;
        $cmd[] = $root;
        $cmd[] = '--tmpfs';
        $cmd[] = $root . '/database';
        $cmd[] = '--remount-ro';
        $cmd[] = $root . '/database';
        foreach ($this->projectDatabaseFiles($root) as $db) {
            if (str_starts_with($db, $root . '/database/')) {
                continue;
            }
            $cmd[] = '--ro-bind';
            $cmd[] = '/dev/null';
            $cmd[] = $db;
        }
        $cmd[] = '--chdir';
        $cmd[] = $root;
        $cmd[] = '--';
        $cmd[] = $python;
        foreach ($args as $arg) {
            $cmd[] = $arg;
        }
        return $cmd;
    }

    private function bindSystemPath(array &$cmd, string $host): void
    {
        if (is_link($host)) {
            $target = readlink($host);
            if (is_string($target) && $target !== '' && !str_contains($target, "\0")) {
                $cmd[] = '--symlink';
                $cmd[] = $target;
                $cmd[] = $host;
            }
            return;
        }
        if (is_dir($host)) {
            $cmd[] = '--ro-bind';
            $cmd[] = $host;
            $cmd[] = $host;
        }
    }

    private function plainFile(string $path): string
    {
        if (!is_file($path) && !is_link($path)) {
            return '';
        }
        if (!is_link($path)) {
            return $path;
        }
        $data = @file_get_contents($path);
        if (!is_string($data)) {
            return '';
        }
        $dir = rtrim(sys_get_temp_dir(), '/') . '/danaco-py';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }
        $file = $dir . '/' . basename($path);
        if (file_put_contents($file, $data) === false) {
            return '';
        }
        return $file;
    }

    private function projectDatabaseFiles(string $root): array
    {
        $found = [];
        $this->collectDatabaseFiles($root, $found, 0);
        return $found;
    }

    private function collectDatabaseFiles(string $dir, array &$found, int $depth): void
    {
        if ($depth > 8 || count($found) >= 200) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.py-packages') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $this->collectDatabaseFiles($full, $found, $depth + 1);
                continue;
            }
            if (is_file($full) && preg_match('/\.(?:db|sqlite|sqlite3)$/i', $name)) {
                $found[] = $full;
            }
        }
    }

    public function yahooFinance(array $args): string
    {
        $action = $this->yahooAction($args);
        if ($action === '') {
            return 'action is quote, history, dividends, splits, events, or search.';
        }
        $asked = trim((string)($args['symbol'] ?? $args['ticker'] ?? ''));
        if ($asked === '') {
            $asked = trim((string)($args['query'] ?? ''));
        }
        $query = $this->yahooQuery($asked);
        if ($query === '' || !preg_match('/^[A-Z0-9^.= -]{1,80}$/', $query)) {
            return 'Pass a ticker or Yahoo symbol, such as NIBE-B or NIBE-B.ST.';
        }
        $type = $this->yahooType((string)($args['type'] ?? $args['kind'] ?? ''));
        if ($type === null) {
            return 'type is stock, etf, fund, index, bond, commodity, crypto, fx, or future.';
        }
        $exchange = strtoupper(trim((string)($args['exchange'] ?? '')));
        if ($exchange !== '' && !in_array($exchange, $this->yahooExchanges(), true)) {
            return 'exchange is a Yahoo suffix such as ST, L, or TO.';
        }
        $window = $this->yahooWindow($action, $args);
        if (is_string($window)) {
            return $window;
        }
        if ($action !== 'search') {
            $query = $this->yahooAlias($query, $type);
        }
        $picked = null;
        $symbol = $query;
        $lookup = $action === 'search' || !$this->yahooDirect($query) || ($type !== '' && !$this->yahooEncoded($query));
        if ($lookup) {
            $picked = $this->yahooFind($query, $exchange, $type);
            if (is_string($picked)) {
                return $picked;
            }
            if ($action === 'search') {
                return $this->yahooSearchText($query, $type, $picked);
            }
            $symbol = (string)$picked['symbol'];
        }
        $chart = $this->yahooLoadChart($symbol, $window);
        if (is_string($chart)) {
            if ($picked !== null) {
                return $chart;
            }
            $picked = $this->yahooFind($query, $exchange, $type);
            if (is_string($picked) || (string)$picked['symbol'] === $symbol) {
                return $chart;
            }
            $symbol = (string)$picked['symbol'];
            $chart = $this->yahooLoadChart($symbol, $window);
            if (is_string($chart)) {
                return $chart;
            }
        }
        return $this->yahooPresent($action, $asked, $symbol, is_array($picked) ? $picked : [], $chart, $window);
    }

    private function yahooAction(array $args): string
    {
        $action = strtolower(trim((string)($args['action'] ?? '')));
        $map = [
            'quote' => 'quote',
            'price' => 'quote',
            'history' => 'history',
            'chart' => 'history',
            'prices' => 'history',
            'dividends' => 'dividends',
            'dividend' => 'dividends',
            'splits' => 'splits',
            'split' => 'splits',
            'events' => 'events',
            'search' => 'search',
            'lookup' => 'search',
        ];
        if ($action !== '' && !isset($map[$action])) {
            return '';
        }
        if ($action !== '') {
            return $map[$action];
        }
        foreach (['range', 'interval', 'start', 'end'] as $key) {
            if (trim((string)($args[$key] ?? '')) !== '') {
                return 'history';
            }
        }
        return 'quote';
    }

    private function yahooQuery(string $raw): string
    {
        $text = strtoupper(trim($raw));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (preg_match('/^[A-Z0-9^][A-Z0-9.^*=-]{0,30} [A-Z]$/', $text)) {
            $text = str_replace(' ', '-', $text);
        }
        if (preg_match('/^([A-Z0-9]{1,12})\.([A-Z])$/', $text, $match) && !in_array($match[2], $this->yahooExchanges(), true)) {
            $text = $match[1] . '-' . $match[2];
        }
        return $text;
    }

    private function yahooDirect(string $symbol): bool
    {
        if ($symbol === '' || str_contains($symbol, ' ')) {
            return false;
        }
        if ($this->yahooEncoded($symbol)) {
            return true;
        }
        return (bool)preg_match('/^[A-Z][A-Z0-9]{0,9}$/', $symbol);
    }

    private function yahooType(string $type): ?string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return '';
        }
        $map = [
            'stock' => 'stock',
            'equity' => 'stock',
            'etf' => 'etf',
            'fund' => 'fund',
            'mutualfund' => 'fund',
            'index' => 'index',
            'bond' => 'bond',
            'bonds' => 'bond',
            'yield' => 'bond',
            'commodity' => 'commodity',
            'commodities' => 'commodity',
            'fx' => 'fx',
            'currency' => 'fx',
            'forex' => 'fx',
            'crypto' => 'crypto',
            'cryptocurrency' => 'crypto',
            'future' => 'future',
            'futures' => 'future',
            'option' => 'option',
            'options' => 'option',
        ];
        return $map[$type] ?? null;
    }

    private function yahooWindow(string $action, array $args): array|string
    {
        $range = strtolower(trim((string)($args['range'] ?? '')));
        $interval = strtolower(trim((string)($args['interval'] ?? '')));
        $start = trim((string)($args['start'] ?? ''));
        $end = trim((string)($args['end'] ?? ''));
        if ($range !== '' && !$this->yahooRangeOk($range)) {
            return 'range is a number of days such as 10d or 30d, or 1mo, 3mo, 6mo, 1y, 2y, 5y, 10y, ytd, or max.';
        }
        if ($interval !== '' && !in_array($interval, ['1m', '2m', '5m', '15m', '30m', '60m', '90m', '1h', '1d', '5d', '1wk', '1mo', '3mo'], true)) {
            return 'interval is 1m, 2m, 5m, 15m, 30m, 60m, 90m, 1h, 1d, 5d, 1wk, 1mo, or 3mo.';
        }
        $params = ['includeAdjustedClose' => 'true', 'events' => 'div|split'];
        if ($action === 'quote' || $action === 'search') {
            $params['interval'] = '1d';
            $params['range'] = '5d';
            return $params;
        }
        if ($start !== '' || $end !== '') {
            if ($start === '' || $end === '') {
                return 'Pass both start and end as YYYY-MM-DD, or pass range.';
            }
            $from = $this->yahooStamp($start, false);
            $to = $this->yahooStamp($end, true);
            if ($from === null || $to === null) {
                return 'start and end are dates, YYYY-MM-DD.';
            }
            if ($from > $to) {
                return 'start is after end.';
            }
            $params['period1'] = (string)$from;
            $params['period2'] = (string)$to;
            $params['interval'] = $interval !== '' ? $interval : ($action === 'history' ? '1d' : '1mo');
            return $params;
        }
        if ($action === 'history') {
            $params['range'] = $range !== '' ? $range : '1y';
            $params['interval'] = $interval !== '' ? $interval : '1d';
            return $params;
        }
        $params['range'] = $range !== '' ? $range : 'max';
        $params['interval'] = '1mo';
        return $params;
    }

    private function yahooRangeOk(string $range): bool
    {
        if (in_array($range, ['1d', '5d', '1mo', '3mo', '6mo', '1y', '2y', '5y', '10y', 'ytd', 'max'], true)) {
            return true;
        }
        return (bool)preg_match('/^[1-9]\d{0,3}d$/', $range);
    }

    private function yahooStamp(string $value, bool $end): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) || !checkdate((int)$match[2], (int)$match[3], (int)$match[1])) {
            return null;
        }
        $stamp = strtotime($value . ' ' . ($end ? '23:59:59' : '00:00:00') . ' UTC');
        return $stamp === false ? null : $stamp;
    }

    private function yahooFind(string $query, string $exchange, string $type): array|string
    {
        $url = 'https://query2.finance.yahoo.com/v1/finance/search?quotesCount=8&newsCount=0&q=' . rawurlencode($query);
        $json = $this->yahooJson($url);
        if (is_string($json)) {
            return $json;
        }
        $quotes = $json['quotes'] ?? null;
        if (!is_array($quotes) || $quotes === []) {
            return 'Yahoo has no listing for ' . $query . '.';
        }
        $rows = [];
        foreach ($quotes as $quote) {
            if (!is_array($quote)) {
                continue;
            }
            $symbol = strtoupper(trim((string)($quote['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $rows[] = $quote;
        }
        if ($rows === []) {
            return 'Yahoo has no listing for ' . $query . '.';
        }
        $pool = $type === '' ? $rows : array_values(array_filter($rows, fn(array $quote): bool => $this->yahooTypeMatch($type, $quote)));
        if ($pool === []) {
            $bits = [];
            foreach (array_slice($rows, 0, 5) as $quote) {
                $bits[] = strtoupper((string)$quote['symbol']) . ' (' . $this->yahooKind((string)($quote['quoteType'] ?? '')) . ')';
            }
            return 'No ' . $type . ' matched ' . $query . '. Yahoo returned: ' . implode(', ', $bits) . '.';
        }
        $best = -1;
        $scored = [];
        foreach ($pool as $quote) {
            $score = $this->yahooScore($query, strtoupper((string)$quote['symbol']));
            $scored[] = ['quote' => $quote, 'score' => $score];
            if ($score > $best) {
                $best = $score;
            }
        }
        $chosen = null;
        if ($exchange !== '') {
            foreach ($scored as $item) {
                if ($item['score'] >= 90 && $this->yahooSuffix(strtoupper((string)$item['quote']['symbol'])) === $exchange) {
                    $chosen = $item;
                    break;
                }
            }
        }
        $note = '';
        if ($chosen === null) {
            if ($exchange !== '' && $best >= 90) {
                $note = 'No ' . $exchange . ' listing matched. Using the closest Yahoo symbol.';
            }
            foreach ($scored as $item) {
                if ($best >= 90 && $item['score'] === $best) {
                    $chosen = $item;
                    break;
                }
            }
        }
        if ($chosen === null && str_contains($query, ' ')) {
            $chosen = $scored[0];
        }
        if ($chosen === null && !str_contains($query, ' ')) {
            foreach ($scored as $item) {
                if ($this->yahooPrefix($query, strtoupper((string)$item['quote']['symbol']))) {
                    $chosen = $item;
                    break;
                }
            }
        }
        if ($chosen === null) {
            $bits = [];
            foreach (array_slice($pool, 0, 5) as $quote) {
                $bits[] = strtoupper((string)$quote['symbol']) . ' (' . $this->yahooKind((string)($quote['quoteType'] ?? '')) . ')';
            }
            return 'Yahoo has no listing for ' . $query . '. Nearby: ' . implode(', ', $bits) . '.';
        }
        $symbol = strtoupper((string)$chosen['quote']['symbol']);
        $alternates = [];
        foreach ($scored as $item) {
            $other = strtoupper((string)$item['quote']['symbol']);
            if ($other === $symbol) {
                continue;
            }
            $keep = ($best >= 90 && $item['score'] === $best)
                || ($best < 90 && str_contains($query, ' '))
                || ($best < 90 && $this->yahooPrefix($query, $other));
            if ($keep && count($alternates) < 6) {
                $alternates[] = $other;
            }
        }
        return [
            'symbol' => $symbol,
            'alternates' => $alternates,
            'note' => $note,
            'rows' => $pool,
        ];
    }

    private function yahooPrefix(string $query, string $symbol): bool
    {
        return $symbol === '^' . $query
            || str_starts_with($symbol, $query . '-')
            || str_starts_with($symbol, $query . '=');
    }

    private function yahooScore(string $query, string $symbol): int
    {
        if ($symbol === $query || $symbol === '^' . $query) {
            return 100;
        }
        $base = $this->yahooBase($symbol);
        $want = $this->yahooBase($query);
        if ($base !== '' && $base === $want) {
            return 90;
        }
        return 0;
    }

    private function yahooBase(string $symbol): string
    {
        if (str_starts_with($symbol, '^')) {
            $symbol = substr($symbol, 1);
        }
        if (str_ends_with($symbol, '=X') || str_ends_with($symbol, '=F')) {
            return substr($symbol, 0, -2);
        }
        $suffix = $this->yahooSuffix($symbol);
        if ($suffix !== '') {
            return substr($symbol, 0, -strlen($suffix) - 1);
        }
        return $symbol;
    }

    private function yahooSuffix(string $symbol): string
    {
        $pos = strrpos($symbol, '.');
        if ($pos === false) {
            return '';
        }
        $suffix = substr($symbol, $pos + 1);
        return in_array($suffix, $this->yahooExchanges(), true) ? $suffix : '';
    }

    private function yahooTypeMatch(string $type, array $quote): bool
    {
        $kind = strtoupper((string)($quote['quoteType'] ?? ''));
        if ($type === 'bond') {
            return $this->yahooIsBond($quote);
        }
        if ($type === 'commodity') {
            return $this->yahooIsCommodity($quote);
        }
        $map = [
            'stock' => 'EQUITY',
            'etf' => 'ETF',
            'fund' => 'MUTUALFUND',
            'index' => 'INDEX',
            'fx' => 'CURRENCY',
            'crypto' => 'CRYPTOCURRENCY',
            'future' => 'FUTURE',
            'option' => 'OPTION',
        ];
        return $kind === ($map[$type] ?? '');
    }

    private function yahooIsBond(array $quote): bool
    {
        $kind = strtoupper((string)($quote['quoteType'] ?? ''));
        $disp = strtolower((string)($quote['typeDisp'] ?? ''));
        $name = strtolower(trim((string)($quote['shortname'] ?? '') . ' ' . (string)($quote['longname'] ?? '')));
        if ($kind === 'BOND' || str_contains($disp, 'bond')) {
            return $kind !== 'ETF' && $kind !== 'MUTUALFUND';
        }
        if ($kind === 'ETF' || $kind === 'MUTUALFUND' || $kind === 'OPTION' || $kind === 'FUTURE') {
            return false;
        }
        return (bool)preg_match('/\b(bond|treasury|yield|gilt|bund|obligation)\b/', $name);
    }

    private function yahooEncoded(string $symbol): bool
    {
        if ($symbol === '' || str_contains($symbol, ' ')) {
            return false;
        }
        if (str_starts_with($symbol, '^') || str_ends_with($symbol, '=X') || str_ends_with($symbol, '=F') || $this->yahooSuffix($symbol) !== '') {
            return true;
        }
        return (bool)preg_match('/^[A-Z0-9]{2,12}-(?:USD|EUR|GBP|JPY|SEK|CHF|CAD|AUD|USDT|USDC)$/', $symbol);
    }

    private function yahooAlias(string $query, string $type): string
    {
        if ($type === 'commodity') {
            $mapped = [
                'GOLD' => 'GC=F',
                'SILVER' => 'SI=F',
                'OIL' => 'CL=F',
                'CRUDE' => 'CL=F',
                'CRUDE OIL' => 'CL=F',
                'WTI' => 'CL=F',
                'BRENT' => 'BZ=F',
                'NATGAS' => 'NG=F',
                'NATURAL GAS' => 'NG=F',
                'COPPER' => 'HG=F',
                'PLATINUM' => 'PL=F',
                'PALLADIUM' => 'PA=F',
                'CORN' => 'ZC=F',
                'WHEAT' => 'ZW=F',
                'SOYBEAN' => 'ZS=F',
                'SOYBEANS' => 'ZS=F',
                'COFFEE' => 'KC=F',
                'SUGAR' => 'SB=F',
                'COTTON' => 'CT=F',
                'COCOA' => 'CC=F',
                'GASOLINE' => 'RB=F',
                'HEATING OIL' => 'HO=F',
                'CATTLE' => 'LE=F',
                'LIVE CATTLE' => 'LE=F',
                'HOGS' => 'HE=F',
                'LEAN HOGS' => 'HE=F',
            ][$query] ?? '';
            return $mapped !== '' ? $mapped : $query;
        }
        if ($type === 'crypto') {
            $mapped = [
                'BITCOIN' => 'BTC-USD',
                'ETHEREUM' => 'ETH-USD',
            ][$query] ?? '';
            if ($mapped !== '') {
                return $mapped;
            }
            if (preg_match('/^[A-Z0-9]{2,6}$/', $query)) {
                return $query . '-USD';
            }
        }
        if ($type === 'fx' && preg_match('/^[A-Z]{6}$/', $query)) {
            return $query . '=X';
        }
        return $query;
    }

    private function yahooIsCommodity(array $quote): bool
    {
        $kind = strtoupper((string)($quote['quoteType'] ?? ''));
        $symbol = strtoupper((string)($quote['symbol'] ?? ''));
        if ($kind !== 'FUTURE' || !str_ends_with($symbol, '=F')) {
            return false;
        }
        $name = strtolower(trim((string)($quote['shortname'] ?? '') . ' ' . (string)($quote['longname'] ?? '') . ' ' . $symbol));
        return (bool)preg_match('/\b(gold|silver|oil|crude|brent|gas|copper|platinum|palladium|corn|wheat|soy|coffee|sugar|cotton|cocoa|cattle|hog|gasoline|gc|si|cl|bz|ng|hg|pl|pa|zc|zw|zs|kc|sb|ct|cc|le|he|rb|ho)\b/', $name);
    }

    private function yahooSearchText(string $query, string $type, array $picked): string
    {
        $rows = is_array($picked['rows'] ?? null) ? $picked['rows'] : [];
        $head = $type !== '' ? ucfirst($type) . ' listings for ' . $query : 'Yahoo listings for ' . $query;
        $lines = [$head];
        foreach (array_slice($rows, 0, 8) as $quote) {
            if (!is_array($quote)) {
                continue;
            }
            $lines[] = strtoupper((string)$quote['symbol'])
                . '  ' . $this->yahooKind((string)($quote['quoteType'] ?? ''))
                . '  ' . $this->yahooText((string)($quote['exchDisp'] ?? ''))
                . '  ' . $this->yahooText((string)($quote['shortname'] ?? $quote['longname'] ?? ''));
        }
        return implode("\n", $lines);
    }

    private function yahooLoadChart(string $symbol, array $window): array|string
    {
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?' . http_build_query($window);
        $json = $this->yahooJson($url);
        if (is_string($json)) {
            return $json;
        }
        $kind = $this->yahooPayload($json);
        if ($kind === 'chart') {
            return $json;
        }
        $why = $this->yahooChartError((string)($json['chart']['error']['description'] ?? ''));
        return $symbol . ': ' . $why;
    }

    private function yahooChartError(string $description): string
    {
        $why = trim($description);
        if ($why === '' || stripos($why, 'delist') !== false) {
            return 'Could not fetch data.';
        }
        return $why;
    }

    private function yahooApiHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'query1.finance.yahoo.com' || $host === 'query2.finance.yahoo.com';
    }

    private function yahooBrowserHeaders(): array
    {
        return [
            'Accept: application/json,text/plain,*/*',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://finance.yahoo.com/',
            'Origin: https://finance.yahoo.com',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
            'Sec-Ch-Ua: "Chromium";v="131", "Google Chrome";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "macOS"',
        ];
    }

    private function yahooCurl($ch): void
    {
        $ciphers = 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';
        @curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, $ciphers);
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        }
    }

    private function yahooPace(): void
    {
        @set_time_limit(0);
        sleep(random_int(5, 30));
    }

    private function yahooJson(string $url): array|string
    {
        $this->yahooPace();
        $meta = [];
        $final = $url;
        $body = $this->request($url, 0, null, $final, $meta, ['Accept: application/json']);
        $json = $this->yahooDecode($body);
        if (is_array($json) && $this->yahooPayload($json) !== '') {
            return $json;
        }
        $denied = $this->yahooDenied($url, $meta, $body);
        if ($this->db->getSetting('headless_browser', '0') !== '1') {
            if ($denied) {
                return 'Yahoo answered HTTP 429 to this client. A browser still receives the JSON. The headless browser is off.';
            }
            return 'Yahoo did not return data.';
        }
        $opened = $this->yahooBrowser($url);
        $json = $this->yahooDecode($opened);
        if (is_array($json) && $this->yahooPayload($json) !== '') {
            return $json;
        }
        if ($denied || $opened === '') {
            return 'Yahoo answered HTTP 429 to this client. A browser still receives the JSON. The headless browser did not return it.';
        }
        return 'Yahoo did not return data.';
    }

    private function yahooDecode(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if ($body[0] !== '{' && $body[0] !== '[') {
            $body = trim(strip_tags($body));
        }
        $start = strpos($body, '{');
        if ($start === false) {
            $start = strpos($body, '[');
        }
        if ($start === false) {
            return null;
        }
        $json = json_decode(substr($body, $start), true);
        return is_array($json) ? $json : null;
    }

    private function yahooPayload(array $json): string
    {
        if (isset($json['quotes']) && is_array($json['quotes'])) {
            return 'search';
        }
        $result = $json['chart']['result'] ?? null;
        if (is_array($result) && $result !== []) {
            return 'chart';
        }
        $code = (string)($json['chart']['error']['code'] ?? '');
        return $code === 'Not Found' ? 'missing' : '';
    }

    private function yahooPresent(string $action, string $asked, string $symbol, array $picked, array $chart, array $window): string
    {
        $result = $chart['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            return $symbol . ': ' . $this->yahooChartError((string)($chart['chart']['error']['description'] ?? ''));
        }
        $lead = $this->yahooLead($asked, $symbol, $picked);
        if ($action === 'quote') {
            return $lead . $this->yahooQuoteText($result);
        }
        if ($action === 'history') {
            return $lead . $this->yahooHistoryText($result, (string)($window['interval'] ?? '1d'));
        }
        return $lead . $this->yahooEventText($result, $action);
    }

    private function yahooLead(string $asked, string $symbol, array $picked): string
    {
        $lines = [];
        $normalized = $this->yahooQuery($asked);
        if ($normalized !== $symbol && strtoupper($asked) !== $symbol) {
            $lines[] = 'Asked for ' . $asked . '. Using ' . $symbol . '.';
        }
        $note = trim((string)($picked['note'] ?? ''));
        if ($note !== '') {
            $lines[] = $note;
        }
        $alternates = is_array($picked['alternates'] ?? null) ? $picked['alternates'] : [];
        if ($alternates !== []) {
            $lines[] = count($alternates) === 1
                ? 'Also listed as: ' . $alternates[0] . '. Pass that symbol to use it.'
                : 'Also listed as: ' . implode(', ', $alternates) . '. Pass one of those symbols to use it.';
        }
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function yahooQuoteText(array $result): string
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $symbol = (string)($meta['symbol'] ?? '');
        $hint = $this->yahooHint($meta);
        $price = $meta['regularMarketPrice'] ?? null;
        if ($symbol === '' || !is_numeric($price)) {
            return 'Yahoo returned no price.';
        }
        $name = $this->yahooText((string)($meta['longName'] ?? $meta['shortName'] ?? ''));
        $lines = [$symbol . ($name !== '' ? ' — ' . $name : '')];
        $where = array_filter([
            $this->yahooKind((string)($meta['instrumentType'] ?? '')),
            $this->yahooText((string)($meta['fullExchangeName'] ?? '')),
            trim((string)($meta['currency'] ?? '')),
        ]);
        if ($where !== []) {
            $lines[] = implode(' · ', $where);
        }
        $rows = $this->yahooSeries($result)['rows'];
        $prev = null;
        if (count($rows) >= 2 && is_numeric($rows[count($rows) - 2]['close'])) {
            $prev = $rows[count($rows) - 2]['close'];
        }
        $priceText = 'Price: ' . $this->yahooNum($price, $hint);
        if (is_numeric($prev) && (float)$prev != 0.0) {
            $change = (float)$price - (float)$prev;
            $pct = ($change / (float)$prev) * 100;
            $priceText .= '  Previous close: ' . $this->yahooNum($prev, $hint);
            $priceText .= '  Change: ' . $this->yahooSigned($change, $hint) . ' (' . $this->yahooSigned($pct, 2) . '%)';
        }
        $lines[] = $priceText;
        $day = '';
        $low = $meta['regularMarketDayLow'] ?? null;
        $high = $meta['regularMarketDayHigh'] ?? null;
        if (is_numeric($low) && is_numeric($high) && (float)$low > 0 && (float)$high > 0) {
            $day = 'Day: ' . $this->yahooNum($low, $hint) . '–' . $this->yahooNum($high, $hint);
        }
        if (is_numeric($meta['regularMarketVolume'] ?? null) && (float)$meta['regularMarketVolume'] > 0) {
            $day .= ($day !== '' ? '  ' : '') . 'Volume: ' . (string)(int)round((float)$meta['regularMarketVolume']);
        }
        if ($day !== '') {
            $lines[] = $day;
        }
        $weekLow = $meta['fiftyTwoWeekLow'] ?? null;
        $weekHigh = $meta['fiftyTwoWeekHigh'] ?? null;
        if (is_numeric($weekLow) && is_numeric($weekHigh) && (float)$weekLow > 0 && (float)$weekHigh > 0) {
            $lines[] = '52-week: ' . $this->yahooNum($weekLow, $hint) . '–' . $this->yahooNum($weekHigh, $hint);
        }
        $stamp = $meta['regularMarketTime'] ?? null;
        if (is_numeric($stamp)) {
            $lines[] = 'As of: ' . $this->yahooWhen((int)$stamp, (string)($meta['exchangeTimezoneName'] ?? 'UTC'), true);
        }
        return implode("\n", $lines);
    }

    private function yahooHistoryText(array $result, string $interval): string
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $symbol = (string)($meta['symbol'] ?? '');
        $series = $this->yahooSeries($result);
        $rows = $series['rows'];
        if ($rows === []) {
            return $symbol . ' has no bars for that range.';
        }
        $total = count($rows);
        $shown = $rows;
        $note = '';
        if ($total > 160) {
            $shown = array_slice($rows, -160);
            $note = ' Showing the latest 160. Pass start and end for an earlier span.';
        }
        $head = $symbol . ' ' . $interval . ', ' . $total . ' bars from ' . $rows[0]['date'] . ' to ' . $rows[$total - 1]['date'] . '.' . $note;
        $lines = [$head, 'date,open,high,low,close,adjclose,volume'];
        $hint = $series['hint'];
        $intraday = !in_array($interval, ['1d', '5d', '1wk', '1mo', '3mo'], true);
        foreach ($shown as $row) {
            $lines[] = ($intraday ? $row['time'] : $row['date'])
                . ',' . $this->yahooCell($row['open'], $hint)
                . ',' . $this->yahooCell($row['high'], $hint)
                . ',' . $this->yahooCell($row['low'], $hint)
                . ',' . $this->yahooCell($row['close'], $hint)
                . ',' . $this->yahooCell($row['adj'], $hint)
                . ',' . (is_numeric($row['volume']) ? (string)(int)round((float)$row['volume']) : '');
        }
        return implode("\n", $lines);
    }

    private function yahooEventText(array $result, string $action): string
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $symbol = (string)($meta['symbol'] ?? '');
        $currency = trim((string)($meta['currency'] ?? ''));
        $tz = (string)($meta['exchangeTimezoneName'] ?? 'UTC');
        $hint = $this->yahooHint($meta);
        $events = is_array($result['events'] ?? null) ? $result['events'] : [];
        $parts = [];
        if ($action === 'dividends' || $action === 'events') {
            $parts[] = $this->yahooDividendLines($symbol, $currency, $tz, $hint, is_array($events['dividends'] ?? null) ? $events['dividends'] : []);
        }
        if ($action === 'splits' || $action === 'events') {
            $parts[] = $this->yahooSplitLines($symbol, $tz, is_array($events['splits'] ?? null) ? $events['splits'] : []);
        }
        return implode("\n\n", $parts);
    }

    private function yahooDividendLines(string $symbol, string $currency, string $tz, int $hint, array $dividends): string
    {
        $rows = [];
        foreach ($dividends as $item) {
            if (!is_array($item) || !is_numeric($item['date'] ?? null) || !is_numeric($item['amount'] ?? null)) {
                continue;
            }
            $rows[] = $item;
        }
        usort($rows, static fn(array $a, array $b): int => ((int)$a['date']) <=> ((int)$b['date']));
        $label = $currency !== '' ? $symbol . ' dividends, ' . $currency : $symbol . ' dividends';
        if ($rows === []) {
            return $label . "\nNone in that range.";
        }
        $cut = '';
        if (count($rows) > 80) {
            $rows = array_slice($rows, -80);
            $cut = ' Showing the latest 80.';
        }
        $lines = [$label . $cut];
        foreach ($rows as $item) {
            $lines[] = $this->yahooWhen((int)$item['date'], $tz, false) . ',' . $this->yahooNum($item['amount'], $hint);
        }
        return implode("\n", $lines);
    }

    private function yahooSplitLines(string $symbol, string $tz, array $splits): string
    {
        $rows = [];
        foreach ($splits as $item) {
            if (!is_array($item) || !is_numeric($item['date'] ?? null)) {
                continue;
            }
            $rows[] = $item;
        }
        usort($rows, static fn(array $a, array $b): int => ((int)$a['date']) <=> ((int)$b['date']));
        if ($rows === []) {
            return $symbol . " splits\nNone in that range.";
        }
        $lines = [$symbol . ' splits'];
        foreach ($rows as $item) {
            $ratio = trim((string)($item['splitRatio'] ?? ''));
            if ($ratio === '' && is_numeric($item['numerator'] ?? null) && is_numeric($item['denominator'] ?? null)) {
                $ratio = $this->yahooPlain($item['numerator']) . ':' . $this->yahooPlain($item['denominator']);
            }
            $lines[] = $this->yahooWhen((int)$item['date'], $tz, false) . ($ratio !== '' ? ',' . $ratio : '');
        }
        return implode("\n", $lines);
    }

    private function yahooSeries(array $result): array
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $tz = $this->yahooZone((string)($meta['exchangeTimezoneName'] ?? 'UTC'));
        $indicators = is_array($result['indicators'] ?? null) ? $result['indicators'] : [];
        $quote = is_array($indicators['quote'][0] ?? null) ? $indicators['quote'][0] : [];
        $adjClose = is_array($indicators['adjclose'][0] ?? null) ? $indicators['adjclose'][0] : [];
        $adj = is_array($adjClose['adjclose'] ?? null) ? $adjClose['adjclose'] : [];
        $rows = [];
        foreach (($result['timestamp'] ?? []) as $i => $stamp) {
            if (!is_numeric($stamp)) {
                continue;
            }
            $close = is_array($quote) ? ($quote['close'][$i] ?? null) : null;
            if (!is_numeric($close)) {
                continue;
            }
            $when = (new DateTimeImmutable('@' . (int)$stamp))->setTimezone($tz);
            $rows[] = [
                'date' => $when->format('Y-m-d'),
                'time' => $when->format('Y-m-d H:i'),
                'open' => is_array($quote) ? ($quote['open'][$i] ?? null) : null,
                'high' => is_array($quote) ? ($quote['high'][$i] ?? null) : null,
                'low' => is_array($quote) ? ($quote['low'][$i] ?? null) : null,
                'close' => $close,
                'adj' => is_array($adj) ? ($adj[$i] ?? null) : null,
                'volume' => is_array($quote) ? ($quote['volume'][$i] ?? null) : null,
            ];
        }
        return ['hint' => $this->yahooHint($meta), 'rows' => $rows];
    }

    private function yahooHint(array $meta): int
    {
        $hint = (int)($meta['priceHint'] ?? 2);
        if ($hint < 0 || $hint > 6) {
            return 2;
        }
        return $hint;
    }

    private function yahooNum(mixed $value, int $hint): string
    {
        return number_format((float)$value, $hint, '.', '');
    }

    private function yahooSigned(float $value, int $hint): string
    {
        $text = $this->yahooNum($value, $hint);
        return $value > 0 ? '+' . $text : $text;
    }

    private function yahooCell(mixed $value, int $hint): string
    {
        return is_numeric($value) ? $this->yahooNum($value, $hint) : '';
    }

    private function yahooText(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function yahooPlain(mixed $value): string
    {
        $text = rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    }

    private function yahooWhen(int $stamp, string $zone, bool $clock): string
    {
        $when = (new DateTimeImmutable('@' . $stamp))->setTimezone($this->yahooZone($zone));
        return $when->format($clock ? 'Y-m-d H:i T' : 'Y-m-d');
    }

    private function yahooZone(string $zone): DateTimeZone
    {
        try {
            return new DateTimeZone($zone !== '' ? $zone : 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    private function yahooKind(string $type): string
    {
        return match (strtoupper($type)) {
            'EQUITY' => 'Equity',
            'ETF' => 'ETF',
            'MUTUALFUND' => 'Fund',
            'INDEX' => 'Index',
            'CURRENCY' => 'Currency',
            'CRYPTOCURRENCY' => 'Crypto',
            'FUTURE' => 'Future',
            'OPTION' => 'Option',
            'BOND' => 'Bond',
            '' => 'Listing',
            default => $type,
        };
    }

    private function yahooExchanges(): array
    {
        return [
            'ST', 'OL', 'HE', 'CO', 'IC', 'IS', 'IR', 'L', 'IL', 'DE', 'F', 'MU', 'DU', 'HM', 'HA', 'BE', 'SG',
            'PA', 'AS', 'BR', 'LS', 'MC', 'MI', 'AT', 'VI', 'SW', 'WA', 'PR', 'BD',
            'TO', 'V', 'CN', 'NE', 'AX', 'NZ', 'HK', 'T', 'TW', 'TWO', 'KS', 'KQ', 'SI', 'KL', 'BK', 'JK',
            'SS', 'SZ', 'BO', 'NS', 'TA', 'JO', 'MX', 'SA', 'BA', 'SN', 'QA', 'SR', 'ME',
        ];
    }
}
