# Ubuntu cutover: Dagento → Agent Danaco 1.0.0

Use this on the machine that still runs the old Dagento install. `install.sh` ships in the MIT-licensed GitHub repo; a packaged installer remains optional for less technical setups.

## 1. Backup projects

```bash
dagento backup ~/danaco-projects-backup.zip
```

Confirm the zip exists and is readable.

## 2. Uninstall the old app

```bash
dagento uninstall
```

Choose deletion of the old project files only after the backup is verified. This should remove the old `dagento` symlink, `dagento.service`, and related OS bits.

## 3. Fresh install from GitHub

```bash
git clone https://github.com/DanacoDev/danaco-agent.git ~/danaco-agent
cd ~/danaco-agent
bash install.sh
```

While the repo is still private, use `gh auth login` (or a working clone URL) before `git clone`.

## 4. Restore projects

```bash
danaco restore ~/danaco-projects-backup.zip
```

## 5. Smoke check

- Open the UI (`danaco`) and sign in
- Confirm footer/settings show version **1.0.7**
- Confirm `danaco update` talks to `DanacoDev/danaco-agent`
- Confirm old `dagento` command is gone
