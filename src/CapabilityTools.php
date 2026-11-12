<?php

class CapabilityTools
{
    public array $blockedInstalls = [];

    public function __construct(private Database $db)
    {
    }

    public function handlers(string $projectFolder, int $projectId = 0, bool $allowInstall = false): array
    {
        $handlers = [];
        $handlers['web_search'] = fn(array $args): string => $this->webSearch((string)($args['query'] ?? ''));
        $handlers['fetch_url'] = fn(array $args): string => $this->fetchUrl((string)($args['url'] ?? ''));
        $handlers['browse_url'] = fn(array $args): string => $this->browseUrl((string)($args['url'] ?? ''));
        $handlers['run_command'] = fn(array $args): string => $this->runCommand((string)($args['command'] ?? ''), $projectFolder, $allowInstall);
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
        $lines[] = '- web_search: args query. One idea, as in a normal search box: two to four plain words. Every word must match, so extra words and synonyms make it miss. Quotes are only for one exact title of several words, plus at most one plain word. Do not quote a single word, do not stack quoted phrases, and do not use OR, AND, NOT, or parentheses to join alternatives. Search the other wording on its own. site:example.com is the only operator. Returns a title, link, and snippet. Then read a result with fetch_url. Rewrite only when the message starts with "This query was not searched" or gives a shorter query, and call once more. If the search provider rejected the request, end the run. Do not call another tool. Do not fetch pages, rewrite the query, or log research. Reply with that failure so the main agent hears it. If the results page could not be read, stop. Do not call web_search again this turn.';
        $lines[] = '- fetch_url: args url. Reads a public page and lists links on it. Open those links to keep crawling.';
        $lines[] = '- browse_url: args url. Opens a public page in the headless browser so scripts run, then returns the text and links. Use it when fetch_url shows an empty or unfinished page.';
        $lines[] = $this->db->fullSystemControl()
            ? '- run_command: args command. May use sudo. Starts in the project folder when there is one. A path that starts with / is anywhere on this computer. Do not install software. Only the main agent can install a dependency, and the user is asked first.'
            : '- run_command: unavailable while full system control is off. Use the available tools or registered skills instead.';
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

    private function webSearch(string $query): string
    {
        $query = str_replace(['“', '”', '„'], '"', trim($query));
        if ($query === '') {
            return 'Missing query.';
        }
        $problem = $this->searchQueryProblem($query);
        if ($problem !== '') {
            return $problem;
        }
        $refusals = [];
        $sawEmpty = false;
        $terms = $this->queryTerms($query);
        foreach (['Bing', 'Google', 'DuckDuckGo'] as $name) {
            if ($name === 'Bing') {
                $found = $this->bingResults($query);
            } elseif ($name === 'Google') {
                $found = $this->googleResults($query);
            } else {
                $found = $this->duckSearch($query);
            }
            $shown = $this->matchingResults($terms, $found['results']);
            $blocks = $this->searchBlocks($shown);
            if ($blocks) {
                $blocks[] = 'Results from ' . $name . '.';
                $blocks[] = 'Read a page with fetch_url. The result lists links you can open next.';
                return clip_text(implode("\n\n", $blocks), 4000);
            }
            if ($found['status'] === 'results' || $found['results']) {
                $found['status'] = 'empty';
            }
            if ($found['status'] === 'empty') {
                $sawEmpty = true;
                continue;
            }
            $refusals[] = $this->engineMiss($name, $found);
        }
        if (!$sawEmpty) {
            $instant = $this->instantAnswer($query);
            if ($instant !== '') {
                return $instant;
            }
            return $this->providersRejected($refusals);
        }
        if (preg_match('/^site:\s*([a-z0-9.-]+\.[a-z]{2,})\/?$/i', $query, $match) && $this->hostIsPublic($match[1])) {
            $home = 'https://' . strtolower($match[1]) . '/';
            return "No search hits for that query. Opened {$home} instead.\n\n" . $this->fetchUrl($home);
        }
        return $this->emptySearchMessage($query);
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

    private function duckSearch(string $query): array
    {
        $post = 'q=' . rawurlencode($query) . '&kl=wt-wt';
        $first = $this->searchFetch('https://html.duckduckgo.com/html/', $post);
        $results = $this->parseSearchResults($first['body']);
        if ($results) {
            return $this->searchOutcome($results, 'results', $first);
        }
        $status = $this->duckPageStatus($first);
        $blocked = $this->isSearchBotWall((string)$first['body']) || ((int)$first['code'] !== 200 && (int)$first['code'] !== 0 && trim((string)$first['body']) !== '');
        if ($blocked) {
            return $this->searchOutcome([], $status, $first);
        }
        $lite = $this->searchFetch('https://lite.duckduckgo.com/lite/', $post);
        $results = $this->parseSearchResults($lite['body']);
        if ($results) {
            return $this->searchOutcome($results, 'results', $lite);
        }
        return $this->searchOutcome([], $this->duckPageStatus($lite), $lite, true);
    }

    private function bingResults(string $query): array
    {
        $market = $this->bingMarket();
        $headers = ['Accept-Language: sv-SE,sv;q=0.9,en;q=0.8', 'Referer: https://www.bing.com/'];
        $cookie = $this->bingCookie();
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        $page = 'https://www.bing.com/search?q=' . rawurlencode($query);
        if ($market !== '') {
            $page .= '&mkt=' . rawurlencode($market);
        }
        $html = $this->searchFetch($page, null, $headers);
        $parsed = $this->parseBingHtml((string)$html['body']);
        if ($parsed && (int)$html['code'] === 200) {
            return $this->searchOutcome($parsed, 'results', $html);
        }
        $url = 'https://www.bing.com/search?format=rss&count=8&q=' . rawurlencode($query);
        if ($market !== '') {
            $url .= '&mkt=' . rawurlencode($market);
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

    private function bingMarket(): string
    {
        return date_default_timezone_get() === 'Europe/Stockholm' ? 'sv-SE' : '';
    }

    private function bingCookie(): string
    {
        static $cookie = null;
        if ($cookie !== null) {
            return $cookie;
        }
        $meta = ['code' => 0, 'error' => ''];
        $final = '';
        $this->request('https://www.bing.com/', 0, null, $final, $meta, ['Accept-Language: sv-SE,sv;q=0.9,en;q=0.8']);
        $cookie = $this->setCookieHeader((string)($meta['header'] ?? ''));
        return $cookie;
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

    private function googleResults(string $query): array
    {
        $fetched = $this->searchFetch('https://www.google.com/search?gbv=1&hl=en&num=8&q=' . rawurlencode($query));
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

    private function instantAnswer(string $query): string
    {
        $body = $this->request('https://api.duckduckgo.com/?format=json&no_html=1&skip_disambig=1&q=' . rawurlencode($query));
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
        $body = $this->request($url, 0, null, $final);
        if (trim($body) === '') {
            return 'The page returned nothing.';
        }
        return $this->pageText($body, $final !== '' ? $final : $url);
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
        $headers = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
        $language = 'Accept-Language: en,sv;q=0.8';
        foreach ($extraHeaders as $header) {
            if (stripos($header, 'Accept-Language:') === 0) {
                $language = $header;
                continue;
            }
            $headers[] = $header;
        }
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
        if (!$this->db->fullSystemControl()) {
            return 'Full system control is off.';
        }
        if (preg_match('/(?:^|[\/\s\'"])[A-Za-z0-9._-]+\.(?:db|sqlite|sqlite3)(?:$|[\s\'"])/i', $command)) {
            return 'Database commands are blocked in run_command. Use sqlite_helper so the database filename is explicit and a recoverable backup is created before a write.';
        }
        if ($this->isInstallCommand($command) && !$allowInstall) {
            $this->blockedInstalls[] = $command;
            return 'Install stopped. Only the main agent can install dependencies, and only after the user agrees. Do not try another install. Tell the main agent what was needed and why.';
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
}
