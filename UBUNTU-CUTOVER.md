# Ubuntu cutover: Dagento → Danaco Agent 1.0.0

Use this on the machine that still runs the old Dagento install. Keep a copy of `install.sh` outside GitHub (this file is local-only / sold separately).

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

## 3. Fresh install from the sold/local installer

Put `install.sh` alone in a new folder (or use the copy from your Mac `danaco-agent` tree). It will clone `DanacoDev/agent` and continue setup:

```bash
mkdir -p ~/danaco-agent && cd ~/danaco-agent
# copy install.sh here, then:
bash install.sh
```

You need GitHub access to the private `DanacoDev/agent` repo (`gh auth login` or a clone URL that works for you).

## 4. Restore projects

```bash
danaco restore ~/danaco-projects-backup.zip
```

## 5. Smoke check

- Open the UI (`danaco`) and sign in
- Confirm footer/settings show version **1.0.0**
- Confirm `danaco update` talks to `DanacoDev/agent`
- Confirm old `dagento` command is gone
