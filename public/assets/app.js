(function () {
  function downloadButton(attrs) {
    return '<button type="button" class="file-icon file-cite-dl" ' + attrs + ' aria-label="Download"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-download"></use></svg></button>';
  }

  function fileKindOf(name) {
    const ext = String(name || '').split('.').pop().toLowerCase();
    if (ext === 'pdf') return 'pdf';
    if (/^(png|jpe?g|gif|webp|svg)$/.test(ext)) return 'image';
    if (ext === 'md') return 'markdown';
    if (ext === 'csv') return 'csv';
    if (/^(db|sqlite|sqlite3)$/.test(ext)) return 'sqlite';
    if (ext === 'docx') return 'docx';
    if (ext === 'html' || ext === 'htm') return 'html';
    if (ext === 'js' || ext === 'mjs') return 'js';
    if (ext === 'php') return 'php';
    return 'text';
  }

  function citedPath(line) {
    const text = String(line || '').trim().replace(/^`([^`]+)`$/, '$1');
    if (!/^(?:[A-Za-z0-9][A-Za-z0-9._-]*\/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:md|pdf|txt|csv|json|svg|html|png|jpe?g|gif|webp|py|php|js|css)$/.test(text)) {
      return '';
    }
    if (text.indexOf('..') !== -1) return '';
    return text;
  }

  function stripChatTags(src) {
    return String(src || '').replace(/<\/?chat\b[^>]*>/gi, '');
  }

  function stripLeakedThink(src) {
    let text = String(src || '');
    text = text.replace(/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*?<\/(?:think|thinking|reasoning|thought)>/gi, '');
    const closer = /<\/(?:think|thinking|reasoning|thought)>/gi;
    let end = -1;
    let match;
    while ((match = closer.exec(text)) !== null) end = match.index + match[0].length;
    if (end >= 0) text = text.slice(end);
    text = text.replace(/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*$/i, '');
    return text;
  }

  function citeHtml(path, folder) {
    const button = downloadButton('data-download="' + path + '"');
    const name = '<span class="file-cite-name">' + path + '</span>';
    if (fileKindOf(path) === 'image' && /^[a-z0-9][a-z0-9_-]{0,63}$/.test(String(folder || ''))) {
      const src = '/api/workspace/raw?scope=project&amp;folder=' + encodeURIComponent(folder) + '&amp;path=' + encodeURIComponent(path);
      return '<div class="file-cite file-cite-image">' + button + '<img class="file-cite-shot" alt="" src="' + src + '">' + name + '</div>';
    }
    return '<div class="file-cite">' + button + name + '</div>';
  }

  function renderMarkdown(src, folder) {
    const fences = [];
    const cites = [];
    let text = stripLeakedThink(src)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
    text = text.replace(/```([^\n]*)\n([\s\S]*?)```/g, function (_, info, code) {
      if (!String(code || '').trim()) return '\n';
      const label = String(info || '').trim();
      let button = '';
      const named = label.match(/^(?:[A-Za-z0-9][A-Za-z0-9._-]*\/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:md|txt|csv|json|svg|html|py|php|js|css)$/);
      if (named && named[0].indexOf('..') === -1) {
        button = downloadButton('data-name="' + named[0].split('/').pop() + '"');
      } else if (/^(md|markdown)$/i.test(label)) {
        button = downloadButton('data-name="note.md"');
      }
      const body = '<pre class="md-pre"><code>' + code + '</code></pre>';
      fences.push(button ? '<div class="md-file">' + button + body + '</div>' : body);
      return '\n@@FENCE' + (fences.length - 1) + '@@\n';
    });
    text = text.split('\n').map(function (line) {
      const path = citedPath(line);
      if (!path) return line;
      cites.push(citeHtml(path, folder));
      return '@@CITE' + (cites.length - 1) + '@@';
    }).join('\n');
    text = text.replace(/`([^`]+)`/g, '<code class="md-code">$1</code>');
    text = text.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    text = text.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    text = text.replace(/^# (.+)$/gm, '<h1>$1</h1>');
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    text = text.replace(/(?:<li>.*<\/li>\n?)+/g, function (block) {
      return '<ul>' + block + '</ul>';
    });
    text = text.split(/\n{2,}/).map(function (block) {
      if (/^\s*<(h\d|ul|pre|div)/.test(block) || block.indexOf('@@FENCE') !== -1) {
        return block;
      }
      if (block.indexOf('@@CITE') === -1) {
        return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
      }
      return block.split('\n').map(function (line) {
        if (line.indexOf('@@CITE') !== -1) return line;
        if (line.trim() === '') return '';
        return '<p>' + line + '</p>';
      }).join('');
    }).join('');
    return text.replace(/@@FENCE(\d+)@@/g, function (_, index) {
      return fences[Number(index)] || '';
    }).replace(/@@CITE(\d+)@@/g, function (_, index) {
      return cites[Number(index)] || '';
    });
  }

  function start() {
    let treeDrag = null;
    let scrollGeneration = 0;
    const app = Vue.createApp({
      data() {
        const savedCollapse = localStorage.getItem('sidebarCollapsed');
        return {
          booting: true,
          authed: false,
          passwordSet: true,
          password: '',
          loginError: '',
          tab: 'chat',
          mobileNav: false,
          sidebarCollapsed: savedCollapse === null ? window.innerWidth < 1024 : savedCollapse === '1',
          nav: [
            { id: 'chat', label: 'Chat', icon: 'i-chat' },
            { id: 'tasks', label: 'Tasks', icon: 'i-tasks' },
            { id: 'workspace', label: 'Workspace', icon: 'i-workspace' },
            { id: 'agents', label: 'Agents', icon: 'i-agents' },
            { id: 'skills', label: 'Skills', icon: 'i-skills' },
            { id: 'setup', label: 'Setup', icon: 'i-setup' }
          ],
          projects: [],
          projectId: Number(localStorage.getItem('projectId') || 0),
          messages: [],
          context: { used: 0, total: 0 },
          compressing: false,
          compressError: '',
          compressNote: '',
          clearingContext: false,
          draft: '',
          attachments: [],
          sending: false,
          stickToBottom: true,
          chatAutoScrolling: false,
          chatAbort: null,
          chatGeneration: '',
          chatStreamProject: 0,
          userStop: false,
          stopModal: false,
          stopNote: '',
          stopBusy: false,
          stopError: '',
          heldTasks: [],
          runStopping: false,
          toolPending: false,
          streamEnded: false,
          steerText: '',
          slowTimer: null,
          slowReply: false,
          waitPhase: '',
          answerStarted: false,
          live: '',
          liveAgent: 'orchestrator',
          liveDetail: { thinking: [], tools: [] },
          traceOpen: {},
          delegation: null,
          actionPrompt: null,
          pendingActions: [],
          termsNeeded: false,
          termsVersion: '1',
          complianceIntendedPurpose: '',
          complianceDisclosure: '',
          complianceEvents: [],
          complianceForm: {
            ai_act_applies: '',
            responsible_person: '',
            intended_use: '',
            compliance_logging: false,
            hitl_external_actions: false,
            retention_days: 90
          },
          complianceMessage: '',
          complianceError: false,
          disclosureCopied: false,
          streamError: '',
          chatQuery: '',
          chatHits: 0,
          chatHitIndex: 0,
          tasks: [],
          taskDraft: '',
          openTask: null,
          taskEdit: {},
          canDrag: window.matchMedia('(pointer: fine)').matches,
          dragId: null,
          columns: [
            { id: 'in_progress', label: 'In progress', hint: 'Being worked on, or scheduled' },
            { id: 'review', label: 'Review', hint: 'Finished run-once tasks waiting for you' }
          ],
          finishedOpen: false,
          taskError: '',
          taskLoading: false,
          agentNames: ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer', 'human'],
          tree: [],
          filePath: '',
          fileName: '',
          fileScope: '',
          fileFolder: '',
          fileRel: '',
          fileIsDir: false,
          fileContent: '',
          fileDraft: '',
          fileEditing: false,
          fileError: '',
          showingSearch: false,
          vaultOpen: false,
          restoredVault: {},
          backups: [],
          trash: [],
          wikiWhich: 'central',
          wikiFiles: [],
          wikiName: 'projects_index.md',
          wikiContent: '',
          wikiQuery: '',
          wikiResult: '',
          searchHits: [],
          fileMarkdown: false,
          filePreviewUrl: '',
          fileLoad: 0,
          fileKey: '',
          treeMenu: null,
          treeError: '',
          treeOverKey: '',
          structuredData: null,
          structuredLoading: false,
          structuredTableName: '',
          agents: [],
          logs: [],
          loopLogs: [],
          logAgent: '',
          logLatest: {},
          logLatestProject: -1,
          openLogs: {},
          skills: [],
          selectedSkillId: 0,
          skillCreating: false,
          skillEditing: false,
          skillForm: {},
          skillError: '',
          settings: {},
          hardware: null,
          setupMessage: '',
          restarting: false,
          restartError: '',
          providerTestBusy: false,
          providerTestMessage: '',
          providerTestError: false,
          backupFolders: [],
          backupBusy: false,
          backupError: '',
          restoreBusy: false,
          restoreMessage: '',
          restoreError: '',
          restoreToken: '',
          restoreCount: 0,
          restoreConflicts: [],
          restoreChoices: {},
          updateInfo: null,
          updateBusy: false,
          updateMessage: '',
          ollamaModels: [],
          ollamaError: '',
          ollamaProgress: '',
          ollamaPullName: '',
          ollamaBusy: false,
          emailAccounts: [],
          emailMessage: '',
          emailError: false,
          emailForm: { project_id: 0, from_name: '', from_email: '', smtp_host: '', smtp_port: 587, smtp_security: 'tls', username: '', password: '' },
          ftpAccounts: [],
          ftpMessage: '',
          ftpError: false,
          ftpForm: { project_id: 0, label: '', host: '', port: 21, security: 'ftp', username: '', password: '' },
          mcpServers: [],
          mcpMessage: '',
          mcpError: false,
          mcpBusyId: 0,
          mcpForm: { label: '', target: '', env: '' },
          mcpPicks: [
            { label: 'GitHub', target: 'https://api.githubcopilot.com/mcp/', env: 'AUTHORIZATION=Bearer ' },
            { label: 'Google Drive', target: 'https://drivemcp.googleapis.com/mcp/v1', env: '' },
            { label: 'Google Calendar', target: 'https://calendarmcp.googleapis.com/mcp/v1', env: '' },
            { label: 'Slack', target: 'https://mcp.slack.com/mcp', env: '' },
            { label: 'Postgres / Database Toolbox', target: 'npx -y @toolbox-sdk/server --prebuilt=postgres', env: 'POSTGRES_HOST=127.0.0.1\nPOSTGRES_PORT=5432\nPOSTGRES_DATABASE=\nPOSTGRES_USER=\nPOSTGRES_PASSWORD=' },
            { label: 'Todoist', target: 'https://ai.todoist.net/mcp', env: '' },
            { label: 'PayPal', target: 'npx -y @paypal/mcp --tools=all', env: 'PAYPAL_ACCESS_TOKEN=\nPAYPAL_ENVIRONMENT=SANDBOX' }
          ],
          cloudKeyDraft: '',
          modelLoad: null,
          awaitingModel: false,
          passwordForm: { current: '', next: '', confirm: '' },
          passwordMessage: '',
          systemControlPassword: '',
          systemControlMessage: '',
          systemControlError: false,
          projectModal: false,
          projectError: '',
          projectForm: { name: '', description: '', goals: '', limitations: '', role: '' },
          projectEdit: null,
          projectEditName: '',
          projectEditError: '',
          projectFolderChoice: false,
          projectOpener: null,
          pollTimer: null,
          healthTick: 0
        };
      },
      computed: {
        currentLabel() {
          if (this.tab === 'compliance') return 'Compliance';
          const item = this.nav.find((entry) => entry.id === this.tab);
          return item ? item.label : 'Agent';
        },
        currentProject() {
          return this.projects.find((project) => project.id === this.projectId) || null;
        },
        showModelLoader() {
          const load = this.modelLoad;
          if (!load || load.ready !== false) return false;
          if (load.error) return true;
          if (this.sending || this.awaitingModel || load.scratch === false) return false;
          return true;
        },
        chatLocked() {
          if (this.awaitingModel) return true;
          return this.showModelLoader;
        },
        agentName() {
          const name = String(this.settings.agent_name || '').trim();
          return name || 'Agent';
        },
        healthBars() {
          const usage = (this.hardware && this.hardware.usage) || {};
          const details = usage.details || {};
          const number = function (value) {
            return String(Math.round(value / 1024));
          };
          return [
            { key: 'cpu', label: 'CPU' },
            { key: 'ram', label: 'RAM' },
            { key: 'vram', label: 'VRAM' },
            { key: 'disk', label: 'HDD' }
          ].map(function (row) {
            const n = Number(usage[row.key]);
            const ok = Number.isFinite(n);
            const value = ok ? Math.max(0, Math.min(100, n)) : 0;
            const detail = details[row.key];
            const text = ok ? Math.round(value) + '%' : '—';
            return {
              label: row.label,
              value: value,
              text: detail && Number(detail.total_mb) > 0
                ? number(Number(detail.used_mb)) + '/' + number(Number(detail.total_mb)) + 'GB (' + text + ')'
                : text
            };
          });
        },
        sortedSkills() {
          const byName = function (a, b) {
            return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' });
          };
          const own = this.skills.filter(function (skill) { return skill.created_by !== 'system'; }).sort(byName);
          const shipped = this.skills.filter(function (skill) { return skill.created_by === 'system'; }).sort(byName);
          return own.concat(shipped);
        },
        selectedSkill() {
          return this.skills.find((skill) => skill.id === this.selectedSkillId) || null;
        },
        chatFontPx() {
          const n = Number(this.settings.chat_font_size);
          if (!Number.isFinite(n)) return 16;
          return Math.max(13, Math.min(22, Math.round(n)));
        },
        mainAgent() {
          return this.agents.find((agent) => agent.name === 'orchestrator') || null;
        },
        consultant() {
          return this.agents.find((agent) => agent.name === 'consultant') || null;
        },
        subAgents() {
          return this.agents.filter((agent) => agent.name !== 'orchestrator' && agent.name !== 'consultant');
        },
        flowInset() {
          const count = Math.max(this.subAgents.length, 1);
          const gaps = Math.max(count - 1, 0) * 10;
          return 'calc((100% - ' + gaps + 'px) / ' + (count * 2) + ' - 1px)';
        },
        flowLive() {
          return this.subAgents.some((agent) => agent.status === 'thinking' || agent.status === 'executing_tool')
            || !!(this.mainAgent && (this.mainAgent.status === 'thinking' || this.mainAgent.status === 'executing_tool'));
        },
        agentsBusy() {
          const projectId = this.projectId || 0;
          return this.agents.some((agent) => this.agentBusy(agent) && (agent.project_id || 0) === projectId);
        },
        stopSettled() {
          return !this.agentsBusy && !this.runStopping && !this.sending;
        },
        thinkingSpinner() {
          return (this.sending || this.awaitingModel) && !this.answerStarted && !this.streamError;
        },
        thinkingLabel() {
          if (this.toolPending) return 'Calling a tool';
          if (!this.thinkingSpinner) return 'Working';
          if (this.waitPhase === 'scheduled') return 'Waiting (agent busy with scheduled task)';
          if (!this.slowReply) return 'Thinking';
          let why = '';
          if (this.waitPhase === 'compressing') why = 'compressing context';
          else if (this.awaitingModel || (this.modelLoad && this.modelLoad.ready === false && !this.modelLoad.error)) why = 'loading model';
          return why ? 'Taking longer than expected (' + why + ')' : 'Taking longer than expected';
        },
        showCpu() {
          const settings = this.settings || {};
          if (settings.model_mode === 'cpu_failsafe') return true;
          return settings.cpu_fallback === '1';
        },
        fileKind() {
          if (this.fileIsDir) return 'dir';
          return fileKindOf(this.fileName || this.fileRel);
        },
        filePreview() {
          return this.fileKind === 'pdf' || this.fileKind === 'image';
        },
        structuredKind() {
          return ['csv', 'sqlite', 'docx'].includes(this.fileKind) ? this.fileKind : '';
        },
        canTrashWorkspace() {
          return !!(this.fileName && this.fileRel);
        },
        selectedStructuredTable() {
          if (!this.structuredData || this.structuredKind !== 'sqlite') return null;
          return this.structuredData.tables.find((table) => table.name === this.structuredTableName) || this.structuredData.tables[0] || null;
        }
      },
      watch: {
        booting(now) {
          if (now || !this.authed || this.tab !== 'chat') return;
          this.scrollChat(true);
        },
        'settings.model_mode'(mode) {
          if (mode === 'cloud') this.settings.cloud_api_use = 'main';
        },
        'settings.cloud_api_use'(use) {
          if (use === 'consultant' && this.settings.model_mode === 'cloud') {
            this.settings.model_mode = 'local';
          }
        },
        messages: {
          handler() {
            if (this.tab === 'chat' && this.chatQuery.trim()) this.queueChatSearch(false);
            this.queueCitedFileCheck();
          },
          deep: true
        },
        live() {
          if (this.chatQuery.trim()) this.queueChatSearch(false);
          this.queueCitedFileCheck();
        },
        sending(now) {
          if (!now) this.queueCitedFileCheck();
        },
        projectId() {
          this.queueCitedFileCheck();
        },
        traceOpen: {
          handler() {
            if (this.chatQuery.trim()) this.queueChatSearch(false);
          },
          deep: true
        }
      },
      mounted() {
        window.addEventListener('hashchange', () => this.onHash());
        window.addEventListener('visibilitychange', () => {
          if (document.hidden || !this.authed || this.tab !== 'agents') return;
          this.refreshStatus(true).catch(function () {});
          this.refreshLogs(true).catch(function () {});
          this.refreshLoop(true).catch(function () {});
        });
        window.addEventListener('mousedown', (event) => {
          if (!this.treeMenu || event.button !== 0) return;
          const menu = document.querySelector('.tree-menu');
          if (menu && menu.contains(event.target)) return;
          this.treeMenu = null;
        });
        window.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') this.treeMenu = null;
        });
        window.addEventListener('dragend', () => {
          treeDrag = null;
          this.treeOverKey = '';
        });
        this.syncHash();
        this.checkSession();
        if ('serviceWorker' in navigator) {
          navigator.serviceWorker.getRegistrations().then(function (regs) {
            if (!regs.length) return;
            Promise.all(regs.map(function (reg) { return reg.unregister(); })).then(function () {
              if (navigator.serviceWorker.controller) location.reload();
            });
          }).catch(function () {});
        }
      },
      methods: {
        md(src) {
          const project = this.currentProject;
          return renderMarkdown(src, project && project.folder_name ? project.folder_name : 'general');
        },
        syncHash() {
          let id = (location.hash || '#chat').replace('#', '');
          if (id === 'tools') {
            id = 'setup';
            if (location.hash !== '#setup') location.hash = 'setup';
          }
          const allowed = this.nav.some((item) => item.id === id) || id === 'compliance';
          this.tab = allowed ? id : 'chat';
        },
        onHash() {
          this.treeMenu = null;
          this.syncHash();
          this.mobileNav = false;
          if (this.authed) this.refreshTab();
        },
        setTab(id) {
          this.tab = id;
          this.mobileNav = false;
          this.refreshTab();
          if (location.hash !== '#' + id) location.hash = id;
        },
        toggleSidebar() {
          this.sidebarCollapsed = !this.sidebarCollapsed;
          localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
        },
        async api(path, options) {
          const opts = options || {};
          const res = await fetch(path, {
            method: opts.method || 'GET',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json' },
            body: opts.body === undefined ? undefined : JSON.stringify(opts.body)
          });
          const data = await res.json().catch(function () { return {}; });
          if (res.status === 401) {
            if (!opts.quiet) this.authed = false;
            throw new Error(data.error || 'Unauthorized');
          }
          if (!res.ok) throw new Error(data.error || 'Request failed');
          return data;
        },
        async checkSession() {
          try {
            const data = await this.api('/api/session');
            this.authed = !!data.authed;
            this.passwordSet = !!data.password_set;
            this.termsVersion = data.terms_version || '1';
            this.termsNeeded = !!data.authed && !data.terms_accepted;
            if (this.authed) await this.loadAll();
          } catch (error) {
            this.loginError = error.message;
          } finally {
            this.booting = false;
          }
        },
        async login() {
          this.loginError = '';
          try {
            const res = await fetch('/api/login', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ password: this.password })
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              this.loginError = data.error || 'Could not sign in.';
              return;
            }
            this.password = '';
            this.authed = true;
            await this.loadAll();
            const session = await this.api('/api/session');
            this.termsVersion = session.terms_version || '1';
            this.termsNeeded = !session.terms_accepted;
          } catch (error) {
            this.loginError = 'Could not reach the server.';
          }
        },
        async logout() {
          try {
            await this.api('/api/logout', { method: 'POST', body: {} });
          } catch (error) {
            this.authed = false;
          }
          this.authed = false;
          this.termsNeeded = false;
          this.mobileNav = false;
          this.stopPoll();
        },
        async loadAll() {
          const [projects, setup, skills, status] = await Promise.all([
            this.api('/api/projects'),
            this.api('/api/setup'),
            this.api('/api/skills'),
            this.api('/api/agents/status')
          ]);
          this.projects = projects.projects;
          if (this.projectId !== 0 && !this.projects.some((project) => project.id === this.projectId)) {
            this.projectId = 0;
          }
          this.applySettings(setup);
          this.skills = skills.skills;
          this.agents = status.agents;
          await this.refreshModelLoad();
          await this.refreshTab();
          this.startPoll();
        },
        applySettings(payload) {
          this.settings = payload.settings || {};
          this.hardware = payload.hardware || null;
          if (this.settings.cloud_max_turns) this.settings.cloud_max_turns = Number(this.settings.cloud_max_turns);
          if (typeof this.settings.terms_accepted === 'boolean') {
            this.termsNeeded = !this.settings.terms_accepted;
          }
        },
        async refreshTab() {
          if (!this.authed) return;
          if (this.tab === 'chat') await this.reloadMessages();
          if (this.tab === 'tasks') await this.reloadTasks();
          if (this.tab === 'workspace') await this.loadTree();
          if (this.tab === 'agents') {
            await this.refreshLogs();
            await this.refreshLoop();
            await this.refreshPendingActions();
          }
          if (this.tab === 'skills') {
            this.skills = (await this.api('/api/skills')).skills;
            this.ensureSkillSelection();
          }
          if (this.tab === 'setup') {
            this.loadUpdate();
            this.applySettings(await this.api('/api/setup'));
            await this.loadEmailAccounts();
            await this.loadFtpAccounts();
            await this.loadMcpServers();
            await this.loadOllamaModels();
          }
          if (this.tab === 'compliance') {
            await this.loadCompliance();
          }
          await this.refreshStatus();
        },
        async onProjectChange() {
          localStorage.setItem('projectId', String(this.projectId || 0));
          this.messages = [];
          this.awaitingModel = false;
          this.stickToBottom = true;
          this.attachments = [];
          this.clearFilePreview();
          this.filePath = '';
          this.fileName = '';
          this.fileRel = '';
          this.fileIsDir = false;
          this.fileContent = '';
          this.fileEditing = false;
          this.fileError = '';
          this.showingSearch = false;
          this.chatQuery = '';
          this.chatHits = 0;
          this.chatHitIndex = 0;
          this.compressError = '';
          this.live = '';
          this.liveDetail = { thinking: [], tools: [] };
          this.delegation = null;
          this.streamError = '';
          clearTimeout(this._chatSearchTimer);
          await this.refreshTab();
        },
        chooseProject(id) {
          const next = Number(id) || 0;
          const same = (this.projectId || 0) === next;
          this.mobileNav = false;
          if (same) {
            if (this.tab !== 'chat') this.setTab('chat');
            return;
          }
          this.projectId = next;
          if (this.tab !== 'chat') {
            this.tab = 'chat';
            if (location.hash !== '#chat') location.hash = 'chat';
          }
          this.onProjectChange();
        },
        openProjectModal() {
          this.projectError = '';
          this.projectForm = { name: '', description: '', goals: '', limitations: '', role: '' };
          this.projectModal = true;
          this.mobileNav = false;
        },
        openProjectEdit(project) {
          this.projectEdit = project;
          this.projectEditName = project.name;
          this.projectEditError = '';
          this.projectFolderChoice = false;
          this.mobileNav = false;
        },
        closeProjectEdit() {
          this.projectEdit = null;
          this.projectFolderChoice = false;
        },
        async renameProject() {
          if (!this.projectEdit || this.projectFolderChoice) return;
          this.projectEditError = '';
          const previous = this.projectEdit;
          try {
            const data = await this.api('/api/projects/' + this.projectEdit.id + '/rename', {
              method: 'POST',
              body: { name: this.projectEditName }
            });
            const next = data.project;
            const index = this.projects.findIndex((item) => item.id === next.id);
            if (index >= 0) this.projects.splice(index, 1, next);
            this.projects.sort((a, b) => a.name.localeCompare(b.name));
            if (previous && next && this.fileScope === 'project' && this.fileFolder === previous.folder_name && next.folder_name && next.folder_name !== previous.folder_name) {
              const oldDesc = previous.folder_name + '_desc.md';
              const newDesc = next.folder_name + '_desc.md';
              if (this.fileName === oldDesc) this.fileName = newDesc;
              if (this.fileRel === 'wiki/' + oldDesc) this.fileRel = 'wiki/' + newDesc;
              if (typeof this.filePath === 'string' && this.filePath.indexOf(previous.folder_name + '/') === 0) {
                this.filePath = next.folder_name + this.filePath.slice(previous.folder_name.length);
                this.filePath = this.filePath.replace('wiki/' + oldDesc, 'wiki/' + newDesc);
              }
              this.fileFolder = next.folder_name;
            }
            this.closeProjectEdit();
            await this.loadTree();
          } catch (error) {
            this.projectEditError = error.message;
          }
        },
        deleteProject() {
          if (!this.projectEdit) return;
          this.projectEditError = '';
          this.projectFolderChoice = true;
        },
        async confirmDeleteProject(removeFolder) {
          const project = this.projectEdit;
          if (!project) return;
          this.projectEditError = '';
          try {
            await this.api('/api/projects/' + project.id + '/delete', { method: 'POST', body: { folder: !!removeFolder } });
            this.projects = this.projects.filter((item) => item.id !== project.id);
            this.closeProjectEdit();
            if (this.projectId === project.id) {
              this.projectId = 0;
              await this.onProjectChange();
            }
          } catch (error) {
            this.projectEditError = error.message;
          }
        },
        projectBrief() {
          const form = this.projectForm;
          const description = String(form.description || '').trim();
          const goals = String(form.goals || '').trim();
          const limitations = String(form.limitations || '').trim();
          const role = String(form.role || '').trim();
          const parts = [];
          if (goals) parts.push('Intended goals and outcomes:\n' + goals);
          if (limitations) parts.push('Limitations:\n' + limitations);
          if (role) parts.push('Your role in this project:\n' + role);
          let text = 'We are now starting a new project.';
          if (description) text += ' ' + description;
          if (parts.length) text += '\n\n' + parts.join('\n\n');
          text += '\n\nAsk me simple questions to help me outline the project systematically';
          return text;
        },
        async createProject() {
          this.projectError = '';
          try {
            const data = await this.api('/api/projects', { method: 'POST', body: this.projectForm });
            this.projects.push(data.project);
            this.projects.sort((a, b) => a.name.localeCompare(b.name));
            this.steerText = '';
            if (this.sending) this.stopRun();
            this.projectId = data.project.id;
            this.projectModal = false;
            this.live = '';
            this.liveAgent = 'orchestrator';
            this.streamError = '';
            this.delegation = null;
            this.draft = '';
            this.attachments = [];
            if (this.tab !== 'chat') this.setTab('chat');
            this.projectOpener = {
              projectId: data.project.id,
              text: this.projectBrief(),
              saved: false
            };
            await this.persistProjectOpener();
            await this.onProjectChange();
            await this.refreshModelLoad();
            await this.maybeSendProjectOpener();
          } catch (error) {
            this.projectError = error.message;
          }
        },
        contextWindow() {
          const total = this.context.total || 0;
          if (total <= 0) return 0;
          return Math.max(256, Math.floor(total * 0.8));
        },
        contextFull() {
          const window = this.contextWindow();
          return window > 0 && (this.context.used || 0) >= window;
        },
        contextDash() {
          const length = 2 * Math.PI * 14.75;
          const window = this.contextWindow();
          const ratio = window > 0 ? Math.min(1, (this.context.used || 0) / window) : 0;
          return (ratio * length) + ' ' + length;
        },
        contextTip() {
          const used = Math.round(this.context.used || 0).toLocaleString();
          const total = Math.round(this.context.total || 0).toLocaleString();
          return used + ' / ' + total + ' tokens';
        },
        async compressContext() {
          if (this.compressing) return;
          this.compressing = true;
          this.compressError = '';
          this.compressNote = '';
          try {
            const data = await this.api('/api/context/compress', { method: 'POST', body: { project_id: this.projectId || 0 } });
            this.context = { used: Number(data.used) || 0, total: Number(data.total) || 0 };
            if (data.deferred) {
              this.compressNote = 'The current action will finish first. Compression runs after that, and the work continues.';
              return;
            }
            await this.reloadMessages();
          } catch (error) {
            this.compressError = error.message;
          } finally {
            this.compressing = false;
          }
        },
        async clearContext() {
          if (this.clearingContext || this.compressing) return;
          if (!window.confirm('Clear this chat and start fresh? The conversation is removed. Wiki notes stay. This cannot be undone.')) return;
          this.clearingContext = true;
          this.compressError = '';
          this.compressNote = '';
          try {
            await this.api('/api/context/clear', { method: 'POST', body: { project_id: this.projectId || 0 } });
            this.messages = [];
            this.live = '';
            this.liveDetail = { thinking: [], tools: [] };
            this.streamError = '';
            this.context = { used: 0, total: this.context.total || 0 };
            window.location.reload();
          } catch (error) {
            this.compressError = error.message;
            this.clearingContext = false;
          }
        },
        agentBusy(agent) {
          return !!agent && (agent.status === 'thinking' || agent.status === 'executing_tool');
        },
        agentWork(agent) {
          if (!agent) return '';
          if (agent.status === 'inactive') return agent.note || 'Inactive';
          if (agent.status === 'idle') return 'Waiting';
          if (this.agentBusy(agent)) return this.agentProject(agent);
          const log = this.logLatest[agent.name] || this.logs.find((item) => item.agent_name === agent.name);
          if (!log) return agent.label;
          return this.logPreview(log.content);
        },
        agentProject(agent) {
          if (!agent || !agent.project_id) return 'General chat';
          return this.projectName(agent.project_id);
        },
        logProject(log) {
          if (!log || !log.project_id) return 'General chat';
          return this.projectName(log.project_id);
        },
        pickLogAgent(name) {
          this.logAgent = this.logAgent === name ? '' : name;
          this.refreshLogs();
        },
        openProviderSetup() {
          this.setTab('setup');
          this.$nextTick(() => {
            const box = document.getElementById('provider-box');
            const field = document.getElementById('provider-key');
            if (box) box.scrollIntoView({ block: 'center' });
            if (field) field.focus();
          });
        },
        loopKind(type) {
          if (type === 'model') return 'Model';
          if (type === 'retry') return 'Retry';
          if (type === 'filter') return 'Filter';
          if (type === 'steer') return 'Steer';
          if (type === 'tool') return 'Tool';
          if (type === 'context') return 'Context';
          if (type === 'stop') return 'Stop';
          if (type === 'process') return 'Process';
          return type || 'Note';
        },
        logKind(type) {
          if (type === 'tool_call') return 'Tool';
          if (type === 'thinking') return 'Thinking';
          if (type === 'output') return 'Result';
          if (type === 'error') return 'Error';
          return type || 'Note';
        },
        logPreview(text) {
          const line = String(text || '').replace(/\s+/g, ' ').trim();
          if (!line) return 'Empty';
          return line.length > 96 ? line.slice(0, 96) + '…' : line;
        },
        toggleLog(id) {
          this.openLogs = Object.assign({}, this.openLogs, { [id]: !this.openLogs[id] });
        },
        scheduledRaw(msg) {
          if (!msg) return '';
          const text = String(msg.content || '').trim();
          if (msg.agent_name === 'task' || text.startsWith('Scheduled task:')) return text;
          if (!text.startsWith('{')) return '';
          try {
            const data = JSON.parse(text);
            const inner = String(data && data.content || '').trim();
            if (inner.startsWith('Scheduled task:')) return inner;
          } catch (error) {
            return '';
          }
          return '';
        },
        scheduledCard(title, text) {
          const name = String(title || '').trim();
          if (!name) return null;
          const projectId = Number(this.projectId) || 0;
          const body = String(text || '').split('\n').slice(1).join('\n').trim();
          const matches = (this.tasks || []).filter((task) => {
            if (String(task.title || '').trim() !== name) return false;
            return !projectId || Number(task.project_id) === projectId;
          });
          if (!matches.length) return null;
          matches.sort((a, b) => {
            const aBody = body && String(a.description || '').trim() === body ? 1 : 0;
            const bBody = body && String(b.description || '').trim() === body ? 1 : 0;
            if (aBody !== bBody) return bBody - aBody;
            const aRun = a.status === 'in_progress' ? 1 : 0;
            const bRun = b.status === 'in_progress' ? 1 : 0;
            if (aRun !== bRun) return bRun - aRun;
            return b.id - a.id;
          });
          return matches[0];
        },
        scheduledText(msg) {
          const text = this.scheduledRaw(msg);
          if (!text) return '';
          const first = text.split('\n')[0].replace(/^Scheduled task:\s*/, '').trim();
          const numbered = first.match(/^#(\d+)\s+(.+)$/);
          let card = numbered ? ('#' + numbered[1] + ' ' + numbered[2]) : first;
          if (!numbered) {
            const task = this.scheduledCard(first, text);
            if (task) card = '#' + task.id + ' ' + (task.title || first);
          }
          return 'Scheduled task started in the background, which might slow this chat down\n' + card;
        },
        agentLabel(name, markCpu) {
          let label = '';
          if (name === 'orchestrator' || name === 'assistant') label = 'Main agent';
          else {
            const text = String(name || '');
            if (!text) return '';
            label = text.charAt(0).toUpperCase() + text.slice(1);
          }
          if (markCpu && (name === 'orchestrator' || name === 'assistant')) return label + ' (CPU)';
          return label;
        },
        queueChatSearch(scrollFirst) {
          clearTimeout(this._chatSearchTimer);
          const scroll = scrollFirst === true;
          this._chatSearchTimer = setTimeout(() => {
            this.$nextTick(() => this.markChatHits(scroll));
          }, scroll ? 160 : 40);
        },
        markChatHits(scrollFirst) {
          const root = this.$refs.transcript;
          if (!root) {
            this.chatHits = 0;
            this.chatHitIndex = 0;
            return;
          }
          root.querySelectorAll('mark.chat-hit').forEach((mark) => {
            mark.replaceWith(document.createTextNode(mark.textContent || ''));
          });
          root.normalize();
          const query = this.chatQuery.trim();
          if (!query) {
            this.chatHits = 0;
            this.chatHitIndex = 0;
            return;
          }
          const needle = query.toLowerCase();
          const nodes = [];
          const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
          let node = walker.nextNode();
          while (node) {
            nodes.push(node);
            node = walker.nextNode();
          }
          nodes.forEach((textNode) => {
            const source = textNode.nodeValue || '';
            const lower = source.toLowerCase();
            if (!lower.includes(needle)) return;
            const frag = document.createDocumentFragment();
            let cursor = 0;
            let found = lower.indexOf(needle);
            while (found !== -1) {
              if (found > cursor) frag.appendChild(document.createTextNode(source.slice(cursor, found)));
              const mark = document.createElement('mark');
              mark.className = 'chat-hit';
              mark.textContent = source.slice(found, found + query.length);
              frag.appendChild(mark);
              cursor = found + query.length;
              found = lower.indexOf(needle, cursor);
            }
            if (cursor < source.length) frag.appendChild(document.createTextNode(source.slice(cursor)));
            textNode.parentNode.replaceChild(frag, textNode);
          });
          const marks = root.querySelectorAll('mark.chat-hit');
          this.chatHits = marks.length;
          if (!marks.length) {
            this.chatHitIndex = 0;
            return;
          }
          if (scrollFirst || this.chatHitIndex >= marks.length) this.chatHitIndex = 0;
          this.paintChatHit(!!scrollFirst);
        },
        paintChatHit(scroll) {
          const root = this.$refs.transcript;
          if (!root) return;
          const marks = root.querySelectorAll('mark.chat-hit');
          marks.forEach((mark, index) => mark.classList.toggle('is-current', index === this.chatHitIndex));
          const current = marks[this.chatHitIndex];
          if (!scroll || !current) return;
          this.stickToBottom = false;
          current.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
        clearChatSearch() {
          this.chatQuery = '';
          clearTimeout(this._chatSearchTimer);
          this.$nextTick(() => this.markChatHits(false));
        },
        stepChatHit(dir) {
          clearTimeout(this._chatSearchTimer);
          this.$nextTick(() => {
            this.markChatHits(false);
            if (!this.chatHits) return;
            this.chatHitIndex = (this.chatHitIndex + dir + this.chatHits) % this.chatHits;
            this.paintChatHit(true);
          });
        },
        async reloadMessages() {
          const data = await this.api('/api/messages?project_id=' + (this.projectId || 0));
          this.messages = Array.isArray(data.messages) ? data.messages : [];
          if (this.messages.some((msg) => this.scheduledRaw(msg))) await this.reloadTasks();
          if (data.context) this.context = { used: Number(data.context.used) || 0, total: Number(data.context.total) || 0 };
          const pending = this.projectOpener;
          if (pending && pending.projectId === (this.projectId || 0) && !this.messages.length) {
            this.messages.push({ id: 'local-opener', role: 'user', agent_name: 'human', content: pending.text });
          }
          this.scrollChat();
        },
        async persistProjectOpener() {
          const pending = this.projectOpener;
          if (!pending || pending.saved || pending.saving) return;
          if ((this.projectId || 0) !== pending.projectId) return;
          pending.saving = true;
          try {
            await this.api('/api/messages', { method: 'POST', body: { project_id: pending.projectId, message: pending.text } });
            pending.saved = true;
          } catch (error) {
            if (this.tab === 'chat') this.streamError = error.message;
          } finally {
            pending.saving = false;
          }
        },
        async maybeSendProjectOpener() {
          const pending = this.projectOpener;
          if (!pending || this.sending) return;
          if ((this.projectId || 0) !== pending.projectId) return;
          await this.persistProjectOpener();
          if (this.projectOpener !== pending || !pending.saved) return;
          if (this.tab !== 'chat') return;
          if (!this.modelLoad || this.modelLoad.ready !== true) await this.refreshModelLoad();
          if (this.projectOpener !== pending || this.sending || !this.modelLoad || this.modelLoad.ready !== true) return;
          if (this.showModelLoader) return;
          if ((this.projectId || 0) !== pending.projectId || this.tab !== 'chat') return;
          this.projectOpener = null;
          await this.runChat(pending.text, { saved: true });
        },
        scrollChat(force) {
          if (force) this.stickToBottom = true;
          if (!this.stickToBottom) return;
          const generation = ++scrollGeneration;
          const pin = (left) => {
            if (generation !== scrollGeneration || !this.stickToBottom) return;
            const el = this.$refs.transcript;
            if (!el) {
              if (left > 0) this.$nextTick(() => pin(left - 1));
              return;
            }
            this.chatAutoScrolling = true;
            el.scrollTop = el.scrollHeight;
            this.chatAutoScrolling = false;
            const gap = el.scrollHeight - el.scrollTop - el.clientHeight;
            if (left > 0 && (el.clientHeight === 0 || gap > 2)) requestAnimationFrame(() => pin(left - 1));
          };
          this.$nextTick(() => pin(8));
        },
        onTranscriptScroll() {
          if (this.chatAutoScrolling) return;
          const el = this.$refs.transcript;
          if (!el) return;
          const gap = el.scrollHeight - el.scrollTop - el.clientHeight;
          this.stickToBottom = gap < 64;
        },
        async onAttach(event) {
          const input = event.target;
          const files = [...input.files];
          input.value = '';
          if (!files.length) return;
          this.streamError = '';
          for (const file of files) {
            const body = new FormData();
            body.append('project_id', String(this.projectId || 0));
            body.append('file', file);
            try {
              const res = await fetch('/api/workspace/upload', { method: 'POST', body: body });
              const data = await res.json().catch(function () { return {}; });
              if (res.status === 401) {
                this.authed = false;
                return;
              }
              if (!res.ok) throw new Error(data.error || 'Could not upload the file.');
              this.attachments.push({ name: data.name, path: data.path });
            } catch (error) {
              this.streamError = error.message || 'Could not upload the file.';
            }
          }
        },
        removeAttachment(file) {
          this.attachments = this.attachments.filter((item) => item.path !== file.path);
        },
        fitComposer() {
          this.$nextTick(() => {
            const el = this.$refs.composer;
            if (!el) return;
            const min = 42;
            el.style.height = min + 'px';
            const limit = 160;
            const full = el.scrollHeight;
            el.style.height = Math.min(Math.max(full, min), limit) + 'px';
            el.style.overflowY = full > limit ? 'auto' : 'hidden';
          });
        },
        async send() {
          if (this.chatLocked) return;
          this.projectOpener = null;
          if (!this.draft.trim() && !this.attachments.length) return;
          let message = this.draft.trim();
          if (this.attachments.length) {
            const lines = this.attachments.map((file) => '- ' + file.path).join('\n');
            message = (message ? message + '\n\n' : '') + 'Attached files:\n' + lines;
          }
          this.draft = '';
          this.attachments = [];
          this.fitComposer();
          this.messages.push({ id: 'local-' + Date.now(), role: 'user', agent_name: 'human', content: message });
          this.scrollChat(true);
          if (this.sending) {
            this.steerText = message;
            this.stopRun();
            return;
          }
          await this.runChat(message);
        },
        async retryMessage(msg) {
          if (this.sending || this.chatLocked || !msg || !msg.failed) return;
          const index = this.messages.findIndex((item) => item.id === msg.id);
          let user = null;
          for (let i = index - 1; i >= 0; i--) {
            if (this.messages[i].role === 'user' && !this.scheduledText(this.messages[i])) {
              user = this.messages[i];
              break;
            }
          }
          if (!user || !String(user.content || '').trim()) return;
          this.streamError = '';
          await this.runChat(String(user.content), {
            retry: Number(user.id) > 0,
            failedId: Number(msg.id) > 0 ? Number(msg.id) : 0
          });
        },
        retryLast() {
          const failed = [...this.messages].reverse().find((msg) => msg.failed);
          if (failed) {
            this.retryMessage(failed);
            return;
          }
          const users = this.messages.filter((msg) => msg.role === 'user' && !this.scheduledText(msg));
          const user = users[users.length - 1];
          if (!user || this.sending || this.chatLocked) return;
          this.streamError = '';
          this.runChat(String(user.content), { retry: Number(user.id) > 0, failedId: 0 });
        },
        async runChat(message, retryOpts) {
          const chatProject = this.projectId || 0;
          const runId = Date.now() + Math.random();
          this.chatRun = runId;
          this.chatStreamProject = chatProject;
          this.awaitingModel = false;
          this.sending = true;
          this.answerStarted = false;
          this.userStop = false;
          this.toolPending = false;
          this.streamEnded = false;
          this.live = '';
          this.liveDetail = { thinking: [], tools: [] };
          this.liveAgent = 'orchestrator';
          this.streamError = '';
          this.slowReply = false;
          this.waitPhase = '';
          this.delegation = null;
          this.armSlowTimer();
          this.scrollChat();
          const generation = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
          this.chatGeneration = generation;
          const controller = new AbortController();
          this.chatAbort = controller;
          let aborted = false;
          try {
            const res = await fetch('/api/chat', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                project_id: chatProject,
                message: message,
                generation: generation,
                retry: !!(retryOpts && retryOpts.retry),
                failed_id: retryOpts && retryOpts.failedId ? retryOpts.failedId : 0,
                saved: !!(retryOpts && retryOpts.saved)
              }),
              signal: controller.signal
            });
            if (res.status === 401) {
              this.authed = false;
              return;
            }
            if (!res.ok || !res.body) {
              const data = await res.json().catch(function () { return {}; });
              throw new Error(data.error || 'Could not send the message.');
            }
            await this.readSse(res.body);
            if (!this.streamEnded) {
              throw new Error('The connection dropped before the reply finished.');
            }
            await this.reloadMessages();
            this.streamError = '';
            await this.refreshStatus();
          } catch (error) {
            const lost = /load failed|failed to fetch|networkerror|connection dropped/i.test(String(error && error.message || ''));
            if (error.name === 'AbortError') {
              aborted = true;
              if ((this.projectId || 0) === chatProject) this.flushLive();
            } else if (lost && (this.projectId || 0) === chatProject) {
              this.flushLive();
              this.messages.push({
                id: 'fail-' + Date.now(),
                role: 'assistant',
                agent_name: 'orchestrator',
                content: 'The connection dropped before the reply finished.',
                failed: 1
              });
            } else if ((this.projectId || 0) === chatProject) {
              const text = error.message || 'The request failed.';
              if (/still loading/i.test(text)) {
                this.streamError = '';
                if (!this.projectOpener || this.projectOpener.projectId !== chatProject) {
                  this.projectOpener = { projectId: chatProject, text: message, saved: !!(retryOpts && retryOpts.saved) };
                }
                await this.refreshModelLoad();
                const load = this.modelLoad;
                this.awaitingModel = !!(load && load.ready !== true && !load.error && load.scratch === false);
              } else {
                this.streamError = text;
                await this.reloadMessages().catch(function () {});
                const last = this.messages[this.messages.length - 1];
                if (!last || !last.failed) {
                  this.messages.push({
                    id: 'fail-' + Date.now(),
                    role: 'assistant',
                    agent_name: 'orchestrator',
                    content: text,
                    failed: 1
                  });
                }
                this.streamError = '';
              }
            }
          } finally {
            if (this.chatRun !== runId) return;
            this.clearSlowTimer();
            this.sending = false;
            this.waitPhase = '';
            this.answerStarted = false;
            this.live = '';
            this.liveDetail = { thinking: [], tools: [] };
            this.toolPending = false;
            this.chatAbort = null;
            this.delegation = null;
            const next = this.steerText;
            this.steerText = '';
            if ((this.projectId || 0) !== chatProject) return;
            if (aborted && !next && this.userStop) {
              this.messages.push({ id: 'stop-' + Date.now(), role: 'assistant', agent_name: 'orchestrator', content: 'Stopped.' });
            }
            if (this.awaitingModel) this.armSlowTimer();
            if (next) await this.runChat(next);
          }
        },
        stopRun() {
          this.actionPrompt = null;
          this.userStop = true;
          const generation = this.chatGeneration || '';
          if (this.chatAbort) this.chatAbort.abort();
          this.api('/api/chat/stop', { method: 'POST', body: { project_id: this.projectId || 0, generation: generation }, quiet: true }).catch(function () {});
        },
        async stopAgents() {
          if (this.stopBusy || this.stopModal) return;
          this.stopBusy = true;
          this.stopError = '';
          try {
            const data = await this.api('/api/agents/stop', {
              method: 'POST',
              body: { project_id: this.projectId || 0, generation: this.chatGeneration || '' }
            });
            this.heldTasks = Array.isArray(data.held) ? data.held : [];
            this.runStopping = true;
            this.stopNote = '';
            this.stopModal = true;
            await this.refreshStatus(true);
          } catch (error) {
            this.stopError = error.message || 'Could not stop the run.';
          } finally {
            this.stopBusy = false;
          }
        },
        dismissStop() {
          this.stopModal = false;
          this.stopNote = '';
          this.stopError = '';
        },
        async submitStopNote() {
          const note = this.stopNote.trim();
          if (!note || !this.stopSettled || this.stopBusy) return;
          const ids = (this.heldTasks || []).filter((id) => Number(id) > 0).map((id) => '#' + id);
          let message = 'The current run was stopped. ';
          if (ids.length) {
            message += 'Held tasks: ' + ids.join(', ') + '. Do not start a held task unless this instruction says to resume it. Change the card, resume it, or end it.';
          } else {
            message += 'Do not continue the stopped run.';
          }
          message += '\n\n' + note;
          this.stopModal = false;
          this.stopNote = '';
          this.stopError = '';
          if (this.tab !== 'chat') this.setTab('chat');
          this.messages.push({ id: 'local-' + Date.now(), role: 'user', agent_name: 'human', content: message });
          this.scrollChat(true);
          await this.runChat(message);
        },
        answerAction(allow) {
          const prompt = this.actionPrompt;
          if (!prompt) return;
          this.actionPrompt = null;
          this.api('/api/actions/answer', { method: 'POST', body: { id: prompt.id, allow: !!allow }, quiet: true }).catch(function () {});
          this.refreshPendingActions();
        },
        answerPending(item, allow) {
          if (!item || !item.id) return;
          this.pendingActions = this.pendingActions.filter(function (row) { return row.id !== item.id; });
          if (this.actionPrompt && this.actionPrompt.id === item.id) this.actionPrompt = null;
          this.api('/api/actions/answer', { method: 'POST', body: { id: item.id, allow: !!allow }, quiet: true }).catch(function () {});
        },
        async refreshPendingActions() {
          try {
            const data = await this.api('/api/actions/pending', { quiet: true });
            this.pendingActions = data.pending || [];
          } catch (error) {
            this.pendingActions = [];
          }
        },
        openCompliance() {
          this.setTab('compliance');
        },
        fillComplianceForm(status) {
          const s = status || {};
          this.complianceForm = {
            ai_act_applies: s.ai_act_applies || '',
            responsible_person: s.responsible_person || '',
            intended_use: s.intended_use_notes || '',
            compliance_logging: !!s.compliance_logging,
            hitl_external_actions: !!s.hitl_external_actions,
            retention_days: Number(s.retention_days) || 90
          };
          if (s.terms_version) this.termsVersion = s.terms_version;
          this.termsNeeded = !s.terms_accepted;
        },
        async loadCompliance() {
          const data = await this.api('/api/compliance');
          this.complianceIntendedPurpose = data.intended_purpose || '';
          this.complianceDisclosure = data.disclosure || '';
          this.complianceEvents = data.events || [];
          this.fillComplianceForm(data.status || {});
          if (data.status) {
            this.settings = Object.assign({}, this.settings, {
              terms_accepted: !!data.status.terms_accepted,
              terms_accepted_at: data.status.terms_accepted_at || '',
              ai_act_applies: data.status.ai_act_applies || '',
              compliance_logging: data.status.compliance_logging ? '1' : '0',
              hitl_external_actions: data.status.hitl_external_actions ? '1' : '0',
              show_compliance_box: !!data.status.show_compliance_box
            });
          }
        },
        async saveCompliance() {
          this.complianceMessage = '';
          this.complianceError = false;
          try {
            const data = await this.api('/api/compliance', {
              method: 'POST',
              body: {
                ai_act_applies: this.complianceForm.ai_act_applies,
                compliance_responsible_person: this.complianceForm.responsible_person,
                compliance_intended_use: this.complianceForm.intended_use,
                compliance_logging: this.complianceForm.compliance_logging,
                hitl_external_actions: this.complianceForm.hitl_external_actions,
                compliance_retention_days: this.complianceForm.retention_days
              }
            });
            if (data.settings) this.applySettings({ settings: data.settings, hardware: this.hardware });
            this.fillComplianceForm(data.status || {});
            this.complianceDisclosure = data.disclosure || this.complianceDisclosure;
            this.complianceMessage = 'Saved.';
            await this.loadCompliance();
          } catch (error) {
            this.complianceError = true;
            this.complianceMessage = error.message;
          }
        },
        async acceptTerms() {
          try {
            const data = await this.api('/api/compliance/terms', { method: 'POST', body: {} });
            if (data.settings) this.applySettings({ settings: data.settings, hardware: this.hardware });
            this.fillComplianceForm(data.status || {});
            this.termsNeeded = false;
          } catch (error) {
            this.complianceError = true;
            this.complianceMessage = error.message;
          }
        },
        async downloadSystemCard() {
          try {
            const res = await fetch('/api/compliance/system-card?download=1', { cache: 'no-store' });
            if (!res.ok) throw new Error('Could not download the system card.');
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'danaco-system-card.md';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            if (this.tab === 'compliance') await this.loadCompliance();
          } catch (error) {
            this.complianceError = true;
            this.complianceMessage = error.message;
          }
        },
        async copyDisclosure() {
          const text = this.complianceDisclosure || '';
          try {
            await navigator.clipboard.writeText(text);
            this.disclosureCopied = true;
            setTimeout(() => { this.disclosureCopied = false; }, 2000);
          } catch (error) {
            this.complianceError = true;
            this.complianceMessage = 'Could not copy.';
          }
        },
        errorText(text) {
          const value = String(text || '').trim();
          if (!value) return 'Error: Something went wrong.';
          if (value.startsWith('Error:')) return value;
          if (value.startsWith('Tool error: ')) return 'Error: ' + value.slice('Tool error: '.length);
          return 'Error: ' + value;
        },
        shownContent(msg) {
          let value = String((msg && msg.content) || '');
          if (msg && msg.role === 'assistant') value = stripChatTags(value);
          if (!msg || !msg.failed) return value;
          if (value.includes('\n\nError: ')) return value;
          return this.errorText(value);
        },
        toggleTrace(key) {
          this.traceOpen = Object.assign({}, this.traceOpen, { [key]: !this.traceOpen[key] });
        },
        noteThinking(data) {
          const agent = data.agent || 'orchestrator';
          const blocks = this.liveDetail.thinking;
          let last = blocks[blocks.length - 1];
          if (last && last.agent === agent) last.text += data.text || '';
          else {
            blocks.push({ agent: agent, text: data.text || '' });
            last = blocks[blocks.length - 1];
          }
          if (last && last.text.length > 6000) {
            last.text = 'Earlier reasoning was set aside.\n\n' + last.text.slice(-2500);
          }
        },
        noteTool(data) {
          this.liveDetail.tools.push({
            agent: data.agent || '',
            name: data.name || 'tool',
            args: data.args || {},
            result: ''
          });
        },
        noteToolResult(data) {
          const name = data.name || '';
          const tools = this.liveDetail.tools;
          for (let i = tools.length - 1; i >= 0; i--) {
            if (tools[i].name === name && !tools[i].result) {
              tools[i].result = data.text || '';
              return;
            }
          }
        },
        armSlowTimer() {
          this.clearSlowTimer();
          this.slowTimer = setTimeout(() => {
            if ((this.sending || this.awaitingModel) && !this.live) this.slowReply = true;
          }, 20000);
        },
        clearSlowTimer() {
          if (this.slowTimer) clearTimeout(this.slowTimer);
          this.slowTimer = null;
        },
        async readSse(body) {
          const reader = body.getReader();
          const decoder = new TextDecoder();
          let buf = '';
          while (true) {
            const chunk = await reader.read();
            if (chunk.done) break;
            buf += decoder.decode(chunk.value, { stream: true });
            const parts = buf.split('\n\n');
            buf = parts.pop() || '';
            parts.forEach((part) => this.applySse(part));
          }
          if (buf.trim()) this.applySse(buf);
        },
        flushLive() {
          const thinking = this.liveDetail.thinking || [];
          const tools = this.liveDetail.tools || [];
          if (!this.live && !thinking.length && !tools.length) return;
          this.messages.push({
            id: 'live-' + Date.now() + Math.random(),
            role: 'assistant',
            agent_name: this.liveAgent,
            content: this.live,
            detail: { thinking: thinking, tools: tools }
          });
          this.live = '';
          this.liveDetail = { thinking: [], tools: [] };
        },
        applySse(raw) {
          if (!raw || raw.charAt(0) === ':') return;
          if (this.chatStreamProject !== (this.projectId || 0)) return;
          let event = 'message';
          const dataLines = [];
          raw.split('\n').forEach((line) => {
            if (line.indexOf('event:') === 0) event = line.slice(6).trim();
            if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).trim());
          });
          if (!dataLines.length) return;
          let data = {};
          try { data = JSON.parse(dataLines.join('\n')); } catch (error) { return; }
          if (event === 'phase') {
            if (!(data.phase === 'compressing' && this.waitPhase === 'scheduled')) {
              this.waitPhase = data.phase || '';
            }
          } else if (event === 'token') {
            this.slowReply = false;
            this.waitPhase = '';
            this.clearSlowTimer();
            if (this.live && this.liveAgent !== data.agent) this.flushLive();
            this.liveAgent = data.agent || this.liveAgent;
            this.live = stripChatTags(this.live + (data.text || ''));
            if (data.text) this.answerStarted = true;
            this.scrollChat();
          } else if (event === 'thinking') {
            this.slowReply = false;
            this.waitPhase = '';
            this.answerStarted = false;
            this.noteThinking(data);
            this.scrollChat();
          } else if (event === 'context') {
            this.context = { used: Number(data.used) || 0, total: Number(data.total) || 0 };
          } else if (event === 'notice') {
            const text = data.text || '';
            const last = this.messages[this.messages.length - 1];
            if (text && (!last || last.role !== 'notice' || last.content !== text)) {
              this.messages.push({ id: 'notice-' + Date.now(), role: 'notice', content: text });
              this.scrollChat();
            }
          } else if (event === 'delegation') {
            this.delegation = data;
          } else if (event === 'install_prompt' || event === 'action_prompt') {
            this.actionPrompt = {
              id: data.id,
              kind: data.kind || (event === 'install_prompt' ? 'install' : 'action'),
              summary: data.summary || (data.command ? ('Allow this install? ' + data.command) : 'Allow this action?'),
              command: data.command || ''
            };
            this.refreshPendingActions();
            this.scrollChat();
          } else if (event === 'tool_call') {
            this.waitPhase = '';
            this.noteTool(data);
            this.scrollChat();
          } else if (event === 'tool_result') {
            this.noteToolResult(data);
            this.scrollChat();
          } else if (event === 'activity') {
            this.waitPhase = '';
            this.toolPending = true;
            this.scrollChat();
          } else if (event === 'stopped') {
            this.streamEnded = true;
            this.streamError = '';
          } else if (event === 'done') {
            this.streamEnded = true;
          } else if (event === 'error') {
            this.streamEnded = true;
            this.streamError = data.text || 'The model failed.';
          } else if (event === 'cpu') {
            if (!this.settings) this.settings = {};
            this.settings.cpu_fallback = data.cpu ? '1' : '0';
          } else if (event === 'status' && data.agent) {
            const found = this.agents.find((agent) => agent.name === data.agent);
            if (found) {
              found.status = data.status;
              found.label = ({
                idle: 'Idle',
                thinking: 'Thinking',
                executing_tool: 'Executing Tool',
                completed: 'Completed',
                error: 'Error',
                stuck: 'Stuck'
              })[data.status] || data.status;
            }
            if (data.status === 'thinking') {
              this.answerStarted = false;
              this.toolPending = false;
            }
            if (data.status === 'executing_tool') this.toolPending = false;
            if ((data.status === 'completed' || data.status === 'error') && this.delegation && this.delegation.agent === data.agent) {
              this.delegation = null;
            }
          }
        },
        repeatLabel(value) {
          return ({
            '5min': 'every 5 min',
            '15min': 'every 15 min',
            '30min': 'every 30 min',
            hourly: 'hourly',
            daily: 'daily',
            weekly: 'weekly',
            monthly: 'monthly'
          })[value] || 'run once';
        },
        taskRepeats(task) {
          return !!(task.recurrence_interval && task.recurrence_interval !== 'none');
        },
        columnStatus(task) {
          if (this.taskRepeats(task)) return 'in_progress';
          if (task.status === 'done' || task.status === 'review' || task.status === 'in_progress') return task.status;
          return 'in_progress';
        },
        finishedTasks() {
          return this.tasks.filter((task) => task.status === 'done' && task.project_id === this.projectId)
            .sort((a, b) => String(b.finished_at || b.created_at || '').localeCompare(String(a.finished_at || a.created_at || '')) || (b.id - a.id));
        },
        finishedVisible() {
          const all = this.finishedTasks();
          return this.finishedOpen ? all : all.slice(0, 4);
        },
        tasksFor(status) {
          return this.tasks.filter((task) => {
            if (this.columnStatus(task) !== status) return false;
            return task.project_id === this.projectId;
          });
        },
        async reloadTasks() {
          if (this.taskLoading) return;
          this.taskLoading = true;
          try {
            const data = await this.api('/api/tasks');
            if (Array.isArray(data.tasks)) this.tasks = data.tasks;
          } catch (error) {
          } finally {
            this.taskLoading = false;
          }
        },
        async addTask() {
          this.taskError = '';
          const title = this.taskDraft.trim();
          if (!title) return;
          let projectId = this.projectId;
          if (!projectId) {
            this.taskError = this.projects.length ? 'Choose a project for this task.' : 'Create a project first.';
            return;
          }
          try {
            await this.api('/api/tasks', {
              method: 'POST',
              body: { project_id: projectId, title: title, status: 'in_progress' }
            });
            this.taskDraft = '';
            await this.reloadTasks();
          } catch (error) {
            this.taskError = error.message || 'Could not add the task.';
          }
        },
        toggleTask(task) {
          if (this.openTask === task.id) {
            this.openTask = null;
            return;
          }
          this.openTask = task.id;
          this.taskError = '';
          const stamp = String(task.schedule_time || '').replace(' ', 'T').slice(0, 16);
          this.taskEdit = {
            title: task.title,
            description: task.description || '',
            assigned_agent: task.assigned_agent,
            when: Number(task.held) === 1 && !stamp ? 'never' : (stamp ? 'at' : 'immediately'),
            schedule_time: stamp,
            recurrence_interval: task.recurrence_interval || 'none'
          };
        },
        taskScheduleStamp(value) {
          let text = String(value || '').trim().replace('T', ' ').replace(/\.\d+$/, '');
          if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(text)) text += ':00';
          return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text) ? text : '';
        },
        taskWhen(task) {
          if (Number(task.held) === 1 && !task.schedule_time) return 'never';
          return task.schedule_time || 'immediately';
        },
        async saveTask(task) {
          this.taskError = '';
          const never = this.taskEdit.when === 'never';
          const scheduled = this.taskEdit.when === 'at';
          const stamp = scheduled ? this.taskScheduleStamp(this.taskEdit.schedule_time) : '';
          if (scheduled && !stamp) {
            this.taskError = 'Choose a time, or set the task to run immediately.';
            return;
          }
          const body = {
            title: this.taskEdit.title,
            description: this.taskEdit.description,
            assigned_agent: this.taskEdit.assigned_agent,
            schedule_time: never ? 'never' : (stamp || null),
            recurrence_interval: this.taskEdit.recurrence_interval
          };
          if (task.status === 'review' && (this.taskEdit.description || '').trim() !== (task.description || '').trim() && (this.taskEdit.description || '').trim() !== '') {
            body.status = 'in_progress';
          }
          try {
            const saved = await this.api('/api/tasks/' + task.id, { method: 'PATCH', body: body });
            Object.assign(task, saved.task);
            if (saved.task.status !== 'review') this.openTask = null;
          } catch (error) {
            this.taskError = error.message || 'Could not save the task.';
          }
        },
        async finishTask(task) {
          const saved = await this.api('/api/tasks/' + task.id, { method: 'PATCH', body: { status: 'done' } });
          Object.assign(task, saved.task);
          if (this.openTask === task.id) this.openTask = null;
        },
        async moveTask(task, status) {
          const saved = await this.api('/api/tasks/' + task.id, { method: 'PATCH', body: { status: status } });
          Object.assign(task, saved.task);
        },
        async deleteTask(task) {
          if (!window.confirm('Delete ' + task.title + '?')) return;
          await this.api('/api/tasks/' + task.id + '/delete', { method: 'POST', body: {} });
          this.tasks = this.tasks.filter((item) => item.id !== task.id);
          if (this.openTask === task.id) this.openTask = null;
        },
        async clearFinishedTasks() {
          const finished = this.finishedTasks();
          if (!finished.length || !window.confirm('Delete all finished tasks?')) return;
          await this.api('/api/tasks/finished/clear', { method: 'POST', body: { project_id: this.projectId } });
          const ids = {};
          finished.forEach((task) => { ids[task.id] = true; });
          this.tasks = this.tasks.filter((item) => !ids[item.id]);
          if (ids[this.openTask]) this.openTask = null;
        },
        onDragStart(task, event) {
          this.dragId = task.id;
          event.dataTransfer.setData('text/plain', String(task.id));
          event.dataTransfer.effectAllowed = 'move';
        },
        async onDrop(status, event) {
          const id = Number(event.dataTransfer.getData('text/plain') || this.dragId);
          const task = this.tasks.find((item) => item.id === id);
          if (!task || this.taskRepeats(task) || this.columnStatus(task) === status) return;
          await this.moveTask(task, status);
        },
        collectOpen(nodes, into) {
          (nodes || []).forEach((node) => {
            if (!node) return;
            if (node.open) into[this.fileIdentity(node)] = true;
            if (node.children) this.collectOpen(node.children, into);
          });
        },
        prepareTree(nodes, openMain, open) {
          return (nodes || []).map((node) => {
            const children = node.children ? this.prepareTree(node.children, false, open) : [];
            const id = (node.scope || '') + '\n' + (node.folder || '') + '\n' + (node.path || '');
            let isOpen = !!(openMain && node.scope === 'wiki' && node.name === 'Main');
            if (open && Object.prototype.hasOwnProperty.call(open, id)) isOpen = true;
            return { ...node, open: isOpen, children: children };
          });
        },
        fileIdentity(node) {
          return (node.scope || '') + '\n' + (node.folder || '') + '\n' + (node.path || '');
        },
        async loadTree() {
          const generation = (this.treeLoad || 0) + 1;
          this.treeLoad = generation;
          const open = {};
          this.collectOpen(this.tree, open);
          const data = await this.api('/api/workspace/tree');
          if (generation !== this.treeLoad) return;
          this.tree = this.prepareTree(data.tree, !Object.keys(open).length, open);
        },
        clearFilePreview() {
          this.filePreviewUrl = '';
        },
        sitePreviewUrl(node) {
          const scope = node && node.scope === 'wiki' ? 'wiki' : 'project';
          const rel = String((node && node.path) || '').split('/').filter(Boolean).map(encodeURIComponent).join('/');
          if (!rel) return '';
          if (scope === 'wiki') return '/preview/wiki/' + rel;
          const folder = String((node && node.folder) || '');
          if (!folder) return '';
          return '/preview/project/' + encodeURIComponent(folder) + '/' + rel;
        },
        async openFile(node) {
          if (!node) return;
          const generation = (this.fileLoad || 0) + 1;
          this.fileLoad = generation;
          const name = node.name || '';
          if (node.type === 'dir') {
            this.clearFilePreview();
            this.filePath = '';
            this.fileName = name;
            this.fileScope = node.scope || 'project';
            this.fileFolder = node.folder || '';
            this.fileRel = node.path || '';
            this.fileIsDir = true;
            this.fileContent = '';
            this.fileDraft = '';
            this.fileEditing = false;
            this.structuredData = null;
            this.structuredTableName = '';
            this.fileError = '';
            this.fileMarkdown = false;
            this.fileKey = this.fileIdentity(node);
            this.showingSearch = false;
            return;
          }
          const kind = fileKindOf(name || node.path || '');
          let previewBlocked = false;
          if (kind === 'html' || kind === 'js') {
            const preview = this.sitePreviewUrl(node);
            if (preview) {
              const opened = window.open(preview, '_blank');
              if (opened) opened.opener = null;
              else previewBlocked = true;
            }
          }
          this.clearFilePreview();
          this.filePath = '';
          this.fileName = name;
          this.fileScope = node.scope || 'project';
          this.fileFolder = node.folder || '';
          this.fileRel = node.path || '';
          this.fileIsDir = false;
          this.fileContent = '';
          this.fileDraft = '';
          this.fileEditing = false;
          this.structuredData = null;
          this.structuredTableName = '';
          this.fileError = previewBlocked ? 'Allow pop-ups to preview this page.' : '';
          this.fileMarkdown = kind === 'markdown';
          this.fileKey = this.fileIdentity(node);
          this.showingSearch = false;
          if (kind === 'pdf' || kind === 'image') {
            this.filePreviewUrl = '/api/workspace/raw?scope=' + encodeURIComponent(this.fileScope) + '&folder=' + encodeURIComponent(this.fileFolder) + '&path=' + encodeURIComponent(this.fileRel);
            this.filePath = name;
            return;
          }
          if (['csv', 'sqlite', 'docx'].includes(kind)) {
            await this.loadStructuredFile();
            return;
          }
          try {
            const data = await this.api('/api/workspace/file?scope=' + encodeURIComponent(this.fileScope) + '&folder=' + encodeURIComponent(this.fileFolder) + '&path=' + encodeURIComponent(this.fileRel));
            if (generation !== this.fileLoad) return;
            this.filePath = data.path;
            this.fileContent = data.content;
            this.fileDraft = data.content;
          } catch (error) {
            if (generation !== this.fileLoad) return;
            this.fileError = error.message;
          }
        },
        startFileEdit() {
          if (this.structuredKind) return;
          this.fileDraft = this.fileContent;
          this.fileError = '';
          this.fileEditing = true;
        },
        cancelFileEdit() {
          this.fileEditing = false;
          this.fileError = '';
        },
        async saveFile() {
          this.fileError = '';
          try {
            await this.api('/api/workspace/file', {
              method: 'POST',
              body: { scope: this.fileScope, folder: this.fileFolder, path: this.fileRel, content: this.fileDraft }
            });
            this.fileContent = this.fileDraft;
            this.fileEditing = false;
          } catch (error) {
            this.fileError = error.message;
          }
        },
        async loadStructuredFile() {
          this.structuredLoading = true;
          this.fileError = '';
          try {
            const data = await this.api('/api/workspace/structured?scope=' + encodeURIComponent(this.fileScope) + '&folder=' + encodeURIComponent(this.fileFolder) + '&path=' + encodeURIComponent(this.fileRel));
            this.structuredData = data.data || null;
            if (this.structuredKind === 'sqlite' && this.structuredData && this.structuredData.tables.length) {
              if (!this.structuredData.tables.some((table) => table.name === this.structuredTableName)) this.structuredTableName = this.structuredData.tables[0].name;
            }
          } catch (error) {
            this.fileError = error.message;
          } finally {
            this.structuredLoading = false;
          }
        },
        async saveStructuredFile() {
          if (!this.structuredData || this.structuredKind === 'sqlite') return;
          this.fileError = '';
          const body = {
            scope: this.fileScope,
            folder: this.fileFolder,
            path: this.fileRel,
            paragraphs: this.structuredData.paragraphs,
            tables: this.structuredData.tables,
            headers: this.structuredData.headers,
            rows: this.structuredData.rows
          };
          try {
            await this.api('/api/workspace/structured', { method: 'POST', body: body });
          } catch (error) {
            this.fileError = error.message;
          }
        },
        async saveSqliteAction(action, row) {
          this.fileError = '';
          try {
            await this.api('/api/workspace/structured', {
              method: 'POST',
              body: { scope: this.fileScope, folder: this.fileFolder, path: this.fileRel, table: this.structuredTableName, action: action, row: row }
            });
            await this.loadStructuredFile();
          } catch (error) {
            this.fileError = error.message;
          }
        },
        addCsvRow() {
          if (this.structuredData) this.structuredData.rows.push(this.structuredData.headers.map(() => ''));
        },
        deleteCsvRow(index) {
          if (this.structuredData) this.structuredData.rows.splice(index, 1);
        },
        addSqliteRow() {
          const table = this.selectedStructuredTable;
          if (!table) return;
          const row = {};
          table.columns.forEach((column) => { if (!column.pk) row[column.name] = ''; });
          this.saveSqliteAction('insert', row);
        },
        deleteSqliteRow(row) {
          this.saveSqliteAction('delete', row);
        },
        onPreviewError(event) {
          const current = this.filePreviewUrl;
          const shown = event && event.target ? event.target.src : '';
          if (!current || !shown) return;
          if (new URL(shown, location.href).href !== new URL(current, location.href).href) return;
          this.filePreviewUrl = '';
          this.fileError = 'Could not open that file.';
        },
        async downloadFile() {
          const name = this.fileName || 'file';
          if (this.filePreview && this.filePreviewUrl) {
            try {
              const res = await fetch(this.filePreviewUrl, { cache: 'no-store' });
              if (res.status === 401) {
                this.authed = false;
                return;
              }
              if (!res.ok) {
                const data = await res.json().catch(function () { return {}; });
                this.fileError = data.error || 'Could not download that file.';
                return;
              }
              this.saveBlob(name, await res.blob());
            } catch (error) {
              this.fileError = error.message;
            }
            return;
          }
          const blob = new Blob([this.fileEditing ? this.fileDraft : this.fileContent], { type: 'text/plain;charset=utf-8' });
          this.saveBlob(name, blob);
        },
        openFileInside(node) {
          if (!this.fileName || !node || !node.path) return false;
          if ((this.fileScope || '') !== (node.scope || '')) return false;
          if ((this.fileFolder || '') !== (node.folder || '')) return false;
          const rel = this.fileRel || '';
          return rel === node.path || rel.startsWith(node.path + '/');
        },
        openTreeMenu(payload) {
          if (!payload || !payload.node || !payload.node.path) return;
          const x = Math.max(8, Math.min(payload.x, window.innerWidth - 168));
          const y = Math.max(8, Math.min(payload.y, window.innerHeight - 48));
          this.treeMenu = { x: x, y: y, node: payload.node };
        },
        async deleteTreeNode(node) {
          this.treeMenu = null;
          if (!node || !node.path) return;
          const name = node.name || (node.type === 'dir' ? 'this folder' : 'this file');
          const prompt = node.type === 'dir'
            ? 'Move "' + name + '" and everything inside to the agent trashbin?'
            : 'Move "' + name + '" to the agent trashbin?';
          if (!window.confirm(prompt)) return;
          this.treeError = '';
          try {
            await this.api('/api/workspace/delete', {
              method: 'POST',
              body: { scope: node.scope || 'project', folder: node.folder || '', path: node.path }
            });
            if (this.openFileInside(node)) {
              this.clearFilePreview();
              this.filePath = '';
              this.fileName = '';
              this.fileScope = '';
              this.fileFolder = '';
              this.fileRel = '';
              this.fileIsDir = false;
              this.fileContent = '';
              this.fileDraft = '';
              this.fileEditing = false;
              this.fileKey = '';
              this.structuredData = null;
              this.structuredTableName = '';
              this.fileMarkdown = false;
            }
            await this.loadTree();
            if (this.vaultOpen) {
              const data = await this.api('/api/workspace/vault');
              this.backups = data.backups;
              this.trash = data.trash;
            }
          } catch (error) {
            this.treeError = error.message;
          }
        },
        async moveTreeNode(payload) {
          const source = payload && payload.source;
          const dest = payload && payload.dest;
          if (!source || !source.path || !dest || dest.type !== 'dir') return;
          this.treeError = '';
          this.treeOverKey = '';
          dest.open = true;
          try {
            const data = await this.api('/api/workspace/move', {
              method: 'POST',
              body: {
                scope: source.scope || 'project',
                folder: source.folder || '',
                path: source.path,
                dest_scope: dest.scope || 'project',
                dest_folder: dest.folder || '',
                dest_path: dest.path || ''
              }
            });
            this.retargetOpenFile(source, data);
            await this.loadTree();
          } catch (error) {
            this.treeError = error.message;
          }
        },
        retargetOpenFile(source, moved) {
          if (!this.fileName || !moved || !this.openFileInside(source)) return;
          const suffix = (this.fileRel || '').slice((source.path || '').length);
          this.fileScope = moved.scope || 'project';
          this.fileFolder = moved.folder || '';
          this.fileRel = (moved.path || '') + suffix;
          this.fileKey = this.fileScope + '\n' + this.fileFolder + '\n' + this.fileRel;
          if (this.filePreviewUrl) {
            this.filePreviewUrl = '/api/workspace/raw?scope=' + encodeURIComponent(this.fileScope) + '&folder=' + encodeURIComponent(this.fileFolder) + '&path=' + encodeURIComponent(this.fileRel);
          }
        },
        async deleteFile() {
          if (!this.canTrashWorkspace) return;
          const name = this.fileName || (this.fileIsDir ? 'this folder' : 'this file');
          const prompt = this.fileIsDir
            ? 'Move "' + name + '" and everything inside to the agent trashbin?'
            : 'Move "' + name + '" to the agent trashbin?';
          if (!window.confirm(prompt)) return;
          this.fileError = '';
          try {
            await this.api('/api/workspace/delete', {
              method: 'POST',
              body: { scope: this.fileScope, folder: this.fileFolder, path: this.fileRel }
            });
            this.clearFilePreview();
            this.filePath = '';
            this.fileName = '';
            this.fileScope = '';
            this.fileFolder = '';
            this.fileRel = '';
            this.fileIsDir = false;
            this.fileContent = '';
            this.fileDraft = '';
            this.fileEditing = false;
            this.fileKey = '';
            await this.loadTree();
            if (this.vaultOpen) {
              const data = await this.api('/api/workspace/vault');
              this.backups = data.backups;
              this.trash = data.trash;
            }
          } catch (error) {
            this.fileError = error.message;
          }
        },
        saveBlob(name, content, type) {
          const blob = content instanceof Blob ? content : new Blob([content], { type: type || 'text/plain;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = name || 'file';
          link.click();
          URL.revokeObjectURL(url);
        },
        async onTranscriptClick(event) {
          const button = event.target.closest('.file-cite-dl');
          if (!button || !this.$refs.transcript || !this.$refs.transcript.contains(button)) return;
          event.preventDefault();
          const path = button.getAttribute('data-download') || '';
          if (path) {
            await this.downloadProjectFile(path, button);
            return;
          }
          const code = button.parentElement ? button.parentElement.querySelector('code') : null;
          this.saveBlob(button.getAttribute('data-name') || 'note.md', code ? code.textContent : '', 'text/markdown;charset=utf-8');
        },
        queueCitedFileCheck() {
          if (this._citeCheckTimer) clearTimeout(this._citeCheckTimer);
          this._citeCheckTimer = setTimeout(() => {
            this._citeCheckTimer = null;
            this.verifyCitedFiles();
          }, 120);
        },
        markCiteMissing(button, message) {
          if (!button) return;
          const cite = button.closest('.file-cite');
          if (!cite) return;
          cite.classList.add('missing');
          button.remove();
          let note = cite.querySelector('.file-cite-missing');
          if (!note) {
            note = document.createElement('span');
            note.className = 'file-cite-missing';
            cite.appendChild(note);
          }
          note.textContent = message || 'File does not exist.';
        },
        async verifyCitedFiles() {
          if (!this.$refs.transcript) return;
          await this.$nextTick();
          const root = this.$refs.transcript;
          const buttons = root.querySelectorAll('.file-cite-dl[data-download]:not([data-checked="1"])');
          for (let i = 0; i < buttons.length; i++) {
            const button = buttons[i];
            if (!root.contains(button)) continue;
            const path = button.getAttribute('data-download') || '';
            button.setAttribute('data-checked', '1');
            if (!path) continue;
            try {
              const data = await this.api('/api/workspace/exists?project_id=' + encodeURIComponent(this.projectId || 0) + '&path=' + encodeURIComponent(path));
              if (!data.exists) this.markCiteMissing(button);
            } catch (error) {
              button.removeAttribute('data-checked');
            }
          }
        },
        async downloadProjectFile(path, button) {
          const res = await fetch('/api/workspace/download?project_id=' + encodeURIComponent(this.projectId || 0) + '&path=' + encodeURIComponent(path), { cache: 'no-store' });
          if (res.status === 401) {
            this.authed = false;
            return;
          }
          if (!res.ok) {
            const data = await res.json().catch(function () { return {}; });
            const err = data.error || 'Could not download that file.';
            if (/not found/i.test(err)) {
              this.markCiteMissing(button, 'File does not exist.');
              return;
            }
            if (button) button.title = err;
            return;
          }
          const blob = await res.blob();
          const name = path.split('/').pop() || 'file';
          this.saveBlob(name, blob);
        },
        clearWorkspaceSearch() {
          this.wikiQuery = '';
          this.wikiResult = '';
          this.searchHits = [];
          this.showingSearch = false;
        },
        listedVault(items) {
          const query = this.wikiQuery.trim().toLowerCase();
          if (!query) return items || [];
          return (items || []).filter((item) => {
            const hay = [item.original, item.stored_as, item.relative_path, item.project_folder, item.kind].join(' ').toLowerCase();
            return hay.includes(query);
          });
        },
        async searchWorkspace() {
          if (this.vaultOpen) return;
          this.wikiResult = '';
          this.searchHits = [];
          const query = this.wikiQuery.trim();
          if (!query) {
            this.showingSearch = false;
            return;
          }
          this.fileEditing = false;
          this.showingSearch = true;
          const data = await this.api('/api/workspace/search', { method: 'POST', body: { query: query } });
          this.searchHits = data.results || [];
          if (!this.searchHits.length) this.wikiResult = 'No matches.';
        },
        async toggleVault() {
          this.vaultOpen = !this.vaultOpen;
          if (this.vaultOpen) {
            const data = await this.api('/api/workspace/vault');
            this.backups = data.backups;
            this.trash = data.trash;
          }
        },
        vaultRestored(kind, storedAs) {
          return !!this.restoredVault[kind + '\n' + storedAs];
        },
        async restoreItem(kind, storedAs) {
          const key = kind + '\n' + storedAs;
          if (this.restoredVault[key]) return;
          await this.api('/api/workspace/restore', { method: 'POST', body: { kind: kind, stored_as: storedAs } });
          this.restoredVault[key] = true;
          await this.loadTree();
        },
        async refreshStatus(quiet) {
          const params = new URLSearchParams();
          params.set('project_id', String(this.projectId || 0));
          const data = await this.api('/api/agents/status?' + params.toString(), { quiet: !!quiet });
          this.agents = data.agents;
          this.runStopping = !!data.stopping;
        },
        async refreshLogs(quiet) {
          const projectId = this.projectId || 0;
          if (this.logLatestProject !== projectId) {
            this.logLatest = {};
            this.logLatestProject = projectId;
          }
          const params = new URLSearchParams();
          params.set('project_id', String(projectId));
          if (this.logAgent) params.set('agent', this.logAgent);
          const logs = (await this.api('/api/agents/logs?' + params.toString(), { quiet: !!quiet })).logs.reverse();
          if (!this.logAgent) {
            const latest = {};
            logs.forEach((log) => {
              if (!latest[log.agent_name]) latest[log.agent_name] = log;
            });
            this.logLatest = latest;
          } else if (logs.length) {
            this.logLatest = Object.assign({}, this.logLatest, { [logs[0].agent_name]: logs[0] });
          }
          this.logs = logs;
        },
        async refreshLoop(quiet) {
          const projectId = this.projectId || 0;
          const params = new URLSearchParams();
          params.set('project_id', String(projectId));
          const logs = (await this.api('/api/agents/loop?' + params.toString(), { quiet: !!quiet })).logs;
          this.loopLogs = Array.isArray(logs) ? logs.slice().reverse() : [];
        },
        projectName(id) {
          const project = this.projects.find((item) => item.id === id);
          return project ? project.name : 'Missing project';
        },
        blankEmailForm() {
          return {
            project_id: this.projectId || (this.projects[0] ? this.projects[0].id : 0),
            from_name: '',
            from_email: '',
            smtp_host: '',
            smtp_port: 587,
            smtp_security: 'tls',
            username: '',
            password: ''
          };
        },
        async loadEmailAccounts() {
          const data = await this.api('/api/tools/email');
          this.emailAccounts = data.accounts || [];
          if (!this.emailForm.project_id && this.projects[0]) this.emailForm.project_id = this.projects[0].id;
        },
        async saveEmailAccount() {
          this.emailMessage = '';
          this.emailError = false;
          try {
            await this.api('/api/tools/email', { method: 'POST', body: this.emailForm });
            this.emailForm = this.blankEmailForm();
            await this.loadEmailAccounts();
            this.emailMessage = 'Mailbox saved.';
          } catch (error) {
            this.emailError = true;
            this.emailMessage = error.message;
          }
        },
        async deleteEmailAccount(account) {
          if (!window.confirm('Delete ' + account.from_email + '?')) return;
          this.emailMessage = '';
          this.emailError = false;
          await this.api('/api/tools/email/' + account.id + '/delete', { method: 'POST', body: {} });
          await this.loadEmailAccounts();
          this.emailMessage = 'Mailbox deleted.';
        },
        blankFtpForm() {
          return {
            project_id: this.projectId || (this.projects[0] ? this.projects[0].id : 0),
            label: '',
            host: '',
            port: 21,
            security: 'ftp',
            username: '',
            password: ''
          };
        },
        async loadFtpAccounts() {
          const data = await this.api('/api/tools/ftp');
          this.ftpAccounts = data.accounts || [];
          if (!this.ftpForm.project_id && this.projects[0]) this.ftpForm.project_id = this.projects[0].id;
        },
        async saveFtpAccount() {
          this.ftpMessage = '';
          this.ftpError = false;
          try {
            await this.api('/api/tools/ftp', { method: 'POST', body: this.ftpForm });
            this.ftpForm = this.blankFtpForm();
            await this.loadFtpAccounts();
            this.ftpMessage = 'FTP login saved.';
          } catch (error) {
            this.ftpError = true;
            this.ftpMessage = error.message;
          }
        },
        async loadMcpServers() {
          const data = await this.api('/api/tools/mcp');
          this.mcpServers = data.servers || [];
        },
        fillMcpPick(pick) {
          this.mcpMessage = '';
          this.mcpError = false;
          this.mcpForm = { label: pick.label, target: pick.target, env: pick.env };
        },
        async saveMcpServer() {
          this.mcpMessage = '';
          this.mcpError = false;
          try {
            await this.api('/api/tools/mcp', { method: 'POST', body: this.mcpForm });
            this.mcpForm = { label: '', target: '', env: '' };
            await this.loadMcpServers();
            this.mcpMessage = 'MCP server saved.';
          } catch (error) {
            this.mcpError = true;
            this.mcpMessage = error.message;
          }
        },
        async refreshMcpServer(server) {
          this.mcpMessage = '';
          this.mcpError = false;
          this.mcpBusyId = server.id;
          try {
            await this.api('/api/tools/mcp/' + server.id + '/refresh', { method: 'POST', body: {} });
            await this.loadMcpServers();
            this.mcpMessage = 'Tools loaded for ' + server.label + '.';
          } catch (error) {
            this.mcpError = true;
            this.mcpMessage = error.message;
          } finally {
            this.mcpBusyId = 0;
          }
        },
        async deleteMcpServer(server) {
          if (!window.confirm('Delete ' + server.label + '?')) return;
          this.mcpMessage = '';
          this.mcpError = false;
          await this.api('/api/tools/mcp/' + server.id + '/delete', { method: 'POST', body: {} });
          await this.loadMcpServers();
          this.mcpMessage = 'MCP server deleted.';
        },
        async deleteFtpAccount(account) {
          if (!window.confirm('Delete ' + account.label + '?')) return;
          this.ftpMessage = '';
          this.ftpError = false;
          await this.api('/api/tools/ftp/' + account.id + '/delete', { method: 'POST', body: {} });
          await this.loadFtpAccounts();
          this.ftpMessage = 'FTP login deleted.';
        },
        modelChoices(key) {
          const names = this.ollamaModels.map((model) => model.name).filter(Boolean);
          const current = String(this.settings[key] || '');
          if (current && names.indexOf(current) === -1) names.unshift(current);
          return names;
        },
        async loadUpdate() {
          try {
            this.updateInfo = await this.api('/api/setup/update');
          } catch (error) {
            this.updateInfo = null;
          }
        },
        async runUpdate() {
          if (!this.updateInfo || !this.updateInfo.available || this.updateInfo.blocked || this.updateBusy) return;
          this.updateBusy = true;
          this.updateMessage = 'Update started. The page reloads when it finishes.';
          try {
            await this.api('/api/setup/update', { method: 'POST', body: {} });
            this.waitForRestart();
          } catch (error) {
            this.updateMessage = error.message;
            this.updateBusy = false;
          }
        },
        async waitForRestart() {
          let sawDown = false;
          for (let attempt = 0; attempt < 40; attempt += 1) {
            await new Promise(function (resolve) { setTimeout(resolve, 1500); });
            let up = false;
            try {
              const res = await fetch('/api/session', { cache: 'no-store' });
              up = res.ok;
            } catch (error) {
              up = false;
            }
            if (!up) sawDown = true;
            if (sawDown && up) {
              location.reload();
              return;
            }
          }
          location.reload();
        },
        async restartApp() {
          if (this.restarting) return;
          if (!window.confirm('Restart the application? Loaded models are cleared from memory and the current reply stops.')) return;
          this.restartError = '';
          this.restarting = true;
          try {
            await this.api('/api/setup/restart', { method: 'POST', body: {} });
            this.waitForRestart();
          } catch (error) {
            this.restartError = error.message;
            this.restarting = false;
          }
        },
        async saveSetup() {
          this.setupMessage = '';
          const body = {
            agent_name: this.settings.agent_name,
            model_mode: this.settings.model_mode,
            local_model: this.settings.local_model,
            cpu_failsafe_model: this.settings.cpu_failsafe_model,
            cloud_base_url: this.settings.cloud_base_url,
            cloud_model: this.settings.cloud_model,
            cloud_api_use: this.settings.cloud_api_use,
            cloud_max_turns: Number(this.settings.cloud_max_turns),
            chat_font_size: this.chatFontPx,
            search_provider: this.settings.search_provider === 'bing' ? 'bing' : 'duckduckgo',
            ai_act_applies: this.settings.ai_act_applies || ''
          };
          if (this.cloudKeyDraft) body.cloud_api_key = this.cloudKeyDraft;
          const data = await this.api('/api/setup', { method: 'POST', body: body });
          this.applySettings(data);
          this.cloudKeyDraft = '';
          this.setupMessage = 'Saved.';
          await this.refreshModelLoad();
        },
        async clearCloudKey() {
          this.setupMessage = '';
          const data = await this.api('/api/setup', {
            method: 'POST',
            body: {
              model_mode: this.settings.model_mode,
              cloud_api_use: this.settings.cloud_api_use,
              clear_cloud_api_key: true
            }
          });
          this.applySettings(data);
          this.cloudKeyDraft = '';
          this.setupMessage = 'Saved.';
        },
        async testProvider() {
          this.providerTestBusy = true;
          this.providerTestMessage = '';
          this.providerTestError = false;
          try {
            const data = await this.api('/api/setup/provider-test', { method: 'POST', body: {} });
            this.providerTestMessage = data.message || 'Success. The external model replied.';
          } catch (error) {
            this.providerTestError = true;
            this.providerTestMessage = error.message;
          } finally {
            this.providerTestBusy = false;
          }
        },
        async loadOllamaModels() {
          try {
            const data = await this.api('/api/setup/models');
            this.ollamaModels = data.models || [];
            this.ollamaError = data.error || '';
          } catch (error) {
            this.ollamaModels = [];
            this.ollamaError = error.message;
          }
        },
        async pullOllamaModel() {
          const name = this.ollamaPullName.trim();
          if (!name || this.ollamaBusy) return;
          this.ollamaBusy = true;
          this.ollamaError = '';
          this.ollamaProgress = 'Starting download of ' + name + '…';
          try {
            const res = await fetch('/api/setup/models/pull', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ name: name })
            });
            if (res.status === 401) {
              this.authed = false;
              return;
            }
            if (!res.ok || !res.body) {
              const data = await res.json().catch(function () { return {}; });
              throw new Error(data.error || 'Could not install the model.');
            }
            await this.readModelPull(res.body);
            this.ollamaPullName = '';
            this.ollamaProgress = name + ' is installed.';
            await this.loadOllamaModels();
          } catch (error) {
            this.ollamaError = error.message;
            this.ollamaProgress = '';
          } finally {
            this.ollamaBusy = false;
          }
        },
        async readModelPull(body) {
          const reader = body.getReader();
          const decoder = new TextDecoder();
          let buf = '';
          let finished = false;
          const take = (raw) => {
            if (!raw || raw.charAt(0) === ':') return;
            let event = 'message';
            const dataLines = [];
            raw.split('\n').forEach((line) => {
              if (line.indexOf('event:') === 0) event = line.slice(6).trim();
              if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).trim());
            });
            if (!dataLines.length) return;
            let data = {};
            try { data = JSON.parse(dataLines.join('\n')); } catch (error) { return; }
            if (event === 'error') throw new Error(data.error || 'Could not install the model.');
            if (event === 'done') finished = true;
            if (event === 'progress') {
              let text = data.status || 'Working';
              if (data.total && data.completed != null) {
                text += ' ' + Math.min(100, Math.round(data.completed / data.total * 100)) + '%';
              }
              this.ollamaProgress = text;
            }
          };
          while (true) {
            const chunk = await reader.read();
            if (chunk.done) break;
            buf += decoder.decode(chunk.value, { stream: true });
            const parts = buf.split('\n\n');
            buf = parts.pop() || '';
            parts.forEach(take);
          }
          if (buf.trim()) take(buf);
          if (!finished) throw new Error('The download stopped before it finished.');
        },
        async deleteOllamaModel(model) {
          if (this.ollamaBusy || model.protected) return;
          if (!window.confirm('Delete ' + model.name + ' from Ollama?')) return;
          this.ollamaBusy = true;
          this.ollamaError = '';
          this.ollamaProgress = '';
          try {
            await this.api('/api/setup/models/delete', { method: 'POST', body: { name: model.name } });
            await this.loadOllamaModels();
          } catch (error) {
            this.ollamaError = error.message;
          } finally {
            this.ollamaBusy = false;
          }
        },
        async setSystemControl(enabled) {
          this.systemControlMessage = '';
          this.systemControlError = false;
          try {
            const body = { enabled: !!enabled };
            if (enabled) body.password = this.systemControlPassword;
            const data = await this.api('/api/setup/system-control', { method: 'POST', body: body });
            this.applySettings(data);
            this.systemControlPassword = '';
            this.systemControlMessage = enabled ? 'Full system control is on.' : 'Full system control is off.';
          } catch (error) {
            this.systemControlError = true;
            this.systemControlMessage = error.message;
          }
        },
        async changePassword() {
          this.passwordMessage = '';
          try {
            await this.api('/api/setup/password', { method: 'POST', body: this.passwordForm });
            this.passwordForm = { current: '', next: '', confirm: '' };
            this.passwordMessage = 'Password updated.';
          } catch (error) {
            this.passwordMessage = error.message;
          }
        },
        ensureSkillSelection() {
          if (this.skillCreating) return;
          if (this.selectedSkillId && this.skills.some((skill) => skill.id === this.selectedSkillId)) return;
          const first = this.sortedSkills[0];
          this.selectedSkillId = first ? first.id : 0;
          this.skillEditing = false;
        },
        openSkill(skill) {
          this.skillCreating = false;
          this.skillEditing = false;
          this.skillError = '';
          this.selectedSkillId = skill.id;
        },
        newSkill() {
          this.skillError = '';
          this.skillCreating = true;
          this.skillEditing = true;
          this.skillForm = {
            name: 'my_skill',
            description: 'What this skill does',
            filename: 'my_skill.php',
            source: "<?php\n/*\n---\nname: my_skill\ndescription: What this skill does\n---\n*/\n$raw = stream_get_contents(STDIN);\n$args = json_decode($raw ?: '', true) ?: [];\necho \"ok\\n\";\n",
            is_active: 1
          };
        },
        editSkill(skill) {
          if (!skill || skill.created_by === 'system') return;
          this.skillError = '';
          this.skillCreating = false;
          this.skillEditing = true;
          this.selectedSkillId = skill.id;
          this.skillForm = {
            id: skill.id,
            name: skill.name,
            description: skill.description,
            filename: skill.script_path,
            source: skill.source || '',
            is_active: skill.is_active
          };
        },
        cancelSkillEdit() {
          this.skillCreating = false;
          this.skillEditing = false;
          this.skillError = '';
          this.ensureSkillSelection();
        },
        async saveSkillForm() {
          this.skillError = '';
          try {
            const data = await this.api('/api/skills', { method: 'POST', body: this.skillForm });
            this.skills = (await this.api('/api/skills')).skills;
            this.skillCreating = false;
            this.skillEditing = false;
            this.selectedSkillId = data.skill && data.skill.id ? data.skill.id : 0;
            this.ensureSkillSelection();
          } catch (error) {
            this.skillError = error.message;
          }
        },
        async toggleSkill(skill, active) {
          await this.api('/api/skills/' + skill.id + '/toggle', { method: 'POST', body: { active: active } });
          skill.is_active = active ? 1 : 0;
        },
        async deleteSkill(skill) {
          if (skill.created_by === 'system') return;
          if (!window.confirm('Delete ' + skill.name + '?')) return;
          this.skillError = '';
          try {
            await this.api('/api/skills/' + skill.id + '/delete', { method: 'POST', body: {} });
            this.skills = (await this.api('/api/skills')).skills;
            if (this.selectedSkillId === skill.id) this.selectedSkillId = 0;
            this.skillEditing = false;
            this.skillCreating = false;
            this.ensureSkillSelection();
          } catch (error) {
            this.skillError = error.message;
          }
        },
        async refreshHealth() {
          try {
            const usage = await this.api('/api/setup/health', { quiet: true });
            this.hardware = Object.assign({}, this.hardware || {}, { usage: usage });
          } catch (error) {}
        },
        async refreshModelLoad(quiet) {
          try {
            this.modelLoad = await this.api('/api/model/load', { quiet: !!quiet });
            if (this.modelLoad && typeof this.modelLoad.cpu === 'boolean' && this.settings && this.settings.model_mode !== 'cpu_failsafe') {
              this.settings.cpu_fallback = this.modelLoad.cpu ? '1' : '0';
            }
            if (this.modelLoad && (this.modelLoad.ready === true || this.modelLoad.error)) {
              this.awaitingModel = false;
              this.slowReply = false;
              this.clearSlowTimer();
            }
          } catch (error) {
            if (this.modelLoad && this.modelLoad.ready === true) return;
            this.modelLoad = { ready: false, percent: this.modelLoad ? this.modelLoad.percent : 0, model: this.modelLoad ? this.modelLoad.model : '', error: error.message, scratch: true };
            this.awaitingModel = false;
          }
        },
        startPoll() {
          this.stopPoll();
          this.pollTimer = setInterval(() => {
            if (!this.authed) return;
            if (!document.hidden) {
              this.refreshModelLoad(true).then(() => this.maybeSendProjectOpener()).catch(function () {});
              if (this.tab === 'tasks') this.reloadTasks();
              if (this.settings && this.settings.hitl_external_actions === '1' && (this.tab === 'chat' || this.sending)) {
                this.refreshPendingActions().catch(function () {});
              }
              if (this.tab === 'setup') {
                this.healthTick += 1;
                if (this.healthTick >= 5) {
                  this.healthTick = 0;
                  this.refreshHealth();
                }
              }
            }
            if (this.tab === 'agents') {
              this.refreshStatus(true).catch(function () {});
              this.refreshLogs(true).catch(function () {});
              this.refreshLoop(true).catch(function () {});
              if (!document.hidden) this.refreshPendingActions().catch(function () {});
            }
          }, 1000);
        },
        stopPoll() {
          if (this.pollTimer) clearInterval(this.pollTimer);
          this.pollTimer = null;
        },
        toggleAllBackup(on) {
          this.backupFolders = on ? this.projects.map((project) => project.folder_name) : [];
        },
        async downloadBackup() {
          if (this.backupBusy || !this.backupFolders.length) return;
          this.backupError = '';
          this.backupBusy = true;
          try {
            const res = await fetch('/api/setup/backup', {
              method: 'POST',
              cache: 'no-store',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ folders: this.backupFolders })
            });
            if (res.status === 401) {
              this.authed = false;
              throw new Error('Unauthorized');
            }
            if (!res.ok) {
              const data = await res.json().catch(function () { return {}; });
              throw new Error(data.error || 'Could not create the backup.');
            }
            const blob = await res.blob();
            const match = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '');
            const name = match ? match[1] : 'danaco-backup.zip';
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
          } catch (error) {
            this.backupError = error.message;
          } finally {
            this.backupBusy = false;
          }
        },
        async stageRestore(event) {
          const input = event.target;
          const file = input.files && input.files[0];
          input.value = '';
          if (!file || this.restoreBusy) return;
          this.restoreError = '';
          this.restoreMessage = '';
          this.restoreChoices = {};
          this.restoreConflicts = [];
          this.restoreBusy = true;
          try {
            const body = new FormData();
            body.append('file', file);
            const res = await fetch('/api/setup/backup/preview', { method: 'POST', cache: 'no-store', body: body });
            const data = await res.json().catch(function () { return {}; });
            if (res.status === 401) {
              this.authed = false;
              throw new Error(data.error || 'Unauthorized');
            }
            if (!res.ok) throw new Error(data.error || 'Could not read that backup.');
            this.restoreToken = data.token || '';
            this.restoreCount = (data.projects || []).length;
            if (data.conflicts && data.conflicts.length) {
              const choices = {};
              data.conflicts.forEach(function (item) { choices[item.folder] = ''; });
              this.restoreChoices = choices;
              this.restoreConflicts = data.conflicts;
              return;
            }
            await this.finishRestore();
          } catch (error) {
            this.restoreError = error.message;
          } finally {
            this.restoreBusy = false;
          }
        },
        restoreReady() {
          return this.restoreConflicts.every((item) => ['overwrite', 'ignore', 'keep'].includes(this.restoreChoices[item.folder]));
        },
        async confirmRestore() {
          if (this.restoreBusy || !this.restoreReady()) return;
          this.restoreError = '';
          this.restoreBusy = true;
          try {
            await this.finishRestore();
          } catch (error) {
            this.restoreError = error.message;
          } finally {
            this.restoreBusy = false;
          }
        },
        async finishRestore() {
          const data = await this.api('/api/setup/backup/restore', {
            method: 'POST',
            body: { token: this.restoreToken, choices: this.restoreChoices }
          });
          this.restoreToken = '';
          this.restoreMessage = (data.lines || []).join(' ');
          this.restoreConflicts = [];
          await this.reloadProjects();
        },
        async cancelRestore() {
          const token = this.restoreToken;
          this.restoreConflicts = [];
          this.restoreChoices = {};
          this.restoreToken = '';
          this.restoreError = '';
          if (!token) return;
          try {
            await this.api('/api/setup/backup/cancel', { method: 'POST', body: { token: token } });
          } catch (error) {
          }
        },
        async reloadProjects() {
          const previous = this.projectId;
          const data = await this.api('/api/projects');
          this.projects = data.projects;
          if (this.projectId !== 0 && !this.projects.some((project) => project.id === this.projectId)) {
            this.projectId = 0;
          }
          this.backupFolders = this.backupFolders.filter((folder) => this.projects.some((project) => project.folder_name === folder));
          if (this.tab === 'setup') {
            await this.loadEmailAccounts();
            await this.loadFtpAccounts();
          }
          if (this.projectId !== previous) await this.onProjectChange();
        }
      }
    });

    app.component('activity-trace', {
      name: 'activity-trace',
      props: {
        thinking: { type: Array, default: function () { return []; } },
        tools: { type: Array, default: function () { return []; } },
        open: { type: Object, default: function () { return {}; } },
        prefix: { type: String, default: '' },
        cpu: { type: Boolean, default: false }
      },
      emits: ['toggle'],
      methods: {
        label(name) {
          let label = 'Agent';
          if (name === 'orchestrator') label = 'Main agent';
          else {
            const text = String(name || '');
            if (text) label = text.charAt(0).toUpperCase() + text.slice(1);
          }
          if (this.cpu && name === 'orchestrator') return label + ' (CPU)';
          return label;
        },
        body(tool) {
          let text = 'Arguments\n' + JSON.stringify(tool.args || {}, null, 2);
          let result = String(tool.result || '');
          if (result.startsWith('Tool error: ')) result = 'Error: ' + result.slice('Tool error: '.length);
          else if (result && !result.startsWith('Error:') && /did not reply|not finish/i.test(result)) result = 'Error: ' + result;
          if (result) text += '\n\nResult\n' + result;
          return text;
        }
      },
      template: '<div v-if="thinking.length || tools.length" class="trace"><div v-for="(block, index) in thinking" :key="prefix + \'t\' + index" class="trace-item"><button type="button" class="trace-toggle" @click="$emit(\'toggle\', prefix + \'t\' + index)">{{ open[prefix + \'t\' + index] ? "▾" : "▸" }} {{ label(block.agent) }} thinking</button><pre v-if="open[prefix + \'t\' + index]" class="trace-body">{{ block.text }}</pre></div><div v-for="(tool, index) in tools" :key="prefix + \'c\' + index" class="trace-item"><button type="button" class="trace-toggle" @click="$emit(\'toggle\', prefix + \'c\' + index)">{{ open[prefix + \'c\' + index] ? "▾" : "▸" }} {{ label(tool.agent) }} · {{ tool.name }}</button><pre v-if="open[prefix + \'c\' + index]" class="trace-body">{{ body(tool) }}</pre></div></div>'
    });
    app.component('file-tree', {
      name: 'file-tree',
      props: {
        nodes: { type: Array, default: function () { return []; } },
        selected: { type: String, default: '' },
        overKey: { type: String, default: '' }
      },
      emits: ['open', 'menu', 'move', 'hover'],
      data: function () {
        return { dropped: false };
      },
      methods: {
        key(node) {
          return (node.scope || '') + '\n' + (node.folder || '') + '\n' + (node.path || '');
        },
        toggle(node, event) {
          if (node.type !== 'dir') return;
          event.stopPropagation();
          node.open = !node.open;
        },
        onClick(node) {
          if (this.dropped) {
            this.dropped = false;
            return;
          }
          this.$emit('open', node);
        },
        onMenu(node, event) {
          event.preventDefault();
          event.stopPropagation();
          if (!node.path) return;
          this.$emit('menu', {
            x: event.clientX,
            y: event.clientY,
            node: {
              name: node.name || '',
              path: node.path || '',
              type: node.type || 'file',
              scope: node.scope || 'project',
              folder: node.folder || ''
            }
          });
        },
        canDrop(node) {
          const source = treeDrag;
          if (!source || !source.path || !node || node.type !== 'dir') return false;
          if ((source.scope || '') === (node.scope || '') && (source.folder || '') === (node.folder || '')) {
            const parent = source.path.indexOf('/') === -1 ? '' : source.path.slice(0, source.path.lastIndexOf('/'));
            const destPath = node.path || '';
            if (parent === destPath) return false;
            if (source.type === 'dir' && (destPath === source.path || destPath.indexOf(source.path + '/') === 0)) return false;
          }
          return true;
        },
        onDragStart(node, event) {
          if (!node.path) {
            event.preventDefault();
            return;
          }
          event.stopPropagation();
          treeDrag = {
            name: node.name || '',
            path: node.path || '',
            type: node.type || 'file',
            scope: node.scope || 'project',
            folder: node.folder || ''
          };
          event.dataTransfer.setData('text/plain', node.path);
          event.dataTransfer.effectAllowed = 'move';
        },
        onDragOver(node, event) {
          if (!this.canDrop(node)) return;
          event.preventDefault();
          event.dataTransfer.dropEffect = 'move';
          const id = this.key(node);
          if (this.overKey !== id) this.$emit('hover', id);
        },
        onDragLeave(node, event) {
          if (event.currentTarget.contains(event.relatedTarget)) return;
          if (this.overKey === this.key(node)) this.$emit('hover', '');
        },
        onDrop(node, event) {
          event.preventDefault();
          event.stopPropagation();
          const source = treeDrag;
          this.dropped = true;
          this.$emit('hover', '');
          if (!source || !this.canDrop(node)) return;
          this.$emit('move', { source: source, dest: node });
        }
      },
      template: '<ul class="tree"><li v-for="node in nodes" :key="key(node)"><button type="button" class="tree-btn" :class="{file: node.type!==\'dir\', active: key(node)===selected, drop: node.type===\'dir\' && key(node)===overKey}" :draggable="!!node.path" @click="onClick(node)" @contextmenu="onMenu(node, $event)" @dragstart="onDragStart(node, $event)" @dragover="onDragOver(node, $event)" @dragleave="onDragLeave(node, $event)" @drop="onDrop(node, $event)"><span class="tree-chevron" @click="toggle(node, $event)">{{ node.type==="dir" ? (node.open ? "▾" : "▸") : "" }}</span><svg class="tree-ico" aria-hidden="true"><use :href="node.type===\'dir\' ? \'#i-folder\' : \'#i-file\'"></use></svg><span class="tree-name">{{ node.name }}</span></button><file-tree v-if="node.type===\'dir\' && node.open" :nodes="node.children" :selected="selected" :over-key="overKey" @open="$emit(\'open\', $event)" @menu="$emit(\'menu\', $event)" @move="$emit(\'move\', $event)" @hover="$emit(\'hover\', $event)"></file-tree></li></ul>'
    });
    app.mount('#app');
  }

  if (window.Vue) start();
  else {
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/vue@3/dist/vue.global.prod.js';
    script.onload = start;
    document.head.appendChild(script);
  }
})();
