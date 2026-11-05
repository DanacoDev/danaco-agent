# Danaco Agent

Self-hosted **agent framework** for your own hardware. Orchestrator, subagents, projects, skills, and a Web UI — without a subscription. You configure models and tools; the runtime can work with a high degree of autonomy inside what you enable.

**License:** [MIT](LICENSE)

## Install

macOS and Linux. From a terminal:

```bash
git clone https://github.com/DanacoDev/agent.git danaco-agent
cd danaco-agent
bash install.sh
```

After install:

```bash
danaco
danaco update
danaco backup
danaco restore
danaco uninstall
```

A packaged installer for less technical users and businesses is sold separately on the Danaco website. The same open-source project is what that installer sets up.

## Intended purpose

Danaco Agent is a self-hosted software framework for language-model-assisted project work (chat, files, tasks, optional tools) on hardware the operator controls. It is not intended for prohibited AI practices or for high-risk AI system use cases under Annex III of the EU AI Act. The organisation that installs and configures it determines the purpose of each deployment and is responsible for lawful use under the laws that apply to them.

See [Terms of Use](public_html/www/legal/terms.php) and [Acceptable Use](public_html/www/legal/aup.php).

## Version

See [`VERSION`](VERSION). Current release: **1.0.0**.
