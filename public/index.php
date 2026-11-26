<?php

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_contains($path, '..')) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        return true;
    }
    if ($path === '/manifest.webmanifest') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile(__DIR__ . '/manifest.webmanifest');
        return true;
    }
    if ($path === '/favicon.ico') {
        header('Content-Type: image/x-icon');
        header('Cache-Control: no-cache');
        readfile(__DIR__ . '/favicon.ico');
        return true;
    }
    $file = __DIR__ . $path;
    if ($path !== '/' && $path !== '/index.php' && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
            header('Cache-Control: no-cache');
            readfile($file);
            return true;
        }
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';
app_boot_web();

$router = new Router();
(new Api())->register($router);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (str_starts_with($uri, '/preview/')) {
    (new SitePreview())->serve($uri);
    exit;
}
if (str_starts_with($uri, '/api/')) {
    $router->dispatch($method, $uri);
    exit;
}
if ($uri === '/legal/terms.php' || $uri === '/legal/aup.php') {
    $legal = __DIR__ . $uri;
    if (is_file($legal)) {
        require $legal;
        exit;
    }
}
if ($uri !== '/' && $uri !== '/index.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Danaco: 1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#dcdfe3">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Agent Danaco">
<title>Agent Danaco</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/icon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/tailwind.js"></script>
<script src="/assets/vue.global.prod.js"></script>
</head>
<body>
<svg class="sprite" aria-hidden="true">
  <symbol id="i-chat" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M5 6.5h14v9H8l-3 3z"/></symbol>
  <symbol id="i-tasks" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M4 5h4v14H4zM10 5h4v9h-4zM16 5h4v6h-4z"/></symbol>
  <symbol id="i-research" viewBox="0 0 24 24"><circle cx="6" cy="7" r="2.1" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="6.5" r="2.1" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="16.5" cy="17" r="2.1" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M8 7.4h6.6M7.4 8.8l7.4 6.2"/></symbol>
  <symbol id="i-workspace" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M3 7h7l2 2h9v9H3z"/></symbol>
  <symbol id="i-folder" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" d="M3 8.5h6.2l1.6-2h4.4l1.6 2H21v10H3z"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" d="M7 3.5h7.2L19 8.2V20.5H7zM14.2 3.8V8.2H18.5M10 12.5h6M10 16h4"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M14.8 14.8 20 20"/></symbol>
  <symbol id="i-agents" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M8 12a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm8 1a2.5 2.5 0 1 0-2.5-2.5A2.5 2.5 0 0 0 16 13zM3 19c.4-2.4 2.4-4 5-4s4.6 1.6 5 4M14 19c.3-1.6 1.5-2.8 3.2-3 1.6.2 2.8 1.4 3.3 3"/></symbol>
  <symbol id="i-skills" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M12 3l2.2 5.5L20 11l-5.8 2.5L12 19l-2.2-5.5L4 11l5.8-2.5z"/></symbol>
  <symbol id="i-setup" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" d="M14.5 4.5 19.5 9.5 8 21H3v-5zM12.5 6.5l5 5"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5h15M9.2 7.4V5.2h5.6v2.2M7.2 7.5l.8 12h8l.8-12M10 11.2v5M14 11.2v5"/></symbol>
  <symbol id="i-archive" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 8h16v11H4zM3 4.5h18V8H3zM10 12h4"/></symbol>
  <symbol id="i-restore" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 10h16v9.5H4zM12 14.5V3.5M8.2 7.2 12 3.5l3.8 3.7"/></symbol>
  <symbol id="i-grip" viewBox="0 0 24 24"><circle cx="9" cy="7" r="1.35" fill="currentColor"/><circle cx="15" cy="7" r="1.35" fill="currentColor"/><circle cx="9" cy="12" r="1.35" fill="currentColor"/><circle cx="15" cy="12" r="1.35" fill="currentColor"/><circle cx="9" cy="17" r="1.35" fill="currentColor"/><circle cx="15" cy="17" r="1.35" fill="currentColor"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12 4v10M8 10l4 4 4-4M5 19h14"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-chevron" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path fill="#000" stroke="#000" stroke-linejoin="round" stroke-width="1" d="M12 3.2 2.2 20.8h19.6z"/><path fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" d="M12 9v5.2"/><circle cx="12" cy="16.9" r="0.95" fill="#fff" stroke="none"/></symbol>
  <symbol id="i-play" viewBox="0 0 24 24"><path fill="currentColor" d="M8 5.2v13.6l12-6.8z"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" d="M4.5 5.5h5.5v13H4.5z"/><circle cx="8" cy="12" r="0.8" fill="currentColor" stroke="none"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12.5 12H20M16.8 8.8 20 12l-3.2 3.2"/></symbol>
  <symbol id="i-av-orchestrator" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" d="M7 8.2 9 5.2 12 7.2 15 5.2 17 8.2"/><circle cx="12" cy="11.2" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" d="M12 13.8 10.4 19.2h3.2zM8 19.6c1.2-1.6 2.4-2.2 4-2.2s2.8.6 4 2.2"/></symbol>
  <symbol id="i-av-scheduler" viewBox="0 0 24 24"><circle cx="8" cy="8" r="2.4" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M4.6 17.4c.5-2.1 1.8-3.2 3.4-3.2s2.9 1.1 3.4 3.2"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M14.2 8.2h5.4M14.2 11.6h5.4M14.2 15h3.2"/></symbol>
  <symbol id="i-av-researcher" viewBox="0 0 24 24"><circle cx="9" cy="9" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="15.2" cy="15" r="2.8" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M17.3 17.1 20 19.8"/></symbol>
  <symbol id="i-av-writer" viewBox="0 0 24 24"><circle cx="12" cy="8.2" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M7.2 18.8 16.6 14.2M15.6 14.6l2.2 1.6"/></symbol>
  <symbol id="i-av-coder" viewBox="0 0 24 24"><circle cx="12" cy="8" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M9 15.2 6 18l3 2.8M15 15.2 18 18l-3 2.8"/></symbol>
  <symbol id="i-av-analyst" viewBox="0 0 24 24"><circle cx="12" cy="8" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M7 19.5v-4M12 19.5V13M17 19.5v-3.2"/></symbol>
  <symbol id="i-av-marketer" viewBox="0 0 24 24"><circle cx="8.2" cy="9" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" d="M12 13.6h2.2L19.5 11v5.4l-5.3-2.2H12z"/></symbol>
  <symbol id="i-av-consultant" viewBox="0 0 24 24"><circle cx="8" cy="8" r="2.4" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M4.6 17.4c.5-2.1 1.8-3.2 3.4-3.2s2.9 1.1 3.4 3.2"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M14.4 10.2h5.4v5.2h-5.4z"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M16.2 10.2V8.8h2v1.4"/></symbol>
</svg>
<div id="app">
  <div v-if="booting" class="boot muted">Loading…</div>

  <div v-else-if="!authed" class="login-screen">
    <form class="login-card" @submit.prevent="login">
      <div class="login-brand">
        <img class="login-icon" src="/assets/logo.png" alt="">
        <h1>Agent Danaco</h1>
      </div>
      <p v-if="!passwordSet" class="form-error">No password is set. Run install.sh first.</p>
      <label class="field">Password
        <input v-model="password" type="password" name="password" autocomplete="current-password" required>
      </label>
      <p class="form-error">{{ loginError }}</p>
      <button class="btn btn-gold" type="submit" style="width:100%">Sign in</button>
    </form>
  </div>

  <div v-else class="shell flex h-dvh" :class="{'sidebar-collapsed': sidebarCollapsed}">
    <aside class="sidebar hidden md:flex flex-col">
      <div class="brand">
        <img class="brand-icon" src="/assets/logo.png" alt="">
        <strong class="brand-name">Agent Danaco</strong>
      </div>
      <button v-for="item in nav" :key="item.id" type="button" class="nav-btn" :class="{active: tab===item.id}" :aria-current="tab===item.id ? 'page' : null" @click="setTab(item.id)">
        <svg class="ico"><use :href="'#' + item.icon"></use></svg>
        <span class="nav-label">{{ item.label }}</span>
      </button>
      <div class="project-picker">
        <div class="project-head">
          <h2>Projects</h2>
          <button class="new-project" type="button" @click="openProjectModal">+ add new</button>
        </div>
        <div class="project-list">
          <div v-for="project in projects" :key="project.id" class="project-row" :class="{active: projectId===project.id}">
            <button class="project-name" type="button" @click="chooseProject(project.id)">{{ project.name }}</button>
            <button class="project-edit" type="button" :aria-label="'Edit ' + project.name" @click.stop="openProjectEdit(project)">
              <svg class="ico" aria-hidden="true"><use href="#i-edit"></use></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="sidebar-foot">
        <button class="nav-btn" type="button" @click="logout"><svg class="ico" aria-hidden="true"><use href="#i-logout"></use></svg><span class="nav-label">Log out</span></button>
        <button class="icon-btn" type="button" title="Collapse sidebar" @click="toggleSidebar">⟨</button>
      </div>
    </aside>

    <div class="workspace">
      <header class="topbar flex md:hidden">
        <button class="icon-btn" type="button" id="open-menu" aria-label="Open menu" :aria-expanded="mobileNav ? 'true' : 'false'" @click="mobileNav = true">☰</button>
        <strong>{{ currentLabel }}</strong>
        <span class="muted">{{ currentProject ? currentProject.name : '' }}</span>
      </header>
      <div class="stage">
        <main class="main-pane">
          <section v-if="tab==='chat' && !currentProject" class="view chat-view">
            <p class="chat-empty">Create a project to start the chat</p>
          </section>
          <section v-else-if="tab==='chat'" class="view chat-view">
            <div class="chat-column">
              <div class="view-head" style="padding:16px 18px 0">
                <div class="chat-heading">
                  <h2>Chat<span v-if="currentProject" class="project-tag">{{ currentProject.name }}</span></h2>
                </div>
                <div class="chat-side">
                  <label class="chat-find">
                    <input v-model="chatQuery" :class="{ 'has-meta': chatQuery.length }" type="search" placeholder="Search in this conversation" aria-label="Search in this conversation" @input="queueChatSearch(true)">
                    <span v-if="chatQuery" class="chat-find-meta">
                      <button type="button" aria-label="Clear search" @click="clearChatSearch">x</button>
                      <template v-if="chatQuery.trim()">
                        <span>{{ chatHits }} found</span>
                        <button type="button" aria-label="Previous match" :disabled="!chatHits" @click="stepChatHit(-1)">↑</button>
                        <button type="button" class="chat-find-next" aria-label="Next match" :disabled="!chatHits" @click="stepChatHit(1)">↓</button>
                      </template>
                    </span>
                  </label>
                <div class="context-meter" :class="{full: contextFull()}" tabindex="0" :aria-label="'Context limit, ' + contextTip()">
                  <svg viewBox="0 0 36 36" aria-hidden="true">
                    <circle class="context-track" cx="18" cy="18" r="14.75"></circle>
                    <circle class="context-fill" cx="18" cy="18" r="14.75" :stroke-dasharray="contextDash()"></circle>
                  </svg>
                  <span class="context-tip">
                    <strong>Context limit</strong>
                    <span>{{ contextTip() }}</span>
                    <a href="#" @click.prevent="compressContext">{{ compressing || compressArmed ? 'Compressing context' : 'Run context compression' }}</a>
                    <a href="#" @click.prevent="clearContext">Clear chat</a>
                    <a :href="'/api/context/download?project_id=' + (projectId || 0)">Download context</a>
                    <span v-if="compressError" class="form-error">{{ compressError }}</span>
                    <span v-if="compressNote" class="hint">{{ compressNote }}</span>
                  </span>
                </div>
                </div>
              </div>
              <div ref="transcript" class="transcript" :style="{ fontSize: chatFontPx + 'px' }" @scroll="onTranscriptScroll" @click="onTranscriptClick">
                <template v-for="msg in messages" :key="msg.id">
                <p v-if="msg.role==='notice'" class="context-note">{{ msg.content }}</p>
                <p v-else-if="scheduledText(msg)" class="context-note scheduled-note">{{ scheduledText(msg) }}</p>
                <article v-else class="bubble" :class="msg.role">
                  <div v-if="msg.role==='tool'" class="tool-line">{{ agentLabel(msg.agent_name, showCpu) }} · {{ msg.content }}</div>
                  <template v-else>
                    <div v-if="msg.role!=='user'" class="who">
                      <span>{{ agentLabel(msg.agent_name || msg.role, showCpu) }}</span>
                      <button v-if="msg.failed" class="retry-link" type="button" :disabled="sending || chatLocked" @click="retryMessage(msg)">Retry</button>
                    </div>
                    <activity-trace v-if="msg.detail" :thinking="msg.detail.thinking || []" :tools="msg.detail.tools || []" :open="traceOpen" :cpu="showCpu" :prefix="'m' + msg.id + '-'" @toggle="toggleTrace"></activity-trace>
                    <div v-if="shownContent(msg)" class="md" v-html="md(shownContent(msg))"></div>
                  </template>
                </article>
                </template>
                <div v-if="compressSpinner" class="thinking-row">
                  <span class="spinner" aria-hidden="true"></span>
                  <span>Compressing context</span>
                </div>
                <article v-if="showLiveBubble" class="bubble assistant">
                  <div class="who">{{ agentLabel(liveAgent, showCpu) }}</div>
                  <activity-trace :thinking="liveDetail.thinking" :tools="liveDetail.tools" :open="traceOpen" :cpu="showCpu" prefix="live-" @toggle="toggleTrace"></activity-trace>
                  <div v-if="live" class="md" v-html="md(live)"></div>
                  <div v-if="streamError && !live && !liveDetail.thinking.length && !liveDetail.tools.length" class="form-error">{{ errorText(streamError) }}</div>
                  <div v-if="!workingSubagents.length && (thinkingSpinner || (sending && (answerStarted || toolPending)))" class="thinking-row">
                    <span class="spinner" aria-hidden="true"></span>
                    <span>{{ thinkingLabel }}</span>
                  </div>
                </article>
                <div v-if="!workingSubagents.length && sending && replyPinned" class="thinking-row">
                  <span class="spinner" aria-hidden="true"></span>
                  <span>{{ thinkingLabel }}</span>
                </div>
              </div>
              <div v-if="workingSubagents.length" class="banner work-banner">
                <div v-for="agent in workingSubagents" :key="agent.name">{{ workLine(agent) }}</div>
              </div>
              <div v-if="actionPrompt" class="banner install-banner">
                <span>{{ actionPrompt.summary || ('Allow this ' + (actionPrompt.kind || 'action') + '?') }}</span>
                <span>
                  <button class="btn btn-gold" type="button" @click="answerAction(true)">Allow</button>
                  <button class="btn" type="button" @click="answerAction(false)">Don't allow</button>
                </span>
              </div>
              <p v-if="streamError" class="form-error" style="padding:0 18px">{{ errorText(streamError) }} <button v-if="!sending" class="retry-link" type="button" :disabled="chatLocked" @click="retryLast">Retry</button></p>
              <div v-if="showModelLoader" class="model-gate">
                <strong>Loading {{ modelLoad.model || 'model' }}</strong>
                <div class="model-track" role="progressbar" :aria-valuenow="modelLoad.percent" aria-valuemin="0" aria-valuemax="100">
                  <span :style="{width: modelLoad.percent + '%'}"></span>
                </div>
                <p class="hint">{{ modelLoad.percent }}% · send stays closed until the model is running</p>
                <p v-if="modelLoad.error" class="form-error">{{ modelLoad.error }}</p>
              </div>
              <form class="composer" @submit.prevent="send">
                <div class="composer-box">
                  <div v-if="attachments.length" class="composer-files">
                    <span v-for="file in attachments" :key="file.path" class="attach-chip">
                      {{ file.name }}
                      <button type="button" aria-label="Remove attachment" @click="removeAttachment(file)">×</button>
                    </span>
                  </div>
                  <div class="composer-line">
                    <button class="attach-btn" type="button" aria-label="Attach a file" title="Attach a file" :disabled="chatLocked" @click="$refs.attachInput.click()">
                      <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M21.4 11.1 12.2 20.3a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
                    </button>
                    <textarea ref="composer" v-model="draft" rows="1" :placeholder="sending && !workingSubagents.length ? 'Send message to steer the agent' : 'Send message'" @input="fitComposer" @keydown.enter.exact.prevent="send"></textarea>
                  </div>
                  <input ref="attachInput" class="file-input" type="file" multiple @change="onAttach">
                </div>
                <button v-if="sending && !workingSubagents.length" class="btn btn-gold" type="button" @click="stopRun">Stop</button>
                <button v-else class="btn btn-gold" type="submit" :disabled="chatLocked">{{ chatLocked ? 'Waiting' : 'Send' }}</button>
              </form>
            </div>
          </section>

          <section v-else-if="tab==='tasks'" class="view tasks-view">
            <div class="task-stage">
              <div class="task-main">
            <div class="view-head tasks-head">
              <h2>Tasks</h2>
              <div class="task-status" role="radiogroup" aria-label="Status">
                <span class="task-status-thumb" :style="{ transform: 'translateX(' + taskStatusIndex() * 100 + '%)' }"></span>
                <button type="button" role="radio" :aria-checked="taskFilter.state === '' ? 'true' : 'false'" :class="{ 'is-on': !taskFilter.state }" @click="taskFilter.state = ''">All</button>
                <button type="button" role="radio" :aria-checked="taskFilter.state === 'pending' ? 'true' : 'false'" :class="{ 'is-on': taskFilter.state === 'pending' }" @click="taskFilter.state = 'pending'">Pending</button>
                <button type="button" role="radio" :aria-checked="taskFilter.state === 'active' ? 'true' : 'false'" :class="{ 'is-on': taskFilter.state === 'active' }" @click="taskFilter.state = 'active'">Active</button>
                <button type="button" role="radio" :aria-checked="taskFilter.state === 'scheduled' ? 'true' : 'false'" :class="{ 'is-on': taskFilter.state === 'scheduled' }" @click="taskFilter.state = 'scheduled'">Scheduled</button>
                <button type="button" role="radio" :aria-checked="taskFilter.state === 'finished' ? 'true' : 'false'" :class="{ 'is-on': taskFilter.state === 'finished' }" @click="taskFilter.state = 'finished'">Finished</button>
              </div>
              <button class="btn btn-gold" type="button" @click="startAddTask">Add task</button>
            </div>
            <article v-if="addingTask" class="task-card">
              <label class="field task-title-field">
                <input ref="taskTitle" v-model="taskEdit.title" placeholder="Title" aria-label="Title" form="task-add-form">
              </label>
              <ul v-if="newSteps.length" class="subtasks" data-step-list="new">
                <template v-for="(sub, index) in newSteps" :key="sub.key">
                <li v-if="dragStepKey === stepKey(sub) && dragStepCard" class="step-placeholder" :style="{ height: dragStepCard.height + 'px' }" :data-step-key="stepKey(sub)"></li>
                <li class="subtask" :class="{ 'is-dragging': dragStepKey === stepKey(sub) }" :data-step-key="stepKey(sub)" :style="stepDragStyle(sub)">
                  <div class="subtask-row">
                    <button class="step-drag" type="button" aria-label="Drag to reorder" @pointerdown="onStepPointerDown('new', $event)">
                      <svg class="ico" aria-hidden="true"><use href="#i-grip"></use></svg>
                    </button>
                    <span class="subtask-title">{{ sub.title }}</span>
                    <span class="task-meta">{{ agentLabel(sub.assigned_agent) }}</span>
                    <button class="task-edit" type="button" aria-label="Remove step" @click="removeNewStep(index)">
                      <svg class="ico" aria-hidden="true"><use href="#i-trash"></use></svg>
                    </button>
                  </div>
                  <p v-if="sub.description" class="subtask-detail">{{ sub.description }}</p>
                </li>
                </template>
              </ul>
              <form class="subtask-add" @submit.prevent="stageNewStep">
                <div class="subtask-add-row">
                  <input v-model="newStepTitle" placeholder="Add step title" aria-label="Add step title">
                  <select v-model="newStepAgent" aria-label="Assigned to">
                    <option v-if="newStepAgent && !agentNames.includes(newStepAgent)" :value="newStepAgent">{{ agentLabel(newStepAgent) }}</option>
                    <option v-for="name in agentNames" :key="name" :value="name">{{ agentLabel(name) }}</option>
                  </select>
                  <button class="btn" type="submit">Add</button>
                </div>
                <textarea v-model="newStepDetail" rows="3" placeholder="Detailed step instructions" aria-label="Step description"></textarea>
              </form>
              <form id="task-add-form" @submit.prevent="addTask">
                <div class="task-fields">
                  <label class="field">Time
                    <select v-model="taskEdit.when" @change="onTaskWhen">
                      <option value="at">at a time</option>
                      <option value="never">Never (inactivated)</option>
                    </select>
                  </label>
                  <label class="field">Repeat
                    <select v-model="taskEdit.recurrence_interval">
                      <option value="none">run once</option>
                      <option value="5min">every 5 min</option>
                      <option value="15min">every 15 min</option>
                      <option value="30min">every 30 min</option>
                      <option value="hourly">hourly</option>
                      <option value="daily">daily</option>
                      <option value="weekly">weekly</option>
                      <option value="monthly">monthly</option>
                    </select>
                  </label>
                </div>
                <label v-if="taskEdit.when==='at'" class="field">When<input type="datetime-local" v-model="taskEdit.schedule_time"></label>
                <p v-if="taskError" class="form-error">{{ taskError }}</p>
                <div class="task-actions">
                  <button class="btn btn-gold" type="submit">Save task</button>
                  <button class="btn" type="button" @click="cancelAddTask">Cancel</button>
                </div>
              </form>
            </article>
            <div class="task-list">
              <template v-for="task in shownTasks()" :key="task.id">
              <div v-if="dragTaskId === task.id && dragCard" class="task-placeholder" :style="{ height: dragCard.height + 'px' }" :data-task-id="task.id"></div>
              <article class="task-card" :class="['is-' + taskState(task), { 'is-dragging': dragTaskId === task.id, 'is-failed': taskFailed(task) }]" :data-task-id="task.id" :style="taskDragStyle(task)">
                <header v-if="openTask !== task.id" class="task-row">
                  <button class="task-drag" type="button" aria-label="Drag to reorder" @pointerdown="onTaskPointerDown(task, $event)">
                    <svg class="ico" aria-hidden="true"><use href="#i-grip"></use></svg>
                  </button>
                  <span class="task-name">
                    <button class="task-title" type="button" :class="{ 'is-finished': stepsDone(task) }" :aria-expanded="expandedTasks[task.id] ? 'true' : 'false'" @click="toggleSteps(task)">{{ flatTitle(task.title) }}</button>
                    <span class="task-tag">#{{ task.id }}</span>
                    <span v-if="taskNextRun(task)" class="task-when">{{ taskNextRun(task) }}</span>
                  </span>
                  <span class="task-tools">
                    <button v-if="taskStatus(task)==='done' && !taskRepeats(task)" class="task-edit" type="button" aria-label="Archive task" @click="archiveTask(task)">
                      <svg class="ico" aria-hidden="true"><use href="#i-archive"></use></svg>
                    </button>
                    <button class="task-edit" type="button" :aria-label="openTask === task.id ? 'Close' : 'Edit'" @click="toggleTask(task)">
                      <svg class="ico" aria-hidden="true"><use :href="openTask === task.id ? '#i-close' : '#i-edit'"></use></svg>
                    </button>
                    <button class="task-edit" type="button" aria-label="Delete task" @click="deleteTask(task)">
                      <svg class="ico" aria-hidden="true"><use href="#i-trash"></use></svg>
                    </button>
                  </span>
                </header>
                <p v-if="taskFailed(task) && task.result && !expandedTasks[task.id]" class="task-fail">{{ task.result }}</p>
                <label v-if="openTask === task.id" class="field task-title-field">Title<input v-model="taskEdit.title" :form="'task-edit-' + task.id"></label>
                <ul v-if="expandedTasks[task.id] && (task.subtasks || []).length" class="subtasks is-saved" :data-step-list="String(task.id)">
                  <template v-for="sub in task.subtasks" :key="sub.id">
                  <li v-if="dragStepKey === stepKey(sub) && dragStepCard" class="step-placeholder" :style="{ height: dragStepCard.height + 'px' }" :data-step-key="stepKey(sub)"></li>
                  <li class="subtask" :class="{ 'is-done': subtaskStatus(sub) === 'done', 'is-dragging': dragStepKey === stepKey(sub) }" :data-step-key="stepKey(sub)" :style="stepDragStyle(sub)">
                    <div class="subtask-row">
                      <button class="step-drag" type="button" aria-label="Drag to reorder" @pointerdown="onStepPointerDown(task.id, $event)">
                        <svg class="ico" aria-hidden="true"><use href="#i-grip"></use></svg>
                      </button>
                      <button class="subtask-check" type="button" :aria-pressed="subtaskStatus(sub) === 'done'" aria-label="Mark step done" @click="toggleSubtaskStatus(task, sub)"></button>
                      <span class="subtask-title">{{ sub.title }}</span>
                      <span class="task-meta">{{ agentLabel(sub.assigned_agent || task.assigned_agent) }}</span>
                      <button class="task-edit" type="button" :aria-label="openSubtask === sub.id ? 'Close' : 'Edit step'" @click="toggleSubtask(task, sub)">
                        <svg class="ico" aria-hidden="true"><use :href="openSubtask === sub.id ? '#i-close' : '#i-edit'"></use></svg>
                      </button>
                      <button class="task-edit" type="button" aria-label="Remove step" @click="deleteSubtask(task, sub)">
                        <svg class="ico" aria-hidden="true"><use href="#i-trash"></use></svg>
                      </button>
                    </div>
                    <p v-if="openSubtask !== sub.id && sub.description && !stepResult(task, sub)" class="subtask-detail">{{ sub.description }}</p>
                    <p v-if="openSubtask !== sub.id && stepResult(task, sub)" class="task-result is-full step-result"><span class="task-result-label">Result:</span><span class="task-result-line"><template v-for="(part, index) in resultParts(stepResult(task, sub))" :key="index"><a v-if="part.href" :href="part.href" target="_blank" rel="noopener">{{ part.text }}</a><span v-else>{{ part.text }}</span></template></span></p>
                    <form v-if="isCurrentHumanStep(task, sub) && openSubtask !== sub.id" class="human-step" @submit.prevent="finishHumanStep(task, sub)">
                      <textarea v-model="humanNotes[sub.id]" rows="3" placeholder="Further instructions" :aria-label="'Further instructions for ' + sub.title"></textarea>
                      <p v-if="humanStepErrorId === sub.id && humanStepError" class="form-error">{{ humanStepError }}</p>
                      <button class="btn" type="submit">Finish step</button>
                    </form>
                    <form v-if="openSubtask === sub.id" class="subtask-form" @submit.prevent="saveSubtask(task, sub)">
                      <label class="field">Title<input v-model="subtaskEdit.title"></label>
                      <label class="field">Assigned to
                        <select v-model="subtaskEdit.assigned_agent">
                          <option v-if="subtaskEdit.assigned_agent && !agentNames.includes(subtaskEdit.assigned_agent)" :value="subtaskEdit.assigned_agent">{{ agentLabel(subtaskEdit.assigned_agent) }}</option>
                          <option v-for="name in agentNames" :key="name" :value="name">{{ agentLabel(name) }}</option>
                        </select>
                      </label>
                      <label class="field">Description<textarea v-model="subtaskEdit.description" rows="4" placeholder="Requirements"></textarea></label>
                      <p v-if="subtaskError" class="form-error">{{ subtaskError }}</p>
                      <div class="task-actions">
                        <button class="btn btn-gold" type="submit">Save step</button>
                        <button class="btn" type="button" @click="toggleSubtask(task, sub)">Cancel</button>
                      </div>
                    </form>
                  </li>
                  </template>
                </ul>
                <p v-if="openTask !== task.id && expandedTasks[task.id] && task.result && (taskFailed(task) || !(task.subtasks || []).length)" class="task-result is-full"><span class="task-result-label">{{ taskFailed(task) ? 'Error:' : 'Result:' }}</span><span class="task-result-line"><template v-for="(part, index) in resultParts(task.result)" :key="index"><a v-if="part.href" :href="part.href" target="_blank" rel="noopener">{{ part.text }}</a><span v-else>{{ part.text }}</span></template></span></p>
                <form v-if="openTask === task.id" class="subtask-add" @submit.prevent="addSubtask(task)">
                  <div class="subtask-add-row">
                    <input v-model="subtaskDrafts[task.id]" placeholder="Add step title" aria-label="Add step title">
                    <select v-model="subtaskAgents[task.id]" aria-label="Assigned to">
                      <option v-if="subtaskAgents[task.id] && !agentNames.includes(subtaskAgents[task.id])" :value="subtaskAgents[task.id]">{{ agentLabel(subtaskAgents[task.id]) }}</option>
                      <option v-for="name in agentNames" :key="name" :value="name">{{ agentLabel(name) }}</option>
                    </select>
                    <button class="btn" type="submit">Add</button>
                  </div>
                  <textarea v-model="subtaskDetails[task.id]" rows="3" placeholder="Detailed step instructions" aria-label="Step description"></textarea>
                </form>
                <form v-if="openTask === task.id" :id="'task-edit-' + task.id" @submit.prevent="saveTask(task)">
                  <div class="task-fields">
                    <label class="field">Time
                      <select v-model="taskEdit.when" @change="onTaskWhen">
                        <option value="at">at a time</option>
                        <option value="never">Never (inactivated)</option>
                      </select>
                    </label>
                    <label class="field">Repeat
                      <select v-model="taskEdit.recurrence_interval">
                        <option value="none">run once</option>
                        <option value="5min">every 5 min</option>
                        <option value="15min">every 15 min</option>
                        <option value="30min">every 30 min</option>
                        <option value="hourly">hourly</option>
                        <option value="daily">daily</option>
                        <option value="weekly">weekly</option>
                        <option value="monthly">monthly</option>
                      </select>
                    </label>
                  </div>
                  <label v-if="taskEdit.when==='at'" class="field">When<input type="datetime-local" v-model="taskEdit.schedule_time"></label>
                  <p v-if="taskError" class="form-error">{{ taskError }}</p>
                  <div class="task-actions">
                    <button class="btn btn-gold" type="submit">Save task</button>
                    <button class="btn" type="button" @click="toggleTask(task)">Cancel</button>
                  </div>
                </form>
              </article>
              </template>
              <p v-if="!openTasks().length" class="empty">No tasks yet.</p>
              <p v-else-if="!shownTasks().length" class="empty">No tasks match these filters.</p>
            </div>
              </div>
            </div>
            <section class="task-archive">
              <header>
                <h3>
                  <button class="archive-toggle" type="button" :aria-expanded="archiveOpen ? 'true' : 'false'" @click="archiveOpen = !archiveOpen">
                    <svg class="ico archive-arrow" :class="{ 'is-open': archiveOpen }" aria-hidden="true"><use href="#i-chevron"></use></svg>
                    Archive
                  </button>
                </h3>
                <div class="archive-actions">
                  <button v-if="archiveOpen && archivedTasks().length" class="btn btn-ghost" type="button" @click="clearArchivedTasks">Clear archive</button>
                  <button v-if="archiveOpen && archivedTasks().length > 4" class="btn btn-ghost" type="button" @click="finishedOpen = !finishedOpen">{{ finishedOpen ? 'Show less' : 'Show all' }}</button>
                </div>
              </header>
              <div v-if="archiveOpen && archivedVisible().length" class="task-list">
                <article v-for="task in archivedVisible()" :key="'done'+task.id" class="task-card is-archive" @click="toggleArchive(task)">
                  <header class="task-row">
                    <span class="task-name">
                      <h3 class="task-title">{{ flatTitle(task.title) }}</h3>
                      <span class="task-tag">#{{ task.id }}</span>
                    </span>
                    <span class="task-tools">
                      <button class="task-edit" type="button" aria-label="Restore task" @click.stop="restoreTask(task)">
                        <svg class="ico" aria-hidden="true"><use href="#i-restore"></use></svg>
                      </button>
                      <button class="task-edit" type="button" aria-label="Delete task" @click.stop="deleteTask(task)">
                        <svg class="ico" aria-hidden="true"><use href="#i-trash"></use></svg>
                      </button>
                    </span>
                  </header>
                  <p v-if="openArchive === task.id && task.result" class="task-result is-full">{{ task.result }}</p>
                </article>
              </div>
              <p v-else-if="archiveOpen" class="empty">No archived tasks yet.</p>
            </section>
          </section>

          <section v-else-if="tab==='research'" class="view research-view">
            <div class="view-head">
              <h2>Research</h2>
              <label v-if="researchMissions.length" class="research-pick">
                <select :value="researchSlug" aria-label="Research mission" :disabled="researchBusy" @change="openResearch($event.target.value)">
                  <option v-for="mission in researchMissions" :key="mission.name" :value="mission.name">{{ mission.name }}{{ mission.active ? ' · On' : '' }}</option>
                </select>
              </label>
              <div class="view-actions">
                <button class="btn" type="button" @click="researchFormOpen = true">New mission</button>
                <button v-if="researchBoard && researchBoard.active" class="btn btn-stop" type="button" :disabled="researchBusy" @click="stopResearch">Stop</button>
                <button v-else class="btn btn-gold" type="button" :disabled="researchBusy || !researchSlug" @click="runResearch">Start</button>
                <button class="btn" type="button" :disabled="researchBusy || !researchSlug" @click="deleteResearch">Delete</button>
              </div>
            </div>
            <p v-if="researchError && !researchFormOpen" class="form-error">{{ researchError }}</p>
            <div v-if="researchBoard" class="research-body">
              <div class="research-main">
                <div class="research-progress">
                  <button class="btn" type="button" @click="researchTreeOpen = true">Open tree</button>
                  <span>{{ researchProgress() }}</span>
                </div>
                <div class="research-dash">
                  <button v-for="item in researchDashTabs()" :key="item.id" type="button" :class="{ active: researchDash === item.id }" @click="researchDash = item.id">
                    {{ item.label }}<span class="count">{{ item.count }}</span>
                  </button>
                </div>
                <div class="research-cards">
                  <p v-if="!researchDashItems().length" class="muted">{{ researchDashEmpty() }}</p>
                  <article v-for="item in researchDashItems()" :key="item.id" class="research-card panel">
                    <h3><button type="button" @click="selectResearchNodeId(item.id)">{{ item.statement }}</button></h3>
                    <p class="muted">{{ item.status }}<template v-if="item.confidence"> · {{ item.confidence }}</template></p>
                    <p v-if="item.insight">{{ item.insight }}</p>
                    <p v-else-if="item.needs_review" class="muted">This result is waiting for you.</p>
                    <p v-else-if="item.status === 'testing'" class="muted">This idea is being checked.</p>
                    <div v-if="researchDash === 'you' || researchDash === 'ideas'" class="research-actions">
                      <button v-if="item.needs_review" class="btn btn-gold" type="button" :disabled="researchBusy" @click="reviewResearchNode(item.id, 'noted')">Keep</button>
                      <button v-else-if="item.status === 'proposed' && !researchGroups().testing.length" class="btn btn-gold" type="button" :disabled="researchBusy" @click="reviewResearchNode(item.id, 'testing')">Pursue</button>
                      <button v-if="item.needs_review || item.status === 'proposed' || item.status === 'testing'" class="btn" type="button" :disabled="researchBusy" title="Park this node and the open nodes under it" @click="reviewResearchNode(item.id, 'parked')">Park</button>
                    </div>
                  </article>
                </div>
              </div>
            </div>
            <p v-else-if="!researchMissions.length" class="empty">No mission yet.</p>
            <div v-if="researchTreeOpen && researchBoard" class="research-screen">
              <button class="research-screen-close" type="button" aria-label="Close" @click="researchTreeOpen = false">
                <svg class="ico" aria-hidden="true"><use href="#i-close"></use></svg>
              </button>
              <div class="research-screen-bar">
                <span class="research-zoom">
                  <button type="button" @click="researchZoom = Math.max(0.55, Math.round((researchZoom - 0.15) * 100) / 100)">−</button>
                  <button type="button" @click="researchZoom = Math.min(1.6, Math.round((researchZoom + 0.15) * 100) / 100)">+</button>
                </span>
              </div>
              <div class="research-tree-body">
                <div ref="researchCanvas" class="research-canvas" @pointerdown="researchPanStart" @pointermove="researchPanMove" @pointerup="researchPanEnd" @pointercancel="researchPanEnd">
                  <div class="research-stage" :style="{ width: (researchMap().width * researchZoom) + 'px', height: (researchMap().height * researchZoom) + 'px' }">
                    <div class="research-scale" :style="{ width: researchMap().width + 'px', height: researchMap().height + 'px', transform: 'scale(' + researchZoom + ')' }">
                      <svg class="research-lines" :width="researchMap().width" :height="researchMap().height">
                        <path v-for="edge in researchMap().edges" :key="edge.id" :d="edge.d"></path>
                      </svg>
                      <button v-for="node in researchMap().nodes" :key="node.id" type="button" class="research-node" :class="['is-' + node.status, { 'is-on': researchSelected === node.id, 'is-best': researchBoard.best && researchBoard.best.id === node.id }]" :style="{ left: node.x + 'px', top: node.y + 'px', width: node.w + 'px', height: node.h + 'px' }" @click.stop="selectResearchNode(node)">
                        <span v-if="!node.goal" class="research-chip">{{ node.status }}</span>
                        <strong>{{ node.statement }}</strong>
                      </button>
                    </div>
                  </div>
                </div>
                <aside class="research-detail panel">
                  <template v-if="researchCurrent() && researchCurrent().goal">
                    <p class="research-chip is-goal">Goal</p>
                    <h3>{{ researchCurrent().statement }}</h3>
                    <p>{{ researchCurrent().question }}</p>
                    <p v-if="researchCurrent().scope" class="muted">{{ researchCurrent().scope }}</p>
                  </template>
                  <template v-else-if="researchCurrent()">
                    <p class="research-chip" :class="'is-' + researchCurrent().status">{{ researchCurrent().status }} · {{ researchCurrent().confidence }}</p>
                    <h3>{{ researchCurrent().statement }}</h3>
                    <p class="muted">{{ researchCurrent().parent_id ? 'Parent #' + researchCurrent().parent_id : 'Goal' }} · #{{ researchCurrent().id }}</p>
                    <p v-if="researchCurrent().insight"><strong>Conclusion.</strong> {{ researchCurrent().insight }}</p>
                    <p v-else class="muted">No conclusion on this node yet.</p>
                    <p v-if="researchCurrent().inherited"><strong>From the branch.</strong> {{ researchCurrent().inherited }}</p>
                    <p v-if="researchCurrent().falsification"><strong>Would falsify it.</strong> {{ researchCurrent().falsification }}</p>
                    <ul v-if="researchCurrent().claims && researchCurrent().claims.length" class="research-claims">
                      <li v-for="claim in researchCurrent().claims" :key="claim.id">{{ claim.text }}</li>
                    </ul>
                    <div class="research-actions">
                      <button class="btn btn-gold" type="button" :disabled="researchBusy" @click="reviewResearch('testing')">Pursue</button>
                      <button class="btn" type="button" :disabled="researchBusy" title="Park this node and the open nodes under it" @click="reviewResearch('parked')">Park</button>
                      <button class="btn" type="button" :disabled="researchBusy" @click="reviewResearch('noted')">Keep</button>
                    </div>
                    <label class="field">Direction
                      <textarea v-model="researchDirection" rows="3" placeholder="Where this branch should go next" @focus="researchDirectionFocused = true" @blur="researchDirectionFocused = false"></textarea>
                    </label>
                    <button class="btn" type="button" :disabled="researchBusy" @click="reviewResearch('', true)">Save direction</button>
                  </template>
                  <p v-else class="muted">Click a node to read it.</p>
                </aside>
              </div>
            </div>
            <div v-if="researchFormOpen" class="modal-back" @click.self="researchFormOpen = false">
              <form class="modal project-modal" @submit.prevent="createResearch">
                <h2>New mission</h2>
                <label class="field">Short name<input v-model="researchForm.title" required></label>
                <label class="field">Goal<textarea v-model="researchForm.question" rows="4" required placeholder="What this mission should find out"></textarea></label>
                <label class="field">Scope<input v-model="researchForm.scope" placeholder="Optional"></label>
                <p class="form-error">{{ researchError }}</p>
                <div class="view-actions">
                  <button class="btn btn-gold" type="submit" :disabled="researchBusy">Create mission</button>
                  <button class="btn" type="button" @click="researchFormOpen = false">Cancel</button>
                </div>
              </form>
            </div>
          </section>

          <section v-else-if="tab==='workspace'" class="view workspace-view" :class="{ 'is-vault': vaultOpen }">
            <div class="view-head">
              <h2>Files</h2>
              <form class="wiki-tools workspace-search" @submit.prevent="searchWorkspace">
                <div class="search-field">
                  <svg class="search-ico" aria-hidden="true"><use href="#i-search"></use></svg>
                  <input v-model="wikiQuery" :aria-label="vaultOpen ? 'Search backups' : 'Search files'" :placeholder="vaultOpen ? 'Search backups' : 'Search filename or content'">
                  <button v-if="wikiQuery" class="search-clear" type="button" aria-label="Clear search" @click="clearWorkspaceSearch">
                    <svg class="ico" aria-hidden="true"><use href="#i-close"></use></svg>
                  </button>
                </div>
              </form>
              <button class="btn btn-ghost" type="button" @click="toggleVault">{{ vaultOpen ? 'Hide backups' : 'Show backups' }}</button>
            </div>
            <div v-if="!vaultOpen" class="split">
              <div class="panel" @scroll="treeMenu = null">
                <p v-if="treeError" class="form-error">{{ treeError }}</p>
                <file-tree :nodes="tree" :selected="fileKey" :over-key="treeOverKey" @open="openFile" @menu="openTreeMenu" @move="moveTreeNode" @hover="treeOverKey = $event"></file-tree>
                <p v-if="!tree.length" class="empty">No files yet.</p>
              </div>
              <div class="panel file-pane" :class="{ 'is-editing': (fileEditing || structuredKind) && !showingSearch, 'is-pdf': fileKind==='pdf' && !fileEditing && !showingSearch }">
                <template v-if="showingSearch">
                  <h3 class="file-title">Search results</h3>
                  <p v-if="wikiResult" class="empty">{{ wikiResult }}</p>
                  <ul v-else class="search-hits">
                    <li v-for="hit in searchHits" :key="hit.scope + hit.folder + hit.path">
                      <button type="button" class="tree-btn file" @click="openFile(hit)"><svg class="tree-ico" aria-hidden="true"><use href="#i-file"></use></svg><span class="tree-name">{{ hit.folder ? hit.folder + '/' + (hit.path || hit.name) : (hit.path || hit.name) }}</span></button>
                    </li>
                  </ul>
                </template>
                <p v-else-if="!fileName" class="empty">Choose a file or folder.</p>
                <template v-else>
                  <div class="file-head">
                    <h3 class="file-title">{{ fileName }}</h3>
                    <div class="file-tools">
                      <button v-if="!fileIsDir && (fileEditing || (structuredKind && structuredKind!=='sqlite'))" class="btn btn-gold" type="button" @click="structuredKind ? saveStructuredFile() : saveFile()">Save</button>
                      <button v-if="!fileIsDir && !filePreview" class="file-icon" type="button" :aria-label="fileEditing ? 'Close editor' : 'Edit'" @click="fileEditing ? cancelFileEdit() : startFileEdit()">
                        <svg class="ico" aria-hidden="true"><use :href="fileEditing ? '#i-close' : '#i-edit'"></use></svg>
                      </button>
                      <button v-if="!fileIsDir" class="file-icon" type="button" aria-label="Download" @click="downloadFile">
                        <svg class="ico" aria-hidden="true"><use href="#i-download"></use></svg>
                      </button>
                      <button v-if="canTrashWorkspace" class="file-icon" type="button" :aria-label="fileIsDir ? 'Move folder to trash' : 'Move file to trash'" :title="fileIsDir ? 'Move folder to trash' : 'Move file to trash'" @click="deleteFile">
                        <svg class="ico" aria-hidden="true"><use href="#i-trash"></use></svg>
                      </button>
                    </div>
                  </div>
                  <p v-if="fileError" class="form-error">{{ fileError }}</p>
                  <p v-else-if="fileIsDir && !fileRel" class="hint">Top-level project folders cannot be deleted here.</p>
                  <p v-else-if="fileIsDir" class="hint">This folder and everything inside can be moved to trash.</p>
                  <div v-if="structuredKind" class="structured-editor">
                    <div v-if="structuredLoading" class="hint">Loading structured file…</div>
                    <template v-else-if="structuredKind==='csv' && structuredData">
                      <div class="structured-tools">
                        <button class="btn" type="button" @click="addCsvRow">Add row</button>
                      </div>
                      <div class="structured-table-wrap">
                        <table class="structured-table">
                          <thead><tr><th v-for="(header, index) in structuredData.headers" :key="'h'+index"><input v-model="structuredData.headers[index]" aria-label="Column name"></th><th></th></tr></thead>
                          <tbody>
                            <tr v-for="(row, rowIndex) in structuredData.rows" :key="'r'+rowIndex">
                              <td v-for="(header, columnIndex) in structuredData.headers" :key="'c'+rowIndex+'-'+columnIndex"><input v-model="structuredData.rows[rowIndex][columnIndex]" :aria-label="header || 'Cell'"></td>
                              <td><button class="file-icon" type="button" aria-label="Delete row" @click="deleteCsvRow(rowIndex)">×</button></td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </template>
                    <template v-else-if="structuredKind==='sqlite' && structuredData">
                      <label class="field structured-select">Table
                        <select v-model="structuredTableName" @change="loadStructuredFile">
                          <option v-for="table in structuredData.tables" :key="table.name" :value="table.name">{{ table.name }}</option>
                        </select>
                      </label>
                      <div v-if="selectedStructuredTable" class="structured-table-wrap">
                        <div class="structured-tools"><button class="btn" type="button" @click="addSqliteRow">Add row</button></div>
                        <table class="structured-table">
                          <thead><tr><th v-for="column in selectedStructuredTable.columns" :key="column.name">{{ column.name }}<span v-if="column.pk" class="hint"> · PK</span></th><th></th></tr></thead>
                          <tbody><tr v-for="(row, rowIndex) in selectedStructuredTable.rows" :key="'db'+rowIndex">
                            <td v-for="column in selectedStructuredTable.columns" :key="column.name"><input v-model="row[column.name]" :disabled="!!column.pk" @change="saveSqliteAction('update', row)"></td>
                            <td><button class="file-icon" type="button" aria-label="Delete row" @click="deleteSqliteRow(row)">×</button></td>
                          </tr></tbody>
                        </table>
                      </div>
                    </template>
                    <template v-else-if="structuredKind==='docx' && structuredData">
                      <p class="hint">Text, paragraph order, and table contents are editable. Advanced Word layout, images, and tracked changes are not edited.</p>
                      <label v-for="(paragraph, index) in structuredData.paragraphs" :key="'p'+index" class="field">Paragraph {{ index + 1 }}<textarea v-model="structuredData.paragraphs[index]" rows="2"></textarea></label>
                      <div v-for="(table, tableIndex) in structuredData.tables" :key="'t'+tableIndex" class="structured-doc-table">
                        <strong>Table {{ tableIndex + 1 }}</strong>
                        <table class="structured-table"><tbody><tr v-for="(row, rowIndex) in table" :key="'tr'+rowIndex"><td v-for="(cell, cellIndex) in row" :key="'tc'+cellIndex"><textarea v-model="structuredData.tables[tableIndex][rowIndex][cellIndex]" rows="2"></textarea></td></tr></tbody></table>
                      </div>
                    </template>
                  </div>
                  <textarea v-else-if="fileEditing" v-model="fileDraft" class="file-editor" aria-label="File text"></textarea>
                  <iframe v-else-if="fileKind==='pdf' && filePreviewUrl" class="file-frame" :src="filePreviewUrl" :title="fileName"></iframe>
                  <img v-else-if="fileKind==='image' && filePreviewUrl" class="file-image" :src="filePreviewUrl" :alt="fileName" @error="onPreviewError">
                  <div v-else-if="fileMarkdown" class="md" v-html="md(fileContent)"></div>
                  <pre v-else-if="!filePreview" class="file-view">{{ fileContent }}</pre>
                </template>
              </div>
            </div>
            <div v-if="vaultOpen" class="vault panel">
              <h3>Restoration</h3>
              <p class="hint">Agents can read and restore these files. They cannot delete them. Trash older than 10 days is removed automatically.</p>
              <article v-for="item in listedVault(backups)" :key="'b'+item.stored_as" class="log-row vault-row">
                <div class="vault-name">Backup · {{ item.original }}</div>
                <button class="btn" :class="{ 'is-restored': vaultRestored('backup', item.stored_as) }" type="button" :disabled="vaultRestored('backup', item.stored_as)" @click="restoreItem('backup', item.stored_as)">{{ vaultRestored('backup', item.stored_as) ? 'Restored' : 'Restore' }}</button>
              </article>
              <article v-for="item in listedVault(trash)" :key="'t'+item.stored_as" class="log-row vault-row">
                <div class="vault-name">Trash · {{ item.original }}</div>
                <button class="btn" :class="{ 'is-restored': vaultRestored('trash', item.stored_as) }" type="button" :disabled="vaultRestored('trash', item.stored_as)" @click="restoreItem('trash', item.stored_as)">{{ vaultRestored('trash', item.stored_as) ? 'Restored' : 'Restore' }}</button>
              </article>
              <p v-if="!listedVault(backups).length && !listedVault(trash).length" class="empty">{{ wikiQuery.trim() ? 'No matches.' : 'Nothing to restore.' }}</p>
            </div>
          </section>

          <section v-else-if="tab==='agents'" class="view">
            <div class="view-head">
              <h2>Activity</h2>
              <div class="view-actions">
                <button v-if="heartbeatPaused" class="btn btn-gold btn-start" type="button" :disabled="startBusy" @click="startAgents"><svg class="ico" aria-hidden="true"><use href="#i-play"></use></svg>Start</button>
                <button v-if="!heartbeatPaused" class="btn" :class="{ 'btn-stop': stopReady }" type="button" :disabled="!stopReady" @click="stopAgents"><svg v-if="stopReady" class="ico" aria-hidden="true"><use href="#i-alert"></use></svg>Stop</button>
              </div>
            </div>
            <p v-if="stopError" class="form-error">{{ stopError }}</p>
            <div v-if="pendingActions.length" class="setup-box">
              <h3>Pending approvals</h3>
              <p class="hint">The agent is waiting for you before continuing.</p>
              <article v-for="item in pendingActions" :key="item.id" class="log-row email-row">
                <div>
                  <strong>{{ item.kind }}</strong>
                  <div class="hint">{{ item.summary }}</div>
                </div>
                <span>
                  <button class="btn btn-gold" type="button" @click="answerPending(item, true)">Allow</button>
                  <button class="btn" type="button" @click="answerPending(item, false)">Don't allow</button>
                </span>
              </article>
            </div>
            <div v-if="mainAgent" class="flow-board">
              <div class="flow">
                <div class="flow-top">
                  <div v-if="taskAgent" class="flow-task-agent">
                    <article class="flow-node is-task" :class="['is-' + taskAgent.status, { 'is-picked': logAgent === taskAgent.name }]" @click="pickLogAgent(taskAgent.name)">
                      <svg class="avatar" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-av-scheduler"></use></svg>
                      <div class="flow-copy">
                        <strong>{{ agentLabel(taskAgent.name) }}</strong>
                        <p class="flow-status">
                          <span v-if="agentBusy(taskAgent)" class="spinner"></span>
                          <span :class="'status-' + taskAgent.status">{{ taskAgent.label }}</span>
                        </p>
                        <p class="hint flow-task">{{ agentWork(taskAgent) }}</p>
                      </div>
                    </article>
                  </div>
                  <div v-if="taskAgent" class="flow-task-down" :class="{live: agentBusy(taskAgent)}"></div>
                  <article class="flow-node is-main" :class="['is-' + mainAgent.status, { 'is-picked': logAgent === mainAgent.name }]" @click="pickLogAgent(mainAgent.name)">
                    <svg class="avatar" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-av-orchestrator"></use></svg>
                    <div class="flow-copy">
                      <strong>{{ agentLabel(mainAgent.name) }}</strong>
                      <p class="flow-status">
                        <span v-if="agentBusy(mainAgent) && !workingSubagents.length" class="spinner"></span>
                        <span :class="'status-' + mainAgent.status">{{ mainAgent.label }}</span>
                      </p>
                      <p class="hint flow-task">{{ agentWork(mainAgent) }}</p>
                    </div>
                  </article>
                  <div v-if="consultant" class="flow-consult">
                    <span class="flow-link" :class="{live: agentBusy(consultant)}"></span>
                    <article class="flow-node is-consultant" :class="['is-' + consultant.status, { 'is-picked': logAgent === consultant.name }]" @click="pickLogAgent(consultant.name)">
                      <svg class="avatar" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-av-consultant"></use></svg>
                      <div class="flow-copy">
                        <strong>{{ agentLabel(consultant.name) }}</strong>
                        <p class="flow-status">
                          <span v-if="agentBusy(consultant)" class="spinner"></span>
                          <span :class="'status-' + consultant.status">{{ consultant.label }}</span>
                        </p>
                        <p class="hint flow-task">
                          <button class="retry-link" type="button" @click.stop="openProviderSetup">{{ consultant.reason === 'on' ? 'Enabled' : 'Edit settings' }}</button>
                        </p>
                      </div>
                    </article>
                </div>
                <div class="flow-down" :class="{live: flowLive}"></div>
              </div>
                <div class="flow-row" :style="{ gridTemplateColumns: 'repeat(' + subAgents.length + ', minmax(148px, 1fr))', '--flow-inset': flowInset }">
                  <div v-for="agent in subAgents" :key="agent.name" class="flow-slot">
                    <span class="flow-stem" :class="{live: agentBusy(agent)}"></span>
                    <article class="flow-node" :class="['is-' + agent.status, { 'is-picked': logAgent === agent.name }]" @click="pickLogAgent(agent.name)">
                      <svg class="avatar" viewBox="0 0 24 24" aria-hidden="true"><use :href="'#i-av-' + agent.name"></use></svg>
                      <div class="flow-copy">
                        <strong>{{ agentLabel(agent.name) }}</strong>
                        <p class="flow-status">
                          <span v-if="agentBusy(agent)" class="spinner"></span>
                          <span :class="'status-' + agent.status">{{ agent.label }}</span>
                        </p>
                        <p class="hint flow-task">{{ agentWork(agent) }}</p>
                      </div>
                    </article>
                  </div>
                </div>
              </div>
            </div>
            <div class="flow-log-head">
              <h3 class="flow-log-title">{{ logAgent ? agentLabel(logAgent) : 'Activity' }}</h3>
              <span class="flow-log-actions">
                <button v-if="logAgent" class="btn btn-ghost" type="button" @click="pickLogAgent(logAgent)">Show all</button>
                <a class="btn btn-ghost" :href="'/api/agents/loop/download?project_id=' + (projectId || 0)">Download activity</a>
              </span>
            </div>
            <div class="log-feed">
              <article v-for="log in logs" :key="log.id" class="log-row log-item">
                <button type="button" class="log-summary" @click="toggleLog(log.id)" :aria-expanded="openLogs[log.id] ? 'true' : 'false'">
                  <span class="type">{{ agentLabel(log.agent_name) }} · {{ logProject(log) }} · {{ logKind(log.log_type) }} · {{ log.created_at }}</span>
                  <span v-if="!openLogs[log.id]" class="hint">{{ logPreview(log.content) }}</span>
                </button>
                <div v-if="openLogs[log.id]" class="md" v-html="md(log.content)"></div>
              </article>
              <p v-if="!logs.length" class="empty">{{ logAgent ? 'No activity for this agent yet.' : (projectId ? 'No activity in this project yet.' : 'No activity yet.') }}</p>
            </div>
            <div class="flow-log-head">
              <h3 class="flow-log-title">Loop</h3>
            </div>
            <div class="log-feed">
              <article v-for="log in loopLogs" :key="'loop-' + log.id" class="log-row">
                <div class="log-summary">
                  <span class="type">{{ agentLabel(log.agent_name) }} · {{ logProject(log) }} · {{ loopKind(log.kind) }} · {{ log.created_at }}</span>
                  <span class="hint">{{ log.line }}</span>
                </div>
              </article>
              <p v-if="!loopLogs.length" class="empty">No loop trace yet.</p>
            </div>
          </section>

          <section v-else-if="tab==='skills'" class="view skills-view">
            <div class="view-head">
              <h2>Skills</h2>
              <button class="btn btn-gold" type="button" @click="newSkill">New skill</button>
            </div>
            <div class="split">
              <div class="panel skill-list">
                <button v-for="skill in sortedSkills" :key="skill.id" type="button" class="tree-btn skill-pick" :class="{active: !skillCreating && selectedSkillId === skill.id}" @click="openSkill(skill)">
                  <span class="tree-name">{{ skill.name }}</span>
                  <span v-if="skill.created_by === 'system'" class="hint skill-origin">System</span>
                </button>
                <p v-if="!skills.length" class="empty">No skills yet.</p>
              </div>
              <div class="panel skill-pane">
                <form v-if="skillCreating || skillEditing" class="skill-editor" @submit.prevent="saveSkillForm">
                  <h3 class="file-title">{{ skillForm.id ? 'Edit skill' : 'New skill' }}</h3>
                  <label class="field">Name<input v-model="skillForm.name" required pattern="[a-z0-9]+([_-][a-z0-9]+)*"></label>
                  <label class="field">Description<input v-model="skillForm.description" required></label>
                  <label class="field">Instructions<textarea v-model="skillForm.source" class="file-editor" rows="16"></textarea></label>
                  <label class="field">Helper script
                    <select v-model="skillForm.lang">
                      <option value="php">PHP</option>
                      <option value="py">Python</option>
                    </select>
                  </label>
                  <label class="field">Script<textarea v-model="skillForm.script" class="file-editor" rows="8" placeholder="Optional. Reads JSON from stdin and prints the result."></textarea></label>
                  <p class="form-error">{{ skillError }}</p>
                  <div class="task-actions">
                    <button class="btn btn-gold" type="submit">Save skill</button>
                    <button class="btn" type="button" @click="cancelSkillEdit">Cancel</button>
                  </div>
                </form>
                <p v-else-if="!selectedSkill" class="empty">Choose a skill.</p>
                <template v-else>
                  <div class="file-head">
                    <h3 class="file-title">{{ selectedSkill.name }}</h3>
                    <div class="file-tools">
                      <span v-if="selectedSkill.created_by === 'system'" class="hint skill-origin">System</span>
                      <button v-if="selectedSkill.created_by !== 'system'" class="file-icon" type="button" aria-label="Edit" @click="editSkill(selectedSkill)">
                        <svg class="ico" aria-hidden="true"><use href="#i-edit"></use></svg>
                      </button>
                      <button v-if="selectedSkill.created_by !== 'system'" class="btn" type="button" @click="deleteSkill(selectedSkill)">Delete</button>
                    </div>
                  </div>
                  <p class="hint">{{ selectedSkill.description }}</p>
                  <p v-if="selectedSkill.available === false" class="hint">Not available on this computer.</p>
                  <label class="switch-row">Enabled
                    <input type="checkbox" :checked="!!selectedSkill.is_active" @change="toggleSkill(selectedSkill, $event.target.checked)">
                  </label>
                  <p v-if="skillError" class="form-error">{{ skillError }}</p>
                  <p v-if="selectedSkill.instructions" class="hint skill-block-label">Instructions</p>
                  <pre v-if="selectedSkill.instructions" class="file-view">{{ selectedSkill.instructions }}</pre>
                  <p v-if="selectedSkill.script" class="hint skill-block-label">Script</p>
                  <pre v-if="selectedSkill.script" class="file-view">{{ selectedSkill.script }}</pre>
                </template>
              </div>
            </div>
          </section>

          <section v-else-if="tab==='compliance'" class="view">
            <div class="view-head">
              <h2>Compliance &amp; documentation</h2>
              <button class="btn" type="button" @click="setTab('setup')">Back to Setup</button>
            </div>
            <div class="setup-box">
              <h3>Roles</h3>
              <p class="hint"><strong>Danaco</strong> supplies this self-hosted agent framework. <strong>You</strong> install, configure, and operate it. You choose purpose, data, models, and tools, and you are responsible for lawful use under the laws that apply to you.</p>
              <p class="hint">{{ complianceIntendedPurpose }}</p>
            </div>
            <div class="setup-box">
              <h3>Terms &amp; Acceptable Use</h3>
              <p class="hint">Version {{ termsVersion }}. {{ settings.terms_accepted ? 'Accepted' + (settings.terms_accepted_at ? ' at ' + settings.terms_accepted_at + ' UTC' : '') + '.' : 'Not accepted yet.' }}</p>
              <p class="hint"><a href="/legal/terms.php" target="_blank" rel="noopener">Terms of Use</a> · <a href="/legal/aup.php" target="_blank" rel="noopener">Acceptable Use</a></p>
              <button v-if="!settings.terms_accepted" class="btn btn-gold" type="button" @click="acceptTerms">Accept Terms and AUP</button>
            </div>
            <div class="setup-box">
              <h3>EU AI Act for this deployment</h3>
              <label class="field">Does the EU AI Act apply?
                <select v-model="complianceForm.ai_act_applies">
                  <option value="">Not set</option>
                  <option value="yes">Yes</option>
                  <option value="no">No</option>
                  <option value="unsure">Not sure</option>
                </select>
              </label>
              <p class="hint">Yes if your organisation is in the EU/EEA, or if this system is put into service / affects people there in a way that brings the Act into scope. Danaco does not geo-detect or give legal advice.</p>
              <label class="field">Responsible person<input v-model="complianceForm.responsible_person" maxlength="200" placeholder="Name or role"></label>
              <label class="field">Notes on intended use<textarea v-model="complianceForm.intended_use" rows="3" maxlength="4000" placeholder="Optional notes for your organisation"></textarea></label>
            </div>
            <div class="setup-box">
              <h3>Optional oversight tools</h3>
              <p class="hint">Autonomy stays the default. Turn these on only if your organisation wants them.</p>
              <label class="check-row"><input type="checkbox" v-model="complianceForm.compliance_logging"><span>Record high-impact actions in a compliance log</span></label>
              <label class="check-row"><input type="checkbox" v-model="complianceForm.hitl_external_actions"><span>Ask before email, FTP, MCP, and risky shell commands</span></label>
              <label class="field">Log retention (days)<input v-model.number="complianceForm.retention_days" type="number" min="7" max="3650"></label>
              <button class="btn btn-gold" type="button" @click="saveCompliance">Save compliance settings</button>
              <p :class="complianceError ? 'form-error' : 'form-ok'">{{ complianceMessage }}</p>
            </div>
            <div class="setup-box">
              <h3>System card</h3>
              <p class="hint">Export Instructions for Use from the live configuration. Useful for your records; it does not change how the agent runs.</p>
              <div class="backup-actions">
                <button class="btn btn-gold" type="button" @click="downloadSystemCard">Download system card</button>
                <button class="btn" type="button" @click="copyDisclosure">Copy disclosure snippet</button>
              </div>
              <p v-if="disclosureCopied" class="form-ok">Copied.</p>
              <label class="field">Disclosure snippet<textarea :value="complianceDisclosure" rows="3" readonly></textarea></label>
            </div>
            <div class="setup-box">
              <h3>AI literacy (short)</h3>
              <ul class="hint-list">
                <li>This is a language-model framework. It can be wrong, incomplete, or confident about false details.</li>
                <li>You enable tools (email, FTP, MCP, shell). The agent can use them with the autonomy you allow.</li>
                <li>Cloud models and search send data off this machine under those providers’ terms.</li>
                <li>Do not use this software for prohibited AI practices or Annex III high-risk uses unless you independently ensure legal conformity.</li>
                <li>Optional logs and approval gates help oversight; they do not certify compliance.</li>
              </ul>
            </div>
            <div class="setup-box">
              <div class="view-head">
                <h3>Compliance log</h3>
                <span>
                  <a class="btn" :href="'/api/compliance/events/export?format=csv'">CSV</a>
                  <a class="btn" :href="'/api/compliance/events/export?format=json'">JSON</a>
                </span>
              </div>
              <article v-for="event in complianceEvents" :key="event.id" class="log-row log-item">
                <div>
                  <span class="type">{{ event.event_type }} · {{ event.created_at }} · {{ event.actor }}</span>
                  <div class="hint">{{ event.summary }}</div>
                </div>
              </article>
              <p v-if="!complianceEvents.length" class="empty">No compliance events yet.</p>
            </div>
          </section>

          <section v-else class="view" @pointerdown="holdSelectRefresh" @focusin="holdSelectRefresh" @focusout="releaseSelectRefresh" @change="releaseSelectRefresh">
            <div v-if="updateInfo && updateInfo.available" class="update-note">
              <h3>New update available</h3>
              <p>Current version {{ updateInfo.current }} -&gt; New version {{ updateInfo.latest }}</p>
              <p v-if="updateInfo.url"><a :href="updateInfo.url" target="_blank" rel="noopener">View this version on GitHub</a></p>
              <p v-if="updateInfo.blocked" class="hint">{{ updateInfo.blocked }}</p>
              <button v-if="!updateInfo.blocked && !updateBusy" class="btn btn-gold" type="button" @click="runUpdate">Install update</button>
              <div v-if="updateBusy" class="update-wait" role="status">
                <span class="spinner" aria-hidden="true"></span>
                <span>{{ updateMessage }}</span>
              </div>
              <p v-else-if="updateMessage" class="hint">{{ updateMessage }}</p>
            </div>
            <div v-else-if="updateInfo && updateInfo.error" class="update-note">
              <h3>Update check failed</h3>
              <p>{{ updateInfo.error }}</p>
            </div>
            <div class="view-head">
              <h2>Setup</h2>
              <div class="setup-save">
                <p class="form-ok">{{ setupMessage }}</p>
                <button class="btn btn-gold" type="submit" form="setup-form">Save changes</button>
              </div>
            </div>
            <div class="setup-box health-box">
              <div class="health-head">
                <div>
                  <h3>System health</h3>
                  <p class="hint">Current resource usage</p>
                </div>
                <button class="retry-link health-restart" type="button" :disabled="restarting" @click="restartApp">{{ restarting ? 'Restarting…' : 'Restart application' }}</button>
              </div>
              <p v-if="restartError" class="form-error">{{ restartError }}</p>
              <div class="health-bars">
                <div v-for="bar in healthBars" :key="bar.label" class="health-card">
                  <div class="health-card-top"><span>{{ bar.label }}</span><strong>{{ bar.text }}</strong></div>
                  <div class="health-track" role="progressbar" :aria-valuenow="bar.value" aria-valuemin="0" aria-valuemax="100" :aria-label="bar.label">
                    <span :style="{ width: bar.value + '%', minWidth: bar.value > 0 ? '8px' : '0' }"></span>
                  </div>
                </div>
              </div>
            </div>
            <form id="setup-form" class="setup-stack" @submit.prevent="saveSetup">
              <div class="setup-box">
                <h3>Jurisdiction</h3>
                <label class="field">Does the EU AI Act apply to this deployment?
                  <select v-model="settings.ai_act_applies">
                    <option value="">Not set</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                  </select>
                </label>
                <p class="hint">Yes if the organisation is in the EU/EEA, or if the system is put into service / affects people there in a way that brings the Act into scope.</p>
                <p class="hint setup-compliance-link"><button class="retry-link" type="button" @click="openCompliance">Compliance &amp; documentation</button></p>
              </div>
              <div class="setup-box">
                <h3>Appearance</h3>
                <label class="field">Chat text size {{ chatFontPx }}
                  <input v-model.number="settings.chat_font_size" type="range" min="13" max="22" step="1">
                </label>
                <p class="hint" :style="{ fontSize: chatFontPx + 'px' }">This is how messages look in chat.</p>
              </div>
              <div class="setup-box">
                <h3>Model</h3>
                <label class="field">Choose what to use as the main model provider
                  <select v-model="settings.model_mode">
                    <option value="cloud">External LLM</option>
                    <option value="local">Local hardware</option>
                    <option value="cpu_failsafe">CPU failsafe</option>
                  </select>
                </label>
                <label class="field">Local model
                  <select v-model="settings.local_model">
                    <option v-for="name in modelChoices('local_model')" :key="'local-'+name" :value="name">{{ name }}</option>
                  </select>
                </label>
                <label class="field">CPU failsafe model
                  <select v-model="settings.cpu_failsafe_model">
                    <option v-for="name in modelChoices('cpu_failsafe_model')" :key="'cpu-'+name" :value="name">{{ name }}</option>
                  </select>
                </label>
              </div>
              <div class="setup-box" id="provider-box">
                <h3>External LLM provider</h3>
                <label class="field">Use this API for
                  <select v-model="settings.cloud_api_use">
                    <option value="">Not in use</option>
                    <option value="main">Main agent</option>
                    <option value="consultant">Consultant</option>
                  </select>
                </label>
                <p class="hint">When using local models, the consultant agent can be used to increase intelligence for difficult projects</p>
                <label class="field">Max turns {{ settings.cloud_max_turns }}
                  <input v-model.number="settings.cloud_max_turns" type="range" min="10" max="500">
                </label>
                <label class="field">Endpoint URL (OpenAI schema)<input v-model="settings.cloud_base_url" placeholder="https://openrouter.ai/api/v1/responses"></label>
                <label class="field">Model<input v-model="settings.cloud_model" placeholder="openrouter/free"></label>
                <p class="hint">Use an API root, or the full address when it already ends in /chat/completions or /responses. The OpenRouter free router model is openrouter/free.</p>
                <label class="field">API key
                  <input id="provider-key" v-model="cloudKeyDraft" type="password" autocomplete="off" :placeholder="settings.cloud_api_key_set ? 'Saved (' + settings.cloud_api_key + ')' : 'Not set'">
                </label>
                <div class="backup-actions">
                  <button v-if="settings.cloud_api_key_set" class="btn" type="button" @click="clearCloudKey">Clear saved key</button>
                  <button class="btn" type="button" :disabled="providerTestBusy" @click="testProvider">{{ providerTestBusy ? 'Testing…' : 'Test' }}</button>
                </div>
                <p v-if="providerTestMessage" :class="providerTestError ? 'form-error' : 'form-ok'">{{ providerTestMessage }}</p>
              </div>
            </form>
            <div class="setup-box">
              <div class="view-head">
                <h3>Local models in Ollama</h3>
                <button class="btn" type="button" @click="loadOllamaModels" :disabled="ollamaBusy">Refresh</button>
              </div>
              <p class="form-error" v-if="ollamaError">{{ ollamaError }}</p>
              <p class="hint" v-else-if="!ollamaModels.length">No models installed.</p>
              <article v-for="model in ollamaModels" :key="model.name" class="model-row">
                <div>
                  <strong>{{ model.name }}</strong>
                  <p class="hint">{{ model.size_label }}<span v-if="model.parameter_size"> · {{ model.parameter_size }}</span><span v-if="model.modified"> · {{ model.modified }}</span></p>
                  <p class="hint" v-if="model.selected">The agent uses this model.</p>
                  <p class="hint" v-if="model.place === 'gpu'">Loaded on the GPU.</p>
                  <p class="hint" v-else-if="model.place === 'cpu'">Loaded in RAM, not on the GPU.</p>
                  <p class="hint" v-if="model.protected">CPU failsafe. This model stays installed.</p>
                </div>
                <button v-if="!model.protected" class="btn" type="button" :disabled="ollamaBusy" @click="deleteOllamaModel(model)">Delete</button>
              </article>
              <form class="model-install" @submit.prevent="pullOllamaModel">
                <input class="inline-input" v-model="ollamaPullName" placeholder="Model name like qwen3.5:9b-q4_K_M or URL to a .gguf file" :disabled="ollamaBusy" aria-label="Model or Hugging Face link to install">
                <button class="btn btn-gold" type="submit" :disabled="ollamaBusy || !ollamaPullName.trim()">Install</button>
              </form>
              <p class="form-error" v-if="ollamaInstallError">{{ ollamaInstallError }}</p>
              <p :class="ollamaBusy ? 'status-thinking' : 'hint'" v-if="ollamaProgress">{{ ollamaProgress }}</p>
            </div>
            <div class="setup-box">
              <h3>Email</h3>
              <p class="hint">Each mailbox is linked to one project. The agent can send only from that project's address.</p>
              <article v-for="account in emailAccounts" :key="account.id" class="log-row email-row">
                <div>
                  <strong>{{ account.from_name }}</strong> &lt;{{ account.from_email }}&gt;
                  <div class="hint">{{ projectName(account.project_id) }} · {{ account.smtp_host }}:{{ account.smtp_port }}</div>
                </div>
                <button class="btn" type="button" @click="deleteEmailAccount(account)">Delete</button>
              </article>
              <p v-if="!emailAccounts.length" class="empty">No mailboxes yet.</p>
              <form class="email-form" @submit.prevent="saveEmailAccount">
                <label class="field">Project
                  <select v-model.number="emailForm.project_id" required>
                    <option v-for="project in projects" :key="'mail'+project.id" :value="project.id">{{ project.name }}</option>
                  </select>
                </label>
                <label class="field">From name<input v-model="emailForm.from_name" required placeholder="Ada Lovelace"></label>
                <label class="field">From email<input v-model="emailForm.from_email" type="email" required placeholder="ada@example.com"></label>
                <label class="field">SMTP host<input v-model="emailForm.smtp_host" required placeholder="smtp.example.com"></label>
                <label class="field">Port<input v-model.number="emailForm.smtp_port" type="number" min="1" max="65535" required></label>
                <label class="field">Security
                  <select v-model="emailForm.smtp_security">
                    <option value="tls">STARTTLS</option>
                    <option value="ssl">SSL</option>
                    <option value="none">None</option>
                  </select>
                </label>
                <label class="field">Username<input v-model="emailForm.username" required autocomplete="off"></label>
                <label class="field">Password<input v-model="emailForm.password" type="password" required autocomplete="new-password"></label>
                <button class="btn btn-gold" type="submit" :disabled="!projects.length">Add mailbox</button>
              </form>
              <p :class="emailError ? 'form-error' : 'form-ok'">{{ emailMessage }}</p>
            </div>
            <div class="setup-box">
              <h3>FTP</h3>
              <p class="hint">A project can have several logins, one per server. Name each one, preferably by its domain. The agent asks which server to use when there is more than one, unless you already named it.</p>
              <article v-for="account in ftpAccounts" :key="'ftp'+account.id" class="log-row email-row">
                <div>
                  <strong>{{ account.label }}</strong> {{ account.username }}@{{ account.host }}:{{ account.port }}
                  <div class="hint">{{ projectName(account.project_id) }} · {{ account.security === 'ftps' ? 'FTPS' : 'FTP' }}</div>
                </div>
                <button class="btn" type="button" @click="deleteFtpAccount(account)">Delete</button>
              </article>
              <p v-if="!ftpAccounts.length" class="empty">No FTP logins yet.</p>
              <form class="email-form" @submit.prevent="saveFtpAccount">
                <label class="field">Project
                  <select v-model.number="ftpForm.project_id" required>
                    <option v-for="project in projects" :key="'ftp'+project.id" :value="project.id">{{ project.name }}</option>
                  </select>
                </label>
                <label class="field">Name<input v-model="ftpForm.label" required placeholder="example.com"></label>
                <label class="field">Host<input v-model="ftpForm.host" required placeholder="ftp.example.com"></label>
                <label class="field">Port<input v-model.number="ftpForm.port" type="number" min="1" max="65535" required></label>
                <label class="field">Security
                  <select v-model="ftpForm.security">
                    <option value="ftp">FTP</option>
                    <option value="ftps">FTPS</option>
                  </select>
                </label>
                <label class="field">Username<input v-model="ftpForm.username" required autocomplete="off"></label>
                <label class="field">Password<input v-model="ftpForm.password" type="password" required autocomplete="new-password"></label>
                <button class="btn btn-gold" type="submit" :disabled="!projects.length">Add FTP login</button>
              </form>
              <p :class="ftpError ? 'form-error' : 'form-ok'">{{ ftpMessage }}</p>
            </div>
            <div class="setup-box">
              <h3>MCP servers</h3>
              <p class="hint">Servers the agents can call for external services, such as Google Drive or Todoist. A command starts a local server. An https address uses a remote one. Environment holds tokens, one KEY=value per line.</p>
              <article v-for="server in mcpServers" :key="'mcp'+server.id" class="log-row email-row">
                <div>
                  <strong>{{ server.label }}</strong>
                  <div class="hint">{{ server.target }}<span v-if="server.has_env"> · token saved</span></div>
                  <div class="hint" v-if="server.tools.length">{{ server.tools.map(function (tool) { return tool.name; }).join(', ') }}</div>
                  <div class="hint" v-else>Tools not loaded yet.</div>
                </div>
                <div class="mcp-actions">
                  <button class="btn" type="button" :disabled="mcpBusyId===server.id" @click="refreshMcpServer(server)">Refresh tools</button>
                  <button class="btn" type="button" @click="deleteMcpServer(server)">Delete</button>
                </div>
              </article>
              <p v-if="!mcpServers.length" class="empty">No MCP servers yet.</p>
              <div class="mcp-picks">
                <button v-for="pick in mcpPicks" :key="pick.label" class="btn" type="button" @click="fillMcpPick(pick)">{{ pick.label }}</button>
              </div>
              <form class="email-form" @submit.prevent="saveMcpServer">
                <label class="field">Name<input v-model="mcpForm.label" required placeholder="Todoist"></label>
                <label class="field">Command or URL<input v-model="mcpForm.target" required placeholder="npx -y @modelcontextprotocol/server-todoist"></label>
                <label class="field">Environment<textarea v-model="mcpForm.env" rows="3" placeholder="TODOIST_API_TOKEN="></textarea></label>
                <button class="btn btn-gold" type="submit">Add MCP server</button>
              </form>
              <p :class="mcpError ? 'form-error' : 'form-ok'">{{ mcpMessage }}</p>
            </div>
            <div class="setup-box">
              <h3>Backup</h3>
              <p class="hint">Saves the selected projects with their folders, wiki, chat, tasks, logins, and all custom skills. Settings and the agent program stay on this computer.</p>
              <div v-if="projects.length" class="backup-list">
                <label class="check-row">
                  <input type="checkbox" :checked="backupFolders.length === projects.length" @change="toggleAllBackup($event.target.checked)">
                  <span>All projects</span>
                </label>
                <label v-for="project in projects" :key="'bak'+project.id" class="check-row">
                  <input type="checkbox" :value="project.folder_name" v-model="backupFolders">
                  <span>{{ project.name }}</span>
                </label>
              </div>
              <p v-else class="hint">No projects to export.</p>
              <div class="backup-actions">
                <button class="btn btn-gold" type="button" :disabled="backupBusy || !backupFolders.length" @click="downloadBackup">{{ backupBusy ? 'Preparing…' : 'Backup selected' }}</button>
                <label class="btn file-btn">
                  {{ restoreBusy ? 'Reading…' : 'Restore from export' }}
                  <input class="file-pick" type="file" accept=".zip,application/zip" aria-label="Backup zip" :disabled="restoreBusy" @change="stageRestore">
                </label>
              </div>
              <p v-if="backupError" class="form-error">{{ backupError }}</p>
              <p v-if="restoreMessage" class="form-ok">{{ restoreMessage }}</p>
              <p v-if="restoreError && !restoreConflicts.length" class="form-error">{{ restoreError }}</p>
            </div>
            <form class="setup-box" @submit.prevent="setSystemControl(true)">
              <h3>Full system control</h3>
              <p class="hint">Gives the agent full control of this computer, including sudo and every file and folder.</p>
              <p class="form-error">Warning! This could make the agent able to break settings, install programs or crash system functions.</p>
              <template v-if="settings.full_system_control !== '1'">
                <label class="field">Password<input v-model="systemControlPassword" type="password" autocomplete="current-password" required></label>
                <button class="btn btn-gold" type="submit">Turn on</button>
              </template>
              <button v-else class="btn" type="button" @click="setSystemControl(false)">Turn off</button>
              <p v-if="systemControlMessage" :class="systemControlError ? 'form-error' : 'form-ok'">{{ systemControlMessage }}</p>
            </form>
            <form id="setup-password" class="setup-box" @submit.prevent="changePassword">
              <h3>Password</h3>
              <label class="field">Current<input v-model="passwordForm.current" type="password" autocomplete="current-password"></label>
              <label class="field">New<input v-model="passwordForm.next" type="password" autocomplete="new-password"></label>
              <label class="field">Confirm<input v-model="passwordForm.confirm" type="password" autocomplete="new-password"></label>
              <button class="btn btn-gold" type="submit">Update password</button>
              <p :class="passwordMessage === 'Password updated.' ? 'form-ok' : 'form-error'">{{ passwordMessage }}</p>
            </form>
            <p v-if="updateInfo && updateInfo.version" class="app-version">Version {{ updateInfo.version }}</p>
          </section>
        </main>
      </div>
    </div>

    <div v-if="mobileNav" class="drawer-backdrop" @click="mobileNav = false">
      <aside class="drawer" @click.stop>
        <div class="brand"><img class="brand-icon" src="/assets/logo.png" alt=""><strong>Agent Danaco</strong></div>
        <button v-for="item in nav" :key="'d'+item.id" type="button" class="nav-btn" :class="{active: tab===item.id}" @click="setTab(item.id)">
          <svg class="ico"><use :href="'#' + item.icon"></use></svg>
          <span>{{ item.label }}</span>
        </button>
        <div class="project-picker">
          <div class="project-head">
            <h2>Projects</h2>
            <button class="new-project" type="button" @click="openProjectModal">+ add new</button>
          </div>
          <div class="project-list">
            <div v-for="project in projects" :key="'m'+project.id" class="project-row" :class="{active: projectId===project.id}">
              <button class="project-name" type="button" @click="chooseProject(project.id)">{{ project.name }}</button>
              <button class="project-edit" type="button" :aria-label="'Edit ' + project.name" @click.stop="openProjectEdit(project)">
                <svg class="ico" aria-hidden="true"><use href="#i-edit"></use></svg>
              </button>
            </div>
          </div>
        </div>
        <button class="nav-btn" type="button" @click="logout"><svg class="ico" aria-hidden="true"><use href="#i-logout"></use></svg><span>Log out</span></button>
      </aside>
    </div>
  </div>

  <div v-if="restoreConflicts.length" class="modal-back" @click.self="cancelRestore">
    <form class="modal backup-modal" @submit.prevent="confirmRestore">
      <h2>Projects already here</h2>
      <p class="hint">This backup contains {{ restoreCount }} {{ restoreCount === 1 ? 'project' : 'projects' }}. The ones below are already on this computer. The rest are imported as they are.</p>
      <div v-for="item in restoreConflicts" :key="item.folder" class="choice-block">
        <strong>{{ item.name }}</strong>
        <p class="hint">{{ item.reason }}</p>
        <label class="choice-row"><input type="radio" :name="'restore-' + item.folder" value="overwrite" v-model="restoreChoices[item.folder]"> <span>Overwrite</span></label>
        <label class="choice-row"><input type="radio" :name="'restore-' + item.folder" value="ignore" v-model="restoreChoices[item.folder]"> <span>Ignore</span></label>
        <label class="choice-row"><input type="radio" :name="'restore-' + item.folder" value="keep" v-model="restoreChoices[item.folder]"> <span>Keep both</span></label>
      </div>
      <p v-if="restoreError" class="form-error">{{ restoreError }}</p>
      <div class="modal-actions">
        <button class="btn btn-gold" type="submit" :disabled="restoreBusy || !restoreReady()">Restore</button>
        <button class="btn" type="button" :disabled="restoreBusy" @click="cancelRestore">Cancel</button>
      </div>
    </form>
  </div>

  <div v-if="projectEdit" class="modal-back" @click.self="closeProjectEdit">
    <form class="modal" @submit.prevent="renameProject">
      <template v-if="!projectFolderChoice">
        <h2>Edit project</h2>
        <label class="field">Name<input v-model="projectEditName" maxlength="120" required></label>
        <p class="form-error">{{ projectEditError }}</p>
        <button class="btn btn-gold" type="submit">Save</button>
        <button class="btn" type="button" @click="deleteProject">Delete project</button>
      </template>
      <template v-else>
        <h2>Delete project</h2>
        <p class="hint">{{ projectEdit.name }} will be removed from the app. Keep the project folder, or permanently delete it?</p>
        <p class="form-error">{{ projectEditError }}</p>
        <button class="btn" type="button" @click="confirmDeleteProject(false)">Keep folder</button>
        <button class="btn" type="button" @click="confirmDeleteProject(true)">Delete folder</button>
      </template>
    </form>
  </div>

  <div v-if="projectModal" class="modal-back" @click.self="projectModal = false">
    <form class="modal project-modal" @submit.prevent="createProject">
      <h2>New project</h2>
      <label class="field">Project name<input v-model="projectForm.name" maxlength="25" required></label>
      <label class="field">Project description<textarea v-model="projectForm.description" rows="3" placeholder="What this project is"></textarea></label>
      <label class="field">Intended goals and outcomes<textarea v-model="projectForm.goals" rows="3" placeholder="What this project should achieve"></textarea></label>
      <label class="field">Limitations<textarea v-model="projectForm.limitations" rows="3" placeholder="Limits, constraints, or things to avoid"></textarea></label>
      <label class="field">The agent's role in this project<textarea v-model="projectForm.role" rows="3" placeholder="What the agent should do here"></textarea></label>
      <p class="form-error">{{ projectError }}</p>
      <div class="view-actions">
        <button class="btn btn-gold" type="submit">Create project</button>
        <button class="btn" type="button" @click="cancelProjectModal">Cancel</button>
      </div>
    </form>
  </div>

  <div v-if="treeMenu" class="tree-menu" :style="{ left: treeMenu.x + 'px', top: treeMenu.y + 'px' }" @mousedown.stop @click.stop @contextmenu.prevent>
    <button v-if="treeMenu.node.type==='dir'" type="button" class="tree-menu-btn is-neutral" @click="createSubfolder(treeMenu.node)">New subfolder</button>
    <button v-if="treeMenu.node.path" type="button" class="tree-menu-btn" @click="deleteTreeNode(treeMenu.node)">Delete</button>
  </div>

  <div v-if="authed && termsNeeded" class="modal-back">
    <form class="modal" @submit.prevent="acceptTerms">
      <h2>Terms &amp; Acceptable Use</h2>
      <p class="hint">Agent Danaco is a self-hosted framework. You operate this deployment and remain responsible for lawful use. The software is provided as is, without warranty.</p>
      <p class="hint">Read the <a href="/legal/terms.php" target="_blank" rel="noopener">Terms of Use</a> and <a href="/legal/aup.php" target="_blank" rel="noopener">Acceptable Use Policy</a> (version {{ termsVersion }}).</p>
      <p class="hint">High-risk Annex III uses and prohibited AI practices are out of intended purpose unless you independently ensure legal conformity.</p>
      <button class="btn btn-gold" type="submit">I accept</button>
    </form>
  </div>

</div>
<script src="/assets/app.js"></script>
</body>
</html>
