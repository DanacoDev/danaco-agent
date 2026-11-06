---
slug: index
nav: What Agent Danaco is
title: What Agent Danaco is
---

Agent Danaco is a local **agent framework**. It runs on your Linux machine, keeps a Web UI in the browser, and puts specialized subagents under one orchestrator. You configure models and tools; the runtime can act with a high degree of autonomy inside what you enable.

## A team, not a single chat

The core is PHP. There is no Docker stack and no Python environment to keep alive. You talk to one project chat. Behind that chat, an orchestrator hands work to subagents and stops them from talking to each other in a loop.

The interface is a password-protected Web UI: the conversation, a Kanban board, a file explorer, an agent feed, and the model settings live in one place. Each project keeps a single continuous chat, so you are not switching sessions to find the thread.

![Agent overview](/assets/screen-agents.jpg)

## Who it is for

Agent Danaco is for people who want project help on hardware they control, without a monthly fee for the runtime itself. Project managers and business leads can describe a procedure in plain language. The agent writes and reuses skills when a task needs a script.

:::cards
#### Your files stay put

Notes, the wiki, and project files live on the machine running Agent Danaco. A cloud model is something you turn on, not the default.

#### One install

MIT-licensed on GitHub. Clone and run the installer yourself, or buy a packaged installer if you want the guided path.
:::

## Intended purpose

The framework is for language-model-assisted project work on hardware you control. It is not intended for prohibited AI practices or for high-risk AI system use cases under Annex III of the EU AI Act. The organisation that installs and configures it determines the purpose of each deployment and is responsible for lawful use under the laws that apply to them.

## What it is built to avoid

Most local agent frameworks fail in the same few ways. Agent Danaco is arranged so those failures are harder to hit.

- Hours of fragile setup: missing Python packages, vector-database drivers, and an install that breaks on the next update.
- Agents stuck in a loop, burning GPU memory until the machine stalls.
- A context window packed with every skill and the whole history, until the model drifts or runs out of memory.
- A file edit or delete with no backup and no trash.
- Work that only exists as a hidden scheduled job, with nothing on screen to pause or reassign.

## How it stays usable

A few mechanisms keep a long project from collapsing into a demo that only worked once.

:::cards
#### Three model tiers

Use a local model through Ollama, an optional cloud API, or a small CPU failsafe when the GPU is out of memory.

#### Wiki memory

A markdown wiki with semantic search. Project memory stays in that project, separate from the general wiki.

#### Trashcan and backups

Edits are backed up. Deletes land in a trashcan the model cannot empty. The agent core stays closed unless you grant system access.

#### Kanban and skills

Tasks sit on a board you can reorder. Skills stay as one-line summaries until they are called. MCP can add outside tools.
:::

![Kanban task board](/assets/screen-kanban.jpg)

You also get a live view of what each subagent is doing, a file explorer for the wiki and work files, and guardrails that cut off looped or stalled runs.

## Install it

The next page walks through the real installer: PHP, the model choice, the Web UI password, Ollama, and the local certificate.

[Continue to the installation page.](installation.php)
