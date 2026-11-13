#!/usr/bin/env python3
"""Setup for Danaco Agent. A window when this computer has a graphical session, otherwise install.sh."""

import codecs
import grp
import os
import pwd
import queue
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

REPO_URL = "https://github.com/DanacoDev/danaco-agent.git"
UNSLOTH_FILES = {
    "hf.co/unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q3_K_S": "Qwen3.6-35B-A3B-UD-Q3_K_S.gguf",
    "hf.co/unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q4_K_M": "Qwen3.6-35B-A3B-UD-Q4_K_M.gguf",
}
URL_RE = re.compile(r"Agent Danaco is running at (https://\S+)")
NEW_COPY_RE = re.compile(r"Installing a new copy at (.+)$")
REPLACED_RE = re.compile(r"Replacing the old files in (.+)$")


def next_install_dir(root):
    """Match the numbered folder install.sh picks beside an existing install."""
    root = os.path.abspath(root)
    parent = os.path.dirname(root)
    base = os.path.basename(root)
    match = re.fullmatch(r"(.+[^0-9])[0-9]+", base)
    stem = match.group(1) if match else base
    number = 1
    while os.path.exists(os.path.join(parent, f"{stem}{number}")):
        number += 1
    return os.path.join(parent, f"{stem}{number}")


def graphical_session():
    return bool(os.environ.get("DISPLAY") or os.environ.get("WAYLAND_DISPLAY"))


def remove_install_tree(path):
    """Delete a Danaco folder left by a failed setup. Leave home and system directories alone."""
    if not path:
        return False
    try:
        resolved = os.path.realpath(path)
    except OSError:
        return False
    home = os.path.realpath(os.path.expanduser("~"))
    if resolved in {"/", home, "/usr", "/bin", "/sbin", "/etc", "/var", "/opt", "/tmp", "/home", "/root"}:
        return False
    if not os.path.isdir(resolved):
        return False
    if not (
        os.path.isfile(os.path.join(resolved, "install.sh"))
        or os.path.isfile(os.path.join(resolved, "bin", "danaco"))
        or os.path.isdir(os.path.join(resolved, ".git"))
    ):
        return False
    shutil.rmtree(resolved)
    return True


def clone_command(dest):
    if shutil.which("gh"):
        probe = subprocess.run(
            ["gh", "auth", "status"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        if probe.returncode == 0:
            return ["gh", "repo", "clone", "DanacoDev/danaco-agent", dest, "--", "--branch", "main", "--single-branch"]
    return ["git", "clone", "--branch", "main", "--single-branch", REPO_URL, dest]


def run_terminal_install():
    """Clone into ~/danaco-agent, if needed, and run install.sh on this terminal."""
    if not sys.stdin.isatty() or not sys.stdout.isatty():
        print("This computer has no graphical session.", file=sys.stderr)
        print("Run danaco-agent-setup from a terminal to continue.", file=sys.stderr)
        return 1
    dest = os.path.abspath(os.path.expanduser("~/danaco-agent"))
    script = os.path.join(dest, "install.sh")
    if not os.path.isfile(script):
        parent = os.path.dirname(dest)
        if not os.path.isdir(parent):
            print(f"The parent folder does not exist: {parent}", file=sys.stderr)
            return 1
        if os.path.isfile(dest):
            print(f"{dest} is a file.", file=sys.stderr)
            return 1
        if os.path.isdir(dest) and os.listdir(dest):
            print(f"{dest} is not empty and does not contain install.sh.", file=sys.stderr)
            return 1
        print("Fetching Danaco Agent from GitHub...", flush=True)
        if subprocess.run(clone_command(dest)).returncode != 0 or not os.path.isfile(script):
            print("Could not download Danaco Agent.", file=sys.stderr)
            return 1
    for name in (
        "DANACO_NONINTERACTIVE",
        "DANACO_REUSE_CHOICE",
        "DANACO_MODEL_MODE",
        "DANACO_LOCAL_MODEL",
        "DANACO_BIND_HOST",
        "DANACO_AI_ACT",
        "DANACO_PASSWORD",
    ):
        os.environ.pop(name, None)
    print(f"No graphical session. Starting the terminal installer in {dest}.", flush=True)
    try:
        os.execvpe("bash", ["bash", script], os.environ)
    except OSError as exc:
        print(f"Could not run install.sh: {exc}", file=sys.stderr)
        return 1


def linux_boot_enabled():
    if shutil.which("systemctl") is None:
        return False
    try:
        result = subprocess.run(
            ["systemctl", "is-enabled", "danaco.service"],
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    return (result.stdout or "").strip() == "enabled"


def register_linux_autostart(dest, env, log):
    """Register the boot service. A graphical install has no terminal for sudo."""
    if sys.platform != "linux" or linux_boot_enabled():
        return
    root = os.path.abspath(dest)
    if not os.path.isfile(os.path.join(root, "bin", "run-agent.sh")):
        return
    user = pwd.getpwuid(os.getuid())
    group = grp.getgrgid(os.getgid())
    system_unit = (
        "[Unit]\n"
        "Description=Danaco Agent web UI\n"
        "After=network.target\n"
        "\n"
        "[Service]\n"
        "Type=simple\n"
        f"User={user.pw_name}\n"
        f"Group={group.gr_name}\n"
        f"WorkingDirectory={root}\n"
        f"ExecStart=/bin/bash {root}/bin/run-agent.sh\n"
        "Restart=always\n"
        "RestartSec=5\n"
        "\n"
        "[Install]\n"
        "WantedBy=multi-user.target\n"
    )
    staging = os.path.join(root, "data", ".danaco.service")
    try:
        os.makedirs(os.path.dirname(staging), exist_ok=True)
        with open(staging, "w", encoding="utf-8") as handle:
            handle.write(system_unit)
    except OSError as exc:
        log(f"Could not prepare the boot service: {exc}")
        return
    config_home = env.get("XDG_CONFIG_HOME") or os.path.expanduser("~/.config")
    user_unit_path = os.path.join(config_home, "systemd", "user", "danaco.service")
    if os.path.isfile(user_unit_path) and shutil.which("systemctl"):
        subprocess.run(
            ["systemctl", "--user", "disable", "--now", "danaco.service"],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    script = 'cp "$1" "/etc/systemd/system/$2" && systemctl daemon-reload && systemctl enable --now "$2"'
    command = ["bash", "-c", script, "bash", staging, "danaco.service"]
    if os.geteuid() != 0:
        if shutil.which("pkexec") is None:
            command = None
        else:
            log("Administrator permission is required so the agent starts at boot.")
            command = ["pkexec", *command]
    registered = False
    if command is not None:
        try:
            registered = subprocess.run(command, env=env).returncode == 0
        except OSError:
            registered = False
    try:
        os.remove(staging)
    except OSError:
        pass
    if registered:
        try:
            with open(os.path.join(root, "data", "service.mode"), "w", encoding="utf-8") as handle:
                handle.write("systemd\n")
        except OSError:
            pass
        log("The agent starts automatically on boot.")
        return
    user_unit = (
        "[Unit]\n"
        "Description=Danaco Agent web UI\n"
        "After=network.target\n"
        "\n"
        "[Service]\n"
        "Type=simple\n"
        f"WorkingDirectory={root}\n"
        f"ExecStart=/bin/bash {root}/bin/run-agent.sh\n"
        "Restart=always\n"
        "RestartSec=5\n"
        "\n"
        "[Install]\n"
        "WantedBy=default.target\n"
    )
    try:
        os.makedirs(os.path.dirname(user_unit_path), exist_ok=True)
        with open(user_unit_path, "w", encoding="utf-8") as handle:
            handle.write(user_unit)
        if shutil.which("systemctl") is None:
            return
        reloaded = subprocess.run(["systemctl", "--user", "daemon-reload"], env=env).returncode == 0
        enabled = subprocess.run(
            ["systemctl", "--user", "enable", "--now", "danaco.service"],
            env=env,
        ).returncode == 0
    except OSError as exc:
        log(f"Could not register automatic startup: {exc}")
        return
    if reloaded and enabled:
        try:
            with open(os.path.join(root, "data", "service.mode"), "w", encoding="utf-8") as handle:
                handle.write("systemd-user\n")
        except OSError:
            pass
        log("The agent starts automatically when you log in.")
    else:
        log("Automatic startup was not registered. Start it later with: danaco")


def format_bytes(count):
    number = float(max(0, count))
    for unit in ("B", "KB", "MB", "GB"):
        if number < 1024 or unit == "GB":
            if unit == "B":
                return f"{int(number)} B"
            text = f"{number:.1f}".rstrip("0").rstrip(".")
            return f"{text} {unit}"
        number /= 1024.0
    return f"{number:.1f} GB"


def remote_size(url):
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "danaco-setup"})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            length = response.headers.get("Content-Length")
            return int(length) if length else 0
    except (OSError, urllib.error.URLError, ValueError):
        return 0


def ollama_listed(name, env):
    if not name or shutil.which("ollama", path=env.get("PATH")) is None:
        return False
    try:
        result = subprocess.run(
            ["ollama", "list"],
            capture_output=True,
            text=True,
            env=env,
            timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    wanted = {name.lower(), f"{name}:latest".lower()}
    for line in (result.stdout or "").splitlines():
        part = line.split()
        if part and part[0].lower() in wanted:
            return True
    return False


def ollama_models():
    """Return (installed, model names). Names are empty when Ollama has none."""
    if shutil.which("ollama") is None:
        return False, []
    try:
        result = subprocess.run(
            ["ollama", "list"],
            capture_output=True,
            text=True,
            timeout=5,
        )
    except (OSError, subprocess.TimeoutExpired):
        return True, []
    names = []
    for line in (result.stdout or "").splitlines():
        part = line.split()
        if not part or part[0] == "NAME":
            continue
        names.append(part[0])
    return True, names


def model_matches(installed_name, recommended):
    if not installed_name or not recommended:
        return False
    return installed_name in (recommended, f"{recommended}:latest") or recommended == f"{installed_name}:latest"


def recommended_model():
    """Match the hardware choice in install.sh."""
    vram_mb = 0
    if shutil.which("nvidia-smi"):
        try:
            result = subprocess.run(
                ["nvidia-smi", "--query-gpu=memory.total", "--format=csv,noheader,nounits"],
                capture_output=True,
                text=True,
                timeout=8,
            )
        except (OSError, subprocess.TimeoutExpired):
            result = None
        for line in ((result.stdout if result is not None else "") or "").splitlines():
            memory = line.strip()
            if not memory.isdigit():
                continue
            memory = int(memory)
            if memory > vram_mb:
                vram_mb = memory
    ram_mb = 0
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("MemTotal:"):
                    ram_mb = int(line.split()[1]) // 1024
                    break
    except (OSError, IndexError, ValueError):
        ram_mb = 0
    if vram_mb >= 22528:
        return "local", "hf.co/unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q4_K_M"
    if vram_mb >= 15360:
        return "local", "hf.co/unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q3_K_S"
    if ram_mb >= 7168:
        return "local", "qwen3.5:9b-q4_K_M"
    return "cpu_failsafe", "qwen2.5:0.5b"


class LogFeed:
    """Turn installer output into plain lines and one short progress line.

    Ollama redraws a download with cursor and color codes. Those codes are
    applied here, then the visible row is reduced to one line that fits.
    """

    def __init__(self):
        self.rows = [""]
        self.row = 0
        self.col = 0
        self.esc = None

    def feed(self, text):
        scrolled = []
        changed = False
        for char in text:
            if self.esc is not None:
                self.esc += char
                if len(self.esc) > 64:
                    self.esc = None
                    continue
                if self._esc_done(char):
                    self._apply_esc(self.esc)
                    self.esc = None
                    changed = True
                continue
            if char == "\x1b":
                self.esc = ""
                continue
            if char == "\r":
                self.col = 0
                changed = True
                continue
            if char == "\n":
                self._newline(scrolled)
                changed = True
                continue
            if char == "\b":
                self.col = max(0, self.col - 1)
                changed = True
                continue
            if ord(char) < 32:
                continue
            self._put(char)
            changed = True
        events = [("line", line) for line in scrolled]
        if changed:
            events.append(("progress", self._status()))
        return events

    def finish(self):
        events = []
        for row in self.rows:
            text = self._plain(row)
            if text and not self._is_live(text):
                events.append(("line", text))
        self.rows = [""]
        self.row = 0
        self.col = 0
        events.append(("progress", ""))
        return events

    def _esc_done(self, char):
        if not self.esc:
            return False
        kind = self.esc[0]
        if kind == "[":
            return char.isalpha() or char in "@`{|}~"
        if kind == "]":
            return char == "\x07" or self.esc.endswith("\x1b\\")
        return True

    def _apply_esc(self, seq):
        if not seq.startswith("["):
            return
        body = seq[1:]
        if not body or body[0] == "?":
            return
        final = body[-1]
        nums = []
        for part in body[:-1].split(";"):
            if part.isdigit():
                nums.append(int(part))

        def count(index, default):
            if index < len(nums) and nums[index] > 0:
                return nums[index]
            return default

        if final == "A":
            self.row = max(0, self.row - count(0, 1))
        elif final == "B":
            self.row += count(0, 1)
        elif final == "C":
            self.col += count(0, 1)
        elif final == "D":
            self.col = max(0, self.col - count(0, 1))
        elif final == "G":
            self.col = max(0, count(0, 1) - 1)
        elif final in ("H", "f"):
            self.row = max(0, count(0, 1) - 1)
            self.col = max(0, count(1, 1) - 1)
        elif final == "K":
            self._erase_line(nums[0] if nums else 0)
        elif final == "J" and (nums[0] if nums else 0) == 2:
            self.rows = [""]
            self.row = 0
            self.col = 0
        while self.row >= len(self.rows):
            self.rows.append("")
        if len(self.rows) > 12:
            self.rows = self.rows[-12:]
            self.row = min(self.row, len(self.rows) - 1)

    def _erase_line(self, mode):
        while self.row >= len(self.rows):
            self.rows.append("")
        row = self.rows[self.row]
        if mode == 2:
            self.rows[self.row] = ""
            self.col = 0
            return
        if mode == 1:
            self.rows[self.row] = row[self.col:]
            self.col = 0
            return
        self.rows[self.row] = row[:self.col]

    def _put(self, char):
        while self.row >= len(self.rows):
            self.rows.append("")
        row = self.rows[self.row]
        if self.col > len(row):
            row += " " * (self.col - len(row))
        if self.col == len(row):
            row += char
        else:
            row = row[:self.col] + char + row[self.col + 1:]
        self.rows[self.row] = row
        self.col += 1

    def _newline(self, scrolled):
        text = self._plain(self.rows[self.row]) if self.row < len(self.rows) else ""
        if text and self._is_live(text):
            extra = re.search(r"([A-Z][A-Za-z][A-Za-z ].*)$", text)
            if extra and not self._is_live(extra.group(1)) and extra.start() > 0:
                scrolled.append(extra.group(1).strip())
                self.rows = [""]
                self.row = 0
                self.col = 0
                return
            self.row += 1
            self.col = 0
            while self.row >= len(self.rows):
                self.rows.append("")
            return
        if text:
            scrolled.append(text)
            self.rows = [""]
            self.row = 0
            self.col = 0
            return
        self.row += 1
        self.col = 0
        while self.row >= len(self.rows):
            self.rows.append("")

    def _plain(self, text):
        text = re.sub(r"\x1b\[[0-9;?]*[ -/]*[@-~]", "", text)
        text = re.sub(r"[\u2500-\u259f\u2800-\u28ff]", "", text)
        return re.sub(r"\s+", " ", text).strip()

    def _is_live(self, text):
        low = text.lower()
        if re.fullmatch(r"\d+[hms](?:\d+[hms])*", low):
            return True
        return (
            low.startswith("pulling ")
            or "mb/s" in low
            or "kb/s" in low
            or "gb/s" in low
            or low.startswith("verifying")
            or low.startswith("writing manifest")
            or "gathering model" in low
            or low.startswith("using existing layer")
            or low.startswith("creating new layer")
        )

    def _status(self):
        chosen = ""
        fallback = ""
        for row in self.rows:
            text = self._plain(row)
            if not text:
                continue
            fallback = text
            if not self._is_live(text):
                continue
            if not chosen or "%" in text or "/" in text:
                chosen = text
        if chosen:
            return self._short(chosen)
        if fallback:
            return self._short(fallback)
        return ""

    def _short(self, text):
        low = text.lower()
        if low.startswith("verifying"):
            return "Verifying the download"
        if low.startswith("writing manifest"):
            return "Writing the model manifest"
        if "gathering model" in low or low.startswith("using existing layer") or low.startswith("creating new layer"):
            return "Preparing the model"
        if "pulling manifest" in low and "%" not in text and "/" not in text:
            return "Downloading the model manifest"
        size = re.search(r"(\d+(?:\.\d+)?\s*[KMG]B)\s*/\s*(\d+(?:\.\d+)?\s*[KMG]B)", text, re.I)
        speed = re.search(r"(\d+(?:\.\d+)?\s*[KMG]B/s)", text, re.I)
        percent = re.search(r"(\d+)\s*%", text)
        if size:
            message = "Downloading "
            if percent:
                message += percent.group(1) + "%, "
            message += size.group(1) + " / " + size.group(2)
            if speed:
                message += ", " + speed.group(1)
            return message
        if percent and low.startswith("pulling "):
            return "Downloading " + percent.group(1) + "%"
        if low.startswith("pulling "):
            return "Downloading the model"
        if len(text) <= 72:
            return text
        return text[:69] + "..."


def main():
    if not graphical_session():
        raise SystemExit(run_terminal_install())
    try:
        import gi
        gi.require_version("Gtk", "4.0")
        from gi.repository import Gio, GLib, Gtk
    except Exception:
        raise SystemExit(run_terminal_install())

    class SetupWindow:
        def __init__(self, app):
            self.app = app
            self.route = ["folder"]
            self.index = 0
            self.running = False
            self.timer = 0
            self.proc = None
            self.stop = False
            self.queue = queue.Queue()
            self.lines = []
            self.progress = None
            self.url = ""
            self.model_buttons = []
            self.job = None
            self.succeeded = False
            self._cleaned = False
            self._new_copy = ""
            self._replaced = False
            self._clean_lock = threading.Lock()
            self._cancelling = False
            self._download_dir = ""
            self._ready = False
            self._finish_ui = False

            self.win = Gtk.ApplicationWindow(application=app, title="Danaco Agent Setup")
            self.win.set_default_size(720, 560)
            self.win.connect("close-request", self.on_close_request)

            root = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            root.set_margin_top(16)
            root.set_margin_bottom(16)
            root.set_margin_start(16)
            root.set_margin_end(16)
            self.win.set_child(root)

            self.stack = Gtk.Stack()
            self.stack.set_vexpand(True)
            root.append(self.stack)
            self.stack.add_named(self._folder_page(), "folder")
            self.stack.add_named(self._reuse_page(), "reuse")
            self.stack.add_named(self._model_page(), "model")
            self.stack.add_named(self._lan_page(), "lan")
            self.stack.add_named(self._password_page(), "password")
            self.stack.add_named(self._aiact_page(), "aiact")
            self.stack.add_named(self._run_page(), "run")

            self.error = Gtk.Label(xalign=0, wrap=True)
            self.error.set_max_width_chars(60)
            root.append(self.error)

            buttons = Gtk.Box(orientation=Gtk.Orientation.HORIZONTAL, spacing=8)
            buttons.set_halign(Gtk.Align.END)
            root.append(buttons)
            self.back_btn = Gtk.Button(label="Back")
            self.back_btn.connect("clicked", lambda *_a: self.on_back())
            self.cancel_btn = Gtk.Button(label="Cancel")
            self.cancel_btn.add_css_class("destructive-action")
            self.cancel_btn.set_visible(False)
            self.cancel_btn.connect("clicked", lambda *_a: self.on_cancel())
            self.open_btn = Gtk.Button(label="Open")
            self.open_btn.set_visible(False)
            self.open_btn.connect("clicked", lambda *_a: self.on_open())
            self.next_btn = Gtk.Button(label="Next")
            self.next_btn.add_css_class("suggested-action")
            self.next_btn.connect("clicked", lambda *_a: self.on_next())
            buttons.append(self.back_btn)
            buttons.append(self.cancel_btn)
            buttons.append(self.open_btn)
            buttons.append(self.next_btn)
            self.show_current()
            self.win.present()

        def heading(self, text):
            label = Gtk.Label(xalign=0, wrap=True)
            label.set_max_width_chars(52)
            label.set_markup(f"<b>{GLib.markup_escape_text(text)}</b>")
            return label

        def body(self, text):
            label = Gtk.Label(label=text, xalign=0, wrap=True)
            label.set_max_width_chars(60)
            return label

        def _folder_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Install folder"))
            page.append(self.body(
                "This downloads Danaco Agent from GitHub and sets it up on this computer. "
                "You will choose a model, who can open it, a password, and whether the EU AI Act applies."
            ))
            row = Gtk.Box(orientation=Gtk.Orientation.HORIZONTAL, spacing=8)
            self.folder_entry = Gtk.Entry()
            self.folder_entry.set_text(os.path.expanduser("~/danaco-agent"))
            self.folder_entry.set_hexpand(True)
            choose = Gtk.Button(label="Choose…")
            choose.connect("clicked", self.on_choose_folder)
            row.append(self.folder_entry)
            row.append(choose)
            page.append(row)
            return page

        def _reuse_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Existing install"))
            self.reuse_found = self.body("")
            page.append(self.reuse_found)
            self.reuse_replace = Gtk.CheckButton(label="Replace the old files")
            self.reuse_keep = Gtk.CheckButton(label="Use the existing files")
            self.reuse_keep.set_group(self.reuse_replace)
            self.reuse_new = Gtk.CheckButton(label="Create a new folder")
            self.reuse_new.set_group(self.reuse_replace)
            self.reuse_keep.set_active(True)
            page.append(self.reuse_replace)
            page.append(self.reuse_keep)
            page.append(self.reuse_new)
            return page

        def _model_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Model"))
            self.model_intro = self.body("")
            page.append(self.model_intro)
            self.model_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6)
            scroll = Gtk.ScrolledWindow()
            scroll.set_policy(Gtk.PolicyType.NEVER, Gtk.PolicyType.AUTOMATIC)
            scroll.set_vexpand(True)
            scroll.set_child(self.model_box)
            page.append(scroll)
            return page

        def _lan_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Who can open it"))
            page.append(self.body("Should the agent be accessible from other devices on the same network?"))
            self.lan_no = Gtk.CheckButton(label="Only this computer")
            self.lan_yes = Gtk.CheckButton(label="Other devices on this network")
            self.lan_yes.set_group(self.lan_no)
            self.lan_no.set_active(True)
            page.append(self.lan_no)
            page.append(self.lan_yes)
            return page

        def _password_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Web UI password"))
            page.append(self.body("At least 8 characters. It is not written to the log."))
            self.pass1 = Gtk.PasswordEntry()
            self.pass1.set_show_peek_icon(True)
            self.pass2 = Gtk.PasswordEntry()
            self.pass2.set_show_peek_icon(True)
            page.append(self.body("Password"))
            page.append(self.pass1)
            page.append(self.body("Confirm password"))
            page.append(self.pass2)
            return page

        def _aiact_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("EU AI Act"))
            page.append(self.body("Does the EU AI Act apply to this deployment?"))
            self.ai_yes = Gtk.CheckButton(label="Yes, activate compliance tools")
            self.ai_no = Gtk.CheckButton(label="No")
            self.ai_no.set_group(self.ai_yes)
            self.ai_no.set_active(True)
            page.append(self.ai_yes)
            page.append(self.ai_no)
            return page

        def _run_page(self):
            page = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
            page.append(self.heading("Setup"))
            self.run_status = self.body("Installing…")
            page.append(self.run_status)
            self.logview = Gtk.TextView()
            self.logview.set_editable(False)
            self.logview.set_monospace(True)
            self.logview.set_wrap_mode(Gtk.WrapMode.WORD_CHAR)
            self.logbuf = self.logview.get_buffer()
            scroll = Gtk.ScrolledWindow()
            scroll.set_policy(Gtk.PolicyType.AUTOMATIC, Gtk.PolicyType.AUTOMATIC)
            scroll.set_vexpand(True)
            scroll.set_child(self.logview)
            self.log_scroll = scroll
            page.append(scroll)
            return page

        def folder_path(self):
            text = self.folder_entry.get_text().strip()
            if not text:
                return ""
            return os.path.abspath(os.path.expanduser(text))

        def set_error(self, text):
            if not text:
                self.error.set_text("")
                return
            self.error.set_markup(
                f'<span color="#b00020">{GLib.markup_escape_text(text)}</span>'
            )

        def show_current(self):
            name = self.route[self.index]
            self.stack.set_visible_child_name(name)
            self.back_btn.set_visible(self.index > 0 and name != "run" and not self.running)
            self.cancel_btn.set_visible(name == "run" and self.running)
            if name == "run":
                if not self.running:
                    self.next_btn.set_label("Close")
                    self.next_btn.set_visible(True)
                    self.next_btn.set_sensitive(True)
            elif name == "aiact":
                self.next_btn.set_label("Install")
                self.next_btn.set_visible(True)
                self.next_btn.set_sensitive(True)
            else:
                self.next_btn.set_label("Next")
                self.next_btn.set_visible(True)
                self.next_btn.set_sensitive(True)
            if name == "reuse":
                self.refresh_reuse()
            if name == "model":
                self.refresh_models()

        def on_choose_folder(self, _button):
            dialog = Gtk.FileChooserNative.new(
                "Install folder",
                self.win,
                Gtk.FileChooserAction.SELECT_FOLDER,
                "Choose",
                "Cancel",
            )
            current = self.folder_path()
            start = current if os.path.isdir(current) else os.path.dirname(current)
            if start and os.path.isdir(start):
                try:
                    dialog.set_current_folder(Gio.File.new_for_path(start))
                except (AttributeError, TypeError):
                    pass
            dialog.set_modal(True)
            dialog.connect("response", self.on_folder_chosen)
            self._chooser = dialog
            dialog.show()

        def on_folder_chosen(self, dialog, response):
            if response == Gtk.ResponseType.ACCEPT:
                chosen = dialog.get_file()
                if chosen is not None and chosen.get_path():
                    self.folder_entry.set_text(chosen.get_path())
            dialog.destroy()
            self._chooser = None

        def refresh_reuse(self):
            path = self.folder_path()
            self.reuse_found.set_text(f"An existing Danaco Agent install was found at {path}")
            self.reuse_new.set_label(
                f"Create a new folder ({os.path.basename(next_install_dir(path))})"
            )

        def refresh_models(self):
            previous_mode, previous_name = self.selected_model()
            installed, names = ollama_models()
            mode, recommended = recommended_model()
            if installed and names:
                self.model_intro.set_text("Ollama is installed. Choose a model:")
            elif installed:
                self.model_intro.set_text("Ollama is installed, but it has no models yet.")
            else:
                self.model_intro.set_text("Ollama is not installed.")
            child = self.model_box.get_first_child()
            while child is not None:
                nxt = child.get_next_sibling()
                self.model_box.remove(child)
                child = nxt
            recommended_choice = None
            choices = []
            for name in names:
                if model_matches(name, recommended):
                    if recommended_choice is None:
                        local_name = name if mode == "local" else ""
                        recommended_choice = (
                            f"{name} (recommended for your hardware)",
                            mode,
                            local_name,
                        )
                    continue
                choices.append((name, "local", name))
            if recommended_choice is None:
                local_name = recommended if mode == "local" else ""
                recommended_choice = (
                    f"Download the recommended model ({recommended})",
                    mode,
                    local_name,
                )
            choices.insert(0, recommended_choice)
            choices.append(("Cloud API", "cloud", ""))
            if mode != "cpu_failsafe":
                choices.append(("Ignore this, continue with only the CPU failsafe", "cpu_failsafe", ""))
            self.model_buttons = []
            leader = None
            for label, choice_mode, local_name in choices:
                button = Gtk.CheckButton(label=label)
                if leader is None:
                    leader = button
                else:
                    button.set_group(leader)
                self.model_box.append(button)
                self.model_buttons.append((button, choice_mode, local_name))
            restored = False
            if previous_mode:
                for button, choice_mode, local_name in self.model_buttons:
                    if choice_mode == previous_mode and local_name == previous_name:
                        button.set_active(True)
                        restored = True
                        break
            if not restored:
                for button, choice_mode, local_name in self.model_buttons:
                    if (choice_mode, local_name) == (recommended_choice[1], recommended_choice[2]):
                        button.set_active(True)
                        restored = True
                        break
            if not restored and self.model_buttons:
                self.model_buttons[0][0].set_active(True)

        def selected_model(self):
            for button, mode, local_name in self.model_buttons:
                if button.get_active():
                    return mode, local_name
            return "", ""

        def reuse_choice(self):
            if self.reuse_replace.get_active():
                return "1"
            if self.reuse_new.get_active():
                return "3"
            return "2"

        def ai_act(self):
            if self.ai_yes.get_active():
                return "yes"
            return "no"

        def validate(self, name):
            self.set_error("")
            if name == "folder":
                path = self.folder_path()
                if not path or path in ("/", os.path.expanduser("~")):
                    self.set_error("Choose a folder for this install, not your home folder or the top of the disk.")
                    return False
                parent = os.path.dirname(os.path.abspath(path))
                if not os.path.isdir(parent):
                    self.set_error("The parent folder does not exist.")
                    return False
                if os.path.isfile(path):
                    self.set_error("That path is a file. Choose a folder.")
                    return False
                if os.path.isdir(path):
                    names = os.listdir(path)
                    danaco = (
                        os.path.isfile(os.path.join(path, "bin", "danaco"))
                        or os.path.isfile(os.path.join(path, "data", "app.sqlite"))
                    )
                    if names and not danaco:
                        self.set_error(
                            "That folder is not empty. Choose an empty folder, a new folder, "
                            "or an existing Danaco Agent install."
                        )
                        return False
                return True
            if name == "password":
                first = self.pass1.get_text()
                second = self.pass2.get_text()
                if first != second or len(first) < 8:
                    self.set_error("Passwords did not match or were shorter than 8 characters.")
                    return False
                if len(first) > 200:
                    self.set_error("Password must be at most 200 characters.")
                    return False
                if "\n" in first or "\r" in first or "\x00" in first:
                    self.set_error("Password cannot contain a line break.")
                    return False
                return True
            if name == "model" and not self.selected_model()[0]:
                self.set_error("Choose a model.")
                return False
            return True

        def on_back(self):
            if self.running or self.index == 0:
                return
            self.set_error("")
            self.index -= 1
            self.show_current()

        def on_next(self):
            name = self.route[self.index]
            if name == "run":
                if not self.running:
                    self.win.close()
                return
            if not self.validate(name):
                return
            if name == "folder":
                self.route = ["folder"]
                if os.path.isfile(os.path.join(self.folder_path(), "data", "app.sqlite")):
                    self.route.append("reuse")
                self.route.extend(["model", "lan", "password", "aiact", "run"])
                self.index = 0
            if name == "aiact":
                self.index = self.route.index("run")
                self.show_current()
                self.start_install()
                return
            self.index += 1
            self.show_current()

        def start_install(self):
            mode, local_name = self.selected_model()
            job = {
                "folder": self.folder_path(),
                "reuse": self.reuse_choice() if "reuse" in self.route else "",
                "mode": mode,
                "local": local_name,
                "bind": "0.0.0.0" if self.lan_yes.get_active() else "127.0.0.1",
                "password": self.pass1.get_text(),
                "ai_act": self.ai_act(),
            }
            self.job = job
            self.succeeded = False
            self._cleaned = False
            self._new_copy = ""
            self._replaced = False
            self.running = True
            self.stop = False
            self.url = ""
            self.lines = []
            self.progress = None
            self.open_btn.set_visible(False)
            self.back_btn.set_visible(False)
            self.next_btn.set_visible(False)
            self._cancelling = False
            self._download_dir = ""
            self._ready = False
            self._finish_ui = False
            self.cancel_btn.set_visible(True)
            self.cancel_btn.set_sensitive(True)
            self.run_status.set_text("Installing…")
            self.render_log()
            self.timer = GLib.timeout_add(100, self.pump)
            threading.Thread(target=self.worker, args=(job,), daemon=True).start()

        def render_log(self):
            body = "\n".join(self.lines)
            if self.progress:
                body = f"{body}\n{self.progress}" if body else self.progress
            self.logbuf.set_text(body)
            adjustment = self.log_scroll.get_vadjustment()

            def scroll_down():
                try:
                    adjustment.set_value(adjustment.get_upper())
                except Exception:
                    pass
                return False

            GLib.idle_add(scroll_down)

        def pump(self):
            finished = None
            changed = False
            try:
                while True:
                    item = self.queue.get_nowait()
                    changed = True
                    kind = item[0]
                    if kind == "line":
                        self.progress = None
                        self.lines.append(item[1])
                        self.note_running(item[1])
                    elif kind == "progress":
                        self.progress = item[1]
                        self.note_running(item[1])
                        if item[1] and not self._ready:
                            self.run_status.set_text(item[1])
                    elif kind == "finished":
                        finished = item
            except queue.Empty:
                pass
            if changed:
                self.render_log()
            if self._finish_ui:
                self.show_finished()
                return False
            if finished is None:
                return True
            _kind, code, url = finished
            if url:
                self.url = url
                self.succeeded = True
            self.running = False
            self.timer = 0
            self.cancel_btn.set_visible(False)
            if code == 0:
                self.succeeded = True
            if code == 0 and self.url:
                self.show_finished()
                return False
            if code == 0:
                self.run_status.set_text("Install is finished.")
                self.next_btn.set_label("Close")
                self.next_btn.set_visible(True)
                self.next_btn.set_sensitive(True)
                return False
            elif self._cancelling or self.stop:
                self.run_status.set_text("Setup was cancelled.")
            else:
                self.run_status.set_text(
                    "Installation failed. Send this report to agent@danaco.se for assistance."
                )
            self.next_btn.set_label("Close")
            self.next_btn.set_visible(True)
            self.next_btn.set_sensitive(True)
            return False

        def browser_env(self):
            env = os.environ.copy()
            for key in (
                "LD_LIBRARY_PATH",
                "PYTHONHOME",
                "PYTHONPATH",
                "GI_TYPELIB_PATH",
                "GDK_PIXBUF_MODULEDIR",
                "GDK_PIXBUF_MODULE_FILE",
            ):
                env.pop(key, None)
            appdir = env.get("APPDIR", "")
            data_dirs = env.get("XDG_DATA_DIRS", "")
            if appdir and data_dirs:
                prefix = os.path.join(appdir, "usr", "share")
                kept = [part for part in data_dirs.split(":") if part and part != prefix]
                if kept:
                    env["XDG_DATA_DIRS"] = ":".join(kept)
                else:
                    env.pop("XDG_DATA_DIRS", None)
            return env

        def note_running(self, text):
            match = URL_RE.search(text or "")
            if not match:
                return
            self.url = match.group(1)
            self.succeeded = True
            self._finish_ui = True

        def show_finished(self):
            """The agent is running. Closing this window must leave it in place."""
            if self._ready:
                return
            self._ready = True
            self.succeeded = True
            self.running = False
            self.cancel_btn.set_visible(False)
            self.run_status.set_text("Install is finished.")
            self.next_btn.set_label("Close")
            self.next_btn.set_visible(True)
            self.next_btn.set_sensitive(True)
            self.on_open()
            self.win.close()

        def on_open(self):
            if not self.url:
                return False
            if shutil.which("xdg-open") is None:
                self.set_error(f"Open this address in a browser: {self.url}")
                return False
            subprocess.Popen(
                ["xdg-open", self.url],
                start_new_session=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                env=self.browser_env(),
            )
            return True

        def cleanup_failed(self):
            with self._clean_lock:
                if self.succeeded or self._cleaned:
                    return
                job = self.job
                if not job or job.get("reuse") == "2":
                    self._cleaned = True
                    return
                target = ""
                if job.get("reuse") == "3":
                    target = self._new_copy
                elif job.get("reuse") == "1":
                    if self._replaced:
                        target = job["folder"]
                else:
                    target = job["folder"]
                self._cleaned = True
            try:
                removed = bool(target) and remove_install_tree(target)
            except OSError:
                with self._clean_lock:
                    self._cleaned = False
                return
            if removed:
                if self.stop:
                    self.queue.put(("line", f"Setup was cancelled. Removed {target}"))
                else:
                    self.queue.put(("line", f"Setup did not finish. Removed {target}"))

        def stop_proc(self, sig):
            proc = self.proc
            if proc is None or proc.poll() is not None:
                return
            try:
                os.killpg(proc.pid, sig)
            except OSError:
                pass

        def on_cancel(self):
            if not self.running or self._cancelling:
                return
            self._cancelling = True
            self.stop = True
            self.cancel_btn.set_sensitive(False)
            self.run_status.set_text("Cancelling…")
            self.stop_proc(15)
            download_dir = self._download_dir
            if download_dir:
                shutil.rmtree(download_dir, ignore_errors=True)
            GLib.timeout_add(1000, self.finish_cancel)

        def finish_cancel(self):
            self.stop_proc(9)
            self.cleanup_failed()
            return False

        def on_close_request(self, _win):
            if self.succeeded:
                if self.timer:
                    GLib.source_remove(self.timer)
                    self.timer = 0
                return False
            self.stop = True
            proc = self.proc
            if proc is not None and proc.poll() is None:
                try:
                    os.killpg(proc.pid, 15)
                except OSError:
                    pass
            self.cleanup_failed()
            if self.timer:
                GLib.source_remove(self.timer)
                self.timer = 0
            return False

        def worker(self, job):
            code = 1
            url = ""
            try:
                code = self.install(job)
                url = self.url_from_log()
            except Exception as exc:
                self.queue.put(("line", f"Setup failed: {exc}"))
                code = 1
            if code != 0:
                self.cleanup_failed()
            self.queue.put(("finished", code, url))

        def url_from_log(self):
            return getattr(self, "_worker_url", "")

        def prepare_unsloth_model(self, name, dest, env):
            """Download a Hugging Face GGUF when this install.sh only knows ollama pull."""
            filename = UNSLOTH_FILES.get(name)
            if not filename:
                return True
            env["PATH"] = "/usr/local/bin:/usr/bin:" + env.get("PATH", "")
            if shutil.which("ollama", path=env["PATH"]) is None:
                self.queue.put(("line", "Installing Ollama..."))
                script_fd, script_path = tempfile.mkstemp(prefix="ollama-install-")
                os.close(script_fd)
                if self.run_proc(["curl", "-fsSL", "-o", script_path, "https://ollama.com/install.sh"], dest, env) != 0:
                    os.remove(script_path)
                    self.queue.put(("line", f"Could not download {name}. The browser was not opened."))
                    return False
                command = ["sh", script_path] if os.geteuid() == 0 else ["pkexec", "sh", script_path]
                installed = self.run_proc(command, dest, env) == 0
                os.remove(script_path)
                if not installed:
                    self.queue.put(("line", f"Could not download {name}. The browser was not opened."))
                    return False
            if ollama_listed(name, env):
                self.queue.put(("line", f"{name} is already downloaded."))
                return True
            self.queue.put(("line", f"Downloading {name}."))
            self.queue.put(("line", "Progress stays on screen. The install will not continue until the download finishes."))
            if self.run_proc(["ollama", "pull", name], dest, env) == 0 and ollama_listed(name, env):
                self.queue.put(("line", f"Finished downloading {name}."))
                return True
            parent = "/var/tmp" if os.path.isdir("/var/tmp") and os.access("/var/tmp", os.W_OK) else None
            workdir = tempfile.mkdtemp(prefix="danaco-model-", dir=parent)
            self._download_dir = workdir
            gguf = os.path.join(workdir, filename)
            url = f"https://huggingface.co/unsloth/Qwen3.6-35B-A3B-GGUF/resolve/main/{filename}"
            modelfile = os.path.join(workdir, "Modelfile")
            self.queue.put(("line", f"Downloading {url}"))
            try:
                if not self.download_url(url, gguf, env):
                    if not self.stop:
                        self.queue.put(("line", f"Could not download {name}. The browser was not opened."))
                    return False
                try:
                    size = os.path.getsize(gguf)
                except OSError:
                    size = 0
                if size < 1000000000:
                    if not self.stop:
                        self.queue.put(("line", f"Could not download {name}. The browser was not opened."))
                    return False
                with open(modelfile, "w", encoding="utf-8") as handle:
                    handle.write(
                        f"FROM {gguf}\n"
                        "PARAMETER min_p 0\n"
                        "PARAMETER num_ctx 32768\n"
                        "PARAMETER temperature 1\n"
                        "PARAMETER top_k 20\n"
                        "PARAMETER top_p 0.95\n"
                        "PARAMETER draft_num_predict 2\n"
                    )
                if self.run_proc(["ollama", "create", name, "-f", modelfile], workdir, env) != 0 or not ollama_listed(name, env):
                    self.queue.put(("line", f"Could not download {name}. The browser was not opened."))
                    return False
            finally:
                shutil.rmtree(workdir, ignore_errors=True)
                self._download_dir = ""
            self.queue.put(("line", f"Finished downloading {name}."))
            return True

        def download_url(self, url, dest, env):
            """Save url to dest and report one progress line."""
            size = {"total": 0}

            def lookup():
                size["total"] = remote_size(url)

            threading.Thread(target=lookup, daemon=True).start()
            proc = subprocess.Popen(
                ["curl", "-fL", "--retry", "3", "--retry-delay", "2", "-s", "-o", dest, url],
                cwd=os.path.dirname(dest),
                env=env,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
                start_new_session=True,
            )
            self.proc = proc
            started = time.monotonic()
            last_report = 0.0
            try:
                while proc.poll() is None:
                    if self.stop:
                        self.stop_proc(15)
                        try:
                            proc.wait(timeout=2)
                        except subprocess.TimeoutExpired:
                            self.stop_proc(9)
                            proc.wait()
                        return False
                    now = time.monotonic()
                    if now - last_report >= 0.5:
                        last_report = now
                        try:
                            got = os.path.getsize(dest)
                        except OSError:
                            got = 0
                        elapsed = max(now - started, 0.001)
                        speed = got / elapsed
                        total = size["total"]
                        if total > 0:
                            percent = int(got * 100 / total)
                            message = f"Downloading {percent}%, {format_bytes(got)} / {format_bytes(total)}, {format_bytes(speed)}/s"
                        else:
                            message = f"Downloading {format_bytes(got)}, {format_bytes(speed)}/s"
                        self.queue.put(("progress", message))
                    time.sleep(0.2)
            finally:
                if self.proc is proc:
                    self.proc = None
            return proc.returncode == 0

        def install(self, job):
            self._worker_url = ""
            dest = job["folder"]
            if not os.path.isfile(os.path.join(dest, "install.sh")):
                self.queue.put(("line", "Fetching Danaco Agent from GitHub..."))
                if self.run_proc(clone_command(dest), os.path.dirname(os.path.abspath(dest)), self.browser_env()) != 0:
                    self.queue.put(("line", "Could not download Danaco Agent."))
                    return 1
            if self.stop:
                return 1
            env = self.browser_env()
            if os.path.isdir(os.path.join(dest, ".git")):
                self.queue.put(("line", "Using the main version."))
                if self.run_proc(["git", "fetch", "origin", "main"], dest, env) == 0:
                    self.run_proc(["git", "checkout", "-B", "main", "FETCH_HEAD"], dest, env)
                    self.run_proc(["git", "reset", "--hard", "FETCH_HEAD"], dest, env)
            script = os.path.join(dest, "install.sh")
            if not os.path.isfile(script):
                self.queue.put(("line", "The download did not include install.sh."))
                return 1
            env["DANACO_NONINTERACTIVE"] = "1"
            env["DANACO_MODEL_MODE"] = job["mode"]
            env["DANACO_LOCAL_MODEL"] = job["local"]
            env["DANACO_BIND_HOST"] = job["bind"]
            env["DANACO_AI_ACT"] = job["ai_act"]
            env["DANACO_PASSWORD"] = job["password"]
            if job["reuse"]:
                env["DANACO_REUSE_CHOICE"] = job["reuse"]
            self.queue.put(("line", "Checking system packages."))
            command = ["bash", script]
            if shutil.which("stdbuf"):
                # Unbuffered stdout so carriage-return progress is not held until a newline.
                command = ["stdbuf", "-o0", "-eL", "bash", script]
            with open(script, encoding="utf-8", errors="replace") as handle:
                script_text = handle.read()
            if "import_unsloth_gguf" not in script_text:
                if not self.prepare_unsloth_model(job.get("local") or "", dest, env):
                    env["DANACO_PASSWORD"] = ""
                    job["password"] = ""
                    return 1
            code = self.run_proc(command, dest, env)
            env["DANACO_PASSWORD"] = ""
            job["password"] = ""
            if code == 0:
                installed = self._new_copy if job.get("reuse") == "3" and self._new_copy else dest
                register_linux_autostart(installed, env, lambda text: self.queue.put(("line", text)))
            return code

        def run_proc(self, command, cwd, env=None):
            proc = subprocess.Popen(
                command,
                cwd=cwd,
                env=env,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                stdin=subprocess.DEVNULL,
                start_new_session=True,
            )
            self.proc = proc
            decoder = codecs.getincrementaldecoder("utf-8")("replace")
            feed = LogFeed()
            last_progress = 0.0
            pending = None

            def emit(event):
                nonlocal last_progress, pending
                if event[0] == "progress":
                    now = time.monotonic()
                    if now - last_progress < 0.1:
                        pending = event
                        return
                    pending = None
                    last_progress = now
                    self.note_output(event)
                    self.queue.put(event)
                    return
                if pending is not None:
                    self.note_output(pending)
                    self.queue.put(pending)
                    pending = None
                self.note_output(event)
                self.queue.put(event)
                last_progress = time.monotonic()

            assert proc.stdout is not None
            code = 1
            try:
                while True:
                    chunk = proc.stdout.read(4096)
                    if not chunk:
                        break
                    for event in feed.feed(decoder.decode(chunk)):
                        emit(event)
                for event in feed.feed(decoder.decode(b"", final=True)):
                    emit(event)
                for event in feed.finish():
                    emit(event)
                code = proc.wait()
            finally:
                if pending is not None:
                    self.note_output(pending)
                    self.queue.put(pending)
                if proc.poll() is None:
                    try:
                        proc.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        try:
                            os.killpg(proc.pid, 15)
                        except OSError:
                            pass
                        proc.wait()
                self.proc = None
            return code

        def note_output(self, event):
            if event[0] != "line":
                return
            match = URL_RE.search(event[1])
            if match:
                self._worker_url = match.group(1)
            copy = NEW_COPY_RE.search(event[1])
            if copy:
                self._new_copy = copy.group(1).strip()
            if REPLACED_RE.search(event[1]):
                self._replaced = True

    class SetupApp(Gtk.Application):
        def __init__(self):
            super().__init__(application_id="com.danaco.agent-setup")

        def do_activate(self):
            if self.get_windows():
                self.get_windows()[0].present()
                return
            SetupWindow(self)

    raise SystemExit(SetupApp().run(sys.argv))


if __name__ == "__main__":
    main()
