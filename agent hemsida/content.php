<?php

return [
    'hero' => [
        'kicker' => 'Self-hosted agent framework',
        'lines' => ['The AI agent framework', 'for local hardware.', 'Zero monthly fees.'],
        'lede' => 'Agent Danaco is a self-hosted orchestration toolkit for project work. You configure models and tools on your own hardware. Your files stay on your machine unless you choose a cloud model.',
        'note' => 'Requires Linux.',
    ],
    'shots' => [
        ['src' => '/assets/screen-agents.jpg', 'title' => 'Agent overview', 'alt' => 'Agent overview with the main agent and subagents'],
        ['src' => '/assets/screen-kanban.jpg', 'title' => 'Kanban task board', 'alt' => 'Kanban task board with backlog, in progress, and review'],
        ['src' => '/assets/screen-chat.jpg', 'title' => 'Chat', 'alt' => 'Project chat with the agent'],
    ],
    'trust' => [
        ['title' => 'Local by default', 'text' => 'Your files stay on your machine. Cloud models are optional.'],
        ['title' => 'One-time install', 'text' => 'No subscriptions. No recurring costs.'],
        ['title' => 'Smart workflows', 'text' => 'Automation that remembers your business logic.'],
    ],
    'requirements' => [
        'kicker' => 'Requirements',
        'title' => 'Recommended hardware.',
        'items' => [
            ['title' => 'Linux', 'text' => 'The system Agent Danaco runs on.'],
            ['title' => '8 GB RAM', 'text' => 'The minimum memory.'],
            ['title' => '16 GB GPU', 'text' => 'Recommended for local models.'],
        ],
        'note' => 'During installation, Agent Danaco finds and installs the best suitable local model automatically.',
    ],
    'values' => [
        'kicker' => 'Why Agent Danaco',
        'title' => 'An agent framework you can actually keep.',
        'cards' => [
            ['title' => 'You own your data', 'text' => 'SaaS tools read your emails to train their models. Agent Danaco runs on your machine. Cloud models are opt-in.'],
            ['title' => 'Automate without coding', 'text' => 'Designed for project managers and business leads. Describe procedures in natural language; the runtime executes with the tools you enable.'],
            ['title' => 'Unbounded intelligence', 'text' => 'No per-user API rate limit on the local runtime. The more capable your hardware, the more work it can take on.'],
        ],
    ],
    'steps' => [
        'kicker' => 'How it works',
        'title' => 'Get started within minutes.',
        'items' => [
            ['title' => 'Install', 'text' => 'Clone from GitHub or use the packaged installer, then answer a short set of questions.'],
            ['title' => 'Configure', 'text' => 'Choose models and tools. You decide what the framework may reach on your machine.'],
            ['title' => 'Delegate', 'text' => 'Assign work in natural language and follow it on the board.'],
        ],
    ],
    'terminal' => [
        'kicker' => 'Productivity',
        'title' => 'Get. Shit. Done.',
        'prompt' => 'Build me a million-dollar business online.',
        'lines' => ['[Searching Local DB...] [Reading Context...]', 'Business plan locked in. Will now build your website...'],
    ],
    'problem' => [
        'kicker' => 'The local-agent problem',
        'title' => 'The framework that just works.',
        'lede' => 'Stop fighting fragile setup scripts, context bloat, and lost files.',
        'pains' => [
            ['title' => 'Simple setup', 'text' => 'Click on the installer, follow the instructions and get started within minutes. All ready to go directly from the start.'],
            ['title' => 'Less context bloat', 'text' => 'Chat histories can otherwise fill the window, then the model starts to hallucinate or runs out of memory.'],
        ],
        'fixes' => [
            ['title' => 'Hub-and-spoke orchestrator', 'text' => 'Subagents do not talk in a loop. The main agent delegates and follows up, so you only have one point of entry.'],
            ['title' => 'Trashcan and backups', 'text' => 'Every file change gets a timestamped backup. Deletes go to a trashcan the model cannot touch, to prevent accidental deletions.'],
            ['title' => 'Dual-scope wiki', 'text' => 'Shared memory stays separate from a project workspace. Delete the project folder without leaving stale context behind.'],
            ['title' => 'Skills for repetitive work', 'text' => 'The agent can build its own skills for repetitive work, to make it faster and guaranteed to work over and over again.'],
            ['title' => 'Three model tiers', 'text' => 'A local model, an optional cloud API, and a small CPU failsafe if the GPU runs out of memory.'],
            ['title' => 'Kanban, not cron', 'text' => 'Tasks sorted as Backlog, In Progress, Review, Done! Schedule and reassign work on a board you can see.'],
        ],
    ],
    'features' => [
        'kicker' => 'What you get',
        'title' => 'Built for long-running projects.',
        'highlights' => [],
        'updates' => [
            'kicker' => 'Always free to upgrade',
            'title' => 'Free updates, on your schedule.',
            'text' => 'New versions stay free. You install them manually from the Setup page when you are ready — no auto-billing, no surprise subscriptions, no forced cloud account.',
        ],
        'list' => [
            'Accessible from the browser on any device on the same LAN',
            'Markdown wiki and QMD semantic search',
            'Core files kept separated from the workspace folders',
            'Guardrails against looped thinking and stalled work',
            'Pre-built subagents, including a consultant that spends cloud tokens only when needed',
            'One continuous chat per project',
            'File explorer for the wiki and work files',
            'MCP connections for outside tools',
            'Automatic context-window handling',
        ],
    ],
    'compliance' => [
        'kicker' => 'EU AI Act',
        'title' => 'Built with the EU AI Act in mind.',
        'lede' => 'Most local agent projects ignore regulation until someone asks. Agent Danaco is an open-source, self-hosted framework designed from the start so business operators can document their role, keep an optional audit trail, and stay clear of high-risk misuse — rare among open-source local agents.',
        'points' => [
            ['title' => 'Clear roles', 'text' => 'Danaco supplies the framework. You operate the deployment, choose models and tools, and remain responsible for lawful use.'],
            ['title' => 'Optional compliance tools', 'text' => 'Setup asks whether the EU AI Act applies. When it does, open Compliance & documentation for Terms, a system card export, and optional logging or approvals.'],
            ['title' => 'Autonomy first', 'text' => 'Extra human approval gates stay off by default. The agent keeps working; stricter oversight is there when your organisation needs it.'],
        ],
        'note' => 'Not legal advice and not a conformity assessment. Intended for general project work — not Annex III high-risk uses.',
    ],
    'compare' => [
        'kicker' => 'Compared',
        'title' => 'Your server, or theirs.',
        'headers' => ['', 'Agent Danaco', 'SaaS competitors'],
        'rows' => [
            ['Data hosting', 'Your server', 'Their cloud'],
            ['Cost', 'One-time $20, or build from source', 'Per user, per month'],
            ['Updates', 'Free, installed manually from Setup', 'Tied to the subscription'],
            ['License', 'MIT (open source)', 'Closed'],
            ['Privacy', 'Local by default', 'Shared, and often trained on'],
            ['EU AI Act posture', 'Built-in role split and optional tooling', 'Vendor-hosted, their paperwork'],
            ['Customization', 'Unlimited on your hardware', 'Restricted by plan'],
        ],
    ],
    'footer' => [
        'note' => 'Agent Danaco is an MIT-licensed self-hosted framework. Help is in the docs. Packaged-installer purchases are handled by Lemon Squeezy. You operate the deployment and remain responsible for lawful use.',
    ],
];
