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
    if (!/^(?:[A-Za-z0-9][A-Za-z0-9._-]*\/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:md|pdf|txt|csv|json|svg|html|png|jpe?g|py|php|js|css)$/.test(text)) {
      return '';
    }
    if (text.indexOf('..') !== -1) return '';
    return text;
  }

  function renderMarkdown(src) {
    const fences = [];
    const cites = [];
    let text = String(src || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
    text = text.replace(/```([^\n]*)\n([\s\S]*?)```/g, function (_, info, code) {
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
      cites.push('<div class="file-cite">' + downloadButton('data-download="' + path + '"') + '<span class="file-cite-name">' + path + '</span></div>');
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
          toolPending: false,
          streamEnded: false,
          steerText: '',
          slowTimer: null,
          slowReply: false,
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
            { id: 'backlog', label: 'Backlog', hint: 'Agent work, or an action to send later' },
            { id: 'in_progress', label: 'In progress', hint: 'Being worked on, or scheduled' },
            { id: 'review', label: 'Review', hint: 'Finished and waiting for you' }
          ],
          finishedOpen: false,
          taskError: '',
          taskFilter: 0,
          taskAddProject: 0,
          taskLoading: false,
          agentNames: ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer', 'human'],
          tree: [],
          filePath: '',
          fileName: '',
          fileScope: '',
          fileFolder: '',
          fileRel: '',
          fileContent: '',
          fileDraft: '',
          fileEditing: false,
          fileError: '',
          showingSearch: false,
          vaultOpen: false,
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
          structuredData: null,
          structuredLoading: false,
          structuredTableName: '',
          agents: [],
          logs: [],
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
          projectForm: { name: '', folder_name: '', purpose: '', folderTouched: false },
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
        thinkingSpinner() {
          return (this.sending || this.awaitingModel) && !this.answerStarted && !this.streamError;
        },
        fileKind() {
          return fileKindOf(this.fileName || this.fileRel);
        },
        filePreview() {
          return this.fileKind === 'pdf' || this.fileKind === 'image';
        },
        structuredKind() {
          return ['csv', 'sqlite', 'docx'].includes(this.fileKind) ? this.fileKind : '';
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
          },
          deep: true
        },
        live() {
          if (this.chatQuery.trim()) this.queueChatSearch(false);
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
        md: renderMarkdown,
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
          this.syncTaskFilter();
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
          if (this.tab === 'tasks') {
            if (!this.taskAddProject) this.taskAddProject = this.projectId || (this.projects[0] ? this.projects[0].id : 0);
            await this.reloadTasks();
          }
          if (this.tab === 'workspace') await this.loadTree();
          if (this.tab === 'agents') {
            await this.refreshLogs();
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
        syncTaskFilter() {
          this.taskFilter = this.projectId || 0;
        },
        async onProjectChange() {
          localStorage.setItem('projectId', String(this.projectId || 0));
          this.syncTaskFilter();
          this.messages = [];
          this.awaitingModel = false;
          this.stickToBottom = true;
          this.attachments = [];
          this.clearFilePreview();
          this.filePath = '';
          this.fileName = '';
          this.fileRel = '';
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
          this.projectForm = { name: '', folder_name: '', purpose: '', folderTouched: false };
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
          try {
            const data = await this.api('/api/projects/' + this.projectEdit.id + '/rename', {
              method: 'POST',
              body: { name: this.projectEditName }
            });
            const index = this.projects.findIndex((item) => item.id === data.project.id);
            if (index >= 0) this.projects.splice(index, 1, data.project);
            this.projects.sort((a, b) => a.name.localeCompare(b.name));
            this.closeProjectEdit();
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
        onProjectName() {
          if (this.projectForm.folderTouched) return;
          this.projectForm.folder_name = this.projectForm.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);
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
            const purpose = String((data.project && data.project.purpose) || '').trim();
            this.projectOpener = {
              projectId: data.project.id,
              text: 'We are now starting a new project. ' + purpose + '\n\nAsk me simple questions to help me outline the project systematically',
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
        contextFull() {
          return this.context.total > 0 && this.context.used >= this.context.total;
        },
        contextDash() {
          const length = 2 * Math.PI * 14.75;
          const total = this.context.total || 0;
          const ratio = total > 0 ? Math.min(1, (this.context.used || 0) / total) : 0;
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
          const log = this.logLatest[agent.name] || this.logs.find((item) => item.agent_name === agent.name);
          if (!log) return agent.label;
          return this.logPreview(log.content);
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
        agentLabel(name) {
          if (name === 'orchestrator') return 'Main agent';
          const text = String(name || '');
          if (!text) return '';
          return text.charAt(0).toUpperCase() + text.slice(1);
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
          if (!this.projectId || !files.length) return;
          this.streamError = '';
          for (const file of files) {
            const body = new FormData();
            body.append('project_id', String(this.projectId));
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
            if (this.messages[i].role === 'user') {
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
          const users = this.messages.filter((msg) => msg.role === 'user');
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
            if (error.name === 'AbortError') {
              aborted = true;
              if ((this.projectId || 0) === chatProject) this.flushLive();
            } else if (error.message === 'The connection dropped before the reply finished.' && (this.projectId || 0) === chatProject) {
              this.flushLive();
              this.messages.push({
                id: 'fail-' + Date.now(),
                role: 'assistant',
                agent_name: 'orchestrator',
                content: 'Error: The connection dropped before the reply finished.',
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
          const value = String((msg && msg.content) || '');
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
            if (this.sending && !this.live) this.slowReply = true;
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
          if (event === 'token') {
            this.slowReply = false;
            this.clearSlowTimer();
            if (this.live && this.liveAgent !== data.agent) this.flushLive();
            this.liveAgent = data.agent || this.liveAgent;
            this.live += data.text || '';
            if (data.text) this.answerStarted = true;
            this.scrollChat();
          } else if (event === 'thinking') {
            this.slowReply = false;
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
            this.noteTool(data);
            this.scrollChat();
          } else if (event === 'tool_result') {
            this.noteToolResult(data);
            this.scrollChat();
          } else if (event === 'activity') {
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
          } else if (event === 'status' && data.agent) {
            const found = this.agents.find((agent) => agent.name === data.agent);
            if (found) {
              found.status = data.status;
              found.label = ({
                idle: 'Idle',
                thinking: 'Thinking',
                executing_tool: 'Executing Tool',
                completed: 'Completed',
                error: 'Error'
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
          })[value] || 'none';
        },
        columnStatus(task) {
          if (task.status === 'done' || task.status === 'review' || task.status === 'in_progress') return task.status;
          if (task.schedule_time || (task.recurrence_interval && task.recurrence_interval !== 'none')) return 'in_progress';
          return 'backlog';
        },
        finishedTasks() {
          return this.tasks.filter((task) => task.status === 'done' && (!this.taskFilter || task.project_id === this.taskFilter))
            .sort((a, b) => String(b.finished_at || b.created_at || '').localeCompare(String(a.finished_at || a.created_at || '')) || (b.id - a.id));
        },
        finishedVisible() {
          const all = this.finishedTasks();
          return this.finishedOpen ? all : all.slice(0, 4);
        },
        tasksFor(status) {
          return this.tasks.filter((task) => {
            if (this.columnStatus(task) !== status) return false;
            if (!this.taskFilter) return true;
            return task.project_id === this.taskFilter;
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
          let projectId = this.taskFilter || this.taskAddProject;
          if (!projectId && this.projects.length === 1) projectId = this.projects[0].id;
          if (!projectId) {
            this.taskError = this.projects.length ? 'Choose a project for this task.' : 'Create a project first.';
            return;
          }
          try {
            await this.api('/api/tasks', {
              method: 'POST',
              body: { project_id: projectId, title: title, status: 'backlog' }
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
          this.taskEdit = {
            title: task.title,
            description: task.description || '',
            assigned_agent: task.assigned_agent,
            schedule_time: (task.schedule_time || '').replace(' ', 'T').slice(0, 16),
            recurrence_interval: task.recurrence_interval || 'none'
          };
        },
        async saveTask(task) {
          const body = {
            title: this.taskEdit.title,
            description: this.taskEdit.description,
            assigned_agent: this.taskEdit.assigned_agent,
            schedule_time: this.taskEdit.schedule_time ? this.taskEdit.schedule_time.replace('T', ' ') + ':00' : null,
            recurrence_interval: this.taskEdit.recurrence_interval
          };
          if (task.status === 'review' && (this.taskEdit.description || '').trim() !== (task.description || '').trim() && (this.taskEdit.description || '').trim() !== '') {
            body.status = 'in_progress';
          }
          const saved = await this.api('/api/tasks/' + task.id, { method: 'PATCH', body: body });
          Object.assign(task, saved.task);
          if (saved.task.status !== 'review') this.openTask = null;
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
        onDragStart(task, event) {
          this.dragId = task.id;
          event.dataTransfer.setData('text/plain', String(task.id));
          event.dataTransfer.effectAllowed = 'move';
        },
        async onDrop(status, event) {
          const id = Number(event.dataTransfer.getData('text/plain') || this.dragId);
          const task = this.tasks.find((item) => item.id === id);
          if (!task || this.columnStatus(task) === status) return;
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
          if (!node || node.type === 'dir') return;
          const generation = (this.fileLoad || 0) + 1;
          this.fileLoad = generation;
          const name = node.name || '';
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
        async deleteFile() {
          const name = this.fileName || 'this file';
          if (!window.confirm('Move "' + name + '" to the agent trashbin?')) return;
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
        async downloadProjectFile(path, button) {
          if (!this.projectId) {
            if (button) button.title = 'Open a project to download this file.';
            return;
          }
          const res = await fetch('/api/workspace/download?project_id=' + encodeURIComponent(this.projectId) + '&path=' + encodeURIComponent(path), { cache: 'no-store' });
          if (res.status === 401) {
            this.authed = false;
            return;
          }
          if (!res.ok) {
            const data = await res.json().catch(function () { return {}; });
            if (button) button.title = data.error || 'Could not download that file.';
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
        async searchWorkspace() {
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
        async restoreItem(kind, storedAs) {
          await this.api('/api/workspace/restore', { method: 'POST', body: { kind: kind, stored_as: storedAs } });
          await this.loadTree();
          const data = await this.api('/api/workspace/vault');
          this.backups = data.backups;
          this.trash = data.trash;
        },
        async refreshStatus(quiet) {
          this.agents = (await this.api('/api/agents/status', { quiet: !!quiet })).agents;
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
          if (this.ollamaBusy) return;
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
            if (this.modelLoad && (this.modelLoad.ready === true || this.modelLoad.error)) this.awaitingModel = false;
          } catch (error) {
            if (this.modelLoad && this.modelLoad.ready === true) return;
            this.modelLoad = { ready: false, percent: this.modelLoad ? this.modelLoad.percent : 0, model: this.modelLoad ? this.modelLoad.model : '', error: error.message, scratch: true };
            this.awaitingModel = false;
          }
        },
        startPoll() {
          this.stopPoll();
          this.pollTimer = setInterval(() => {
            if (!this.authed || document.hidden) return;
            this.refreshModelLoad(true).then(() => this.maybeSendProjectOpener()).catch(function () {});
            if (this.tab === 'tasks') this.reloadTasks();
            if (this.tab === 'agents') {
              this.refreshStatus(true).catch(function () {});
              this.refreshLogs(true).catch(function () {});
              this.refreshPendingActions().catch(function () {});
            }
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
        prefix: { type: String, default: '' }
      },
      emits: ['toggle'],
      methods: {
        label(name) {
          if (name === 'orchestrator') return 'Main agent';
          const text = String(name || '');
          if (!text) return 'Agent';
          return text.charAt(0).toUpperCase() + text.slice(1);
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
        selected: { type: String, default: '' }
      },
      emits: ['open'],
      methods: {
        key(node) {
          return (node.scope || '') + '\n' + (node.folder || '') + '\n' + (node.path || '');
        }
      },
      template: '<ul class="tree"><li v-for="node in nodes" :key="key(node)"><button v-if="node.type===\'dir\'" type="button" class="tree-btn" @click="node.open = !node.open"><span class="tree-chevron">{{ node.open ? "▾" : "▸" }}</span><svg class="tree-ico" aria-hidden="true"><use href="#i-folder"></use></svg><span class="tree-name">{{ node.name }}</span></button><button v-else type="button" class="tree-btn file" :class="{active: key(node)===selected}" @click="$emit(\'open\', node)"><span class="tree-chevron"></span><svg class="tree-ico" aria-hidden="true"><use href="#i-file"></use></svg><span class="tree-name">{{ node.name }}</span></button><file-tree v-if="node.type===\'dir\' && node.open" :nodes="node.children" :selected="selected" @open="$emit(\'open\', $event)"></file-tree></li></ul>'
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
