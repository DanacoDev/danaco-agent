#!/usr/bin/env python3
"""Setup for Danaco Agent. A window when this computer has a graphical session, otherwise install.sh."""

import codecs
import os
import queue
import re
import shutil
import subprocess
import sys
import threading
import time

REPO_URL = "https://github.com/DanacoDev/danaco-agent.git"
URL_RE = re.compile(r"Agent Danaco is running at (https://\S+)")


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


def clone_command(dest):
    if shutil.which("gh"):
        probe = subprocess.run(
            ["gh", "auth", "status"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        if probe.returncode == 0:
            return ["gh", "repo", "clone", "DanacoDev/danaco-agent", dest]
    return ["git", "clone", REPO_URL, dest]


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
    compute = ""
    driver = ""
    if shutil.which("nvidia-smi"):
        try:
            result = subprocess.run(
                ["nvidia-smi", "--query-gpu=memory.total,compute_cap,driver_version", "--format=csv,noheader,nounits"],
                capture_output=True,
                text=True,
                timeout=8,
            )
        except (OSError, subprocess.TimeoutExpired):
            result = None
        for line in ((result.stdout if result is not None else "") or "").splitlines():
            parts = [part.strip() for part in line.split(",")]
            if len(parts) < 2 or not parts[0].isdigit():
                continue
            memory = int(parts[0])
            if memory > vram_mb:
                vram_mb = memory
                compute = parts[1]
                driver = parts[2] if len(parts) > 2 else ""
    ram_mb = 0
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("MemTotal:"):
                    ram_mb = int(line.split()[1]) // 1024
                    break
    except (OSError, IndexError, ValueError):
        ram_mb = 0
    major = 0
    head = compute.split(".", 1)[0]
    if head.isdigit():
        major = int(head)
    driver_major = 0
    driver_head = driver.split(".", 1)[0]
    if driver_head.isdigit():
        driver_major = int(driver_head)
    if vram_mb >= 15360:
        # Ollama runs NVFP4 on CUDA 13, which needs driver 580 or newer.
        if major >= 10 and driver_major >= 580:
            return "local", "qwen3.6:35b-a3b-nvfp4"
        return "local", "qwen3.6:35b-a3b-q4_K_M"
    if ram_mb >= 7168:
        return "local", "qwen3.5:9b-q4_K_M"
    return "cpu_failsafe", "qwen2.5:0.5b"


class LogFeed:
    """Split process output into finished lines and one in-progress line.

    A carriage return, as Ollama uses for download progress, replaces the
    in-progress line instead of starting another one.
    """

    def __init__(self):
        self.current = ""
        self.replace = False

    def feed(self, text):
        events = []
        for char in text:
            if char == "\r":
                self.replace = True
            elif char == "\n":
                events.append(("line", self.current))
                self.current = ""
                self.replace = False
            else:
                if self.replace:
                    self.current = ""
                    self.replace = False
                self.current += char
        if self.current or self.replace:
            events.append(("progress", self.current))
        return events

    def finish(self):
        if not self.current:
            return []
        line = self.current
        self.current = ""
        self.replace = False
        return [("line", line)]


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
            self.open_btn = Gtk.Button(label="Open")
            self.open_btn.set_visible(False)
            self.open_btn.connect("clicked", lambda *_a: self.on_open())
            self.next_btn = Gtk.Button(label="Next")
            self.next_btn.add_css_class("suggested-action")
            self.next_btn.connect("clicked", lambda *_a: self.on_next())
            buttons.append(self.back_btn)
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
            self.running = True
            self.stop = False
            self.url = ""
            self.lines = []
            self.progress = None
            self.open_btn.set_visible(False)
            self.back_btn.set_visible(False)
            self.next_btn.set_visible(False)
            self.run_status.set_text("Installing…")
            self.render_log()
            self.timer = GLib.timeout_add(100, self.pump)
            threading.Thread(target=self.worker, args=(job,), daemon=True).start()

        def render_log(self):
            body = "\n".join(self.lines)
            if self.progress is not None:
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
                        match = URL_RE.search(item[1])
                        if match:
                            self.url = match.group(1)
                    elif kind == "progress":
                        self.progress = item[1]
                    elif kind == "finished":
                        finished = item
            except queue.Empty:
                pass
            if changed:
                self.render_log()
            if finished is None:
                return True
            _kind, code, url = finished
            if url:
                self.url = url
            self.running = False
            self.timer = 0
            if code == 0 and self.url and self.on_open():
                self.win.close()
                return False
            if code == 0 and self.url:
                self.run_status.set_text(f"Danaco Agent is running at {self.url}")
                self.open_btn.set_visible(True)
            elif code == 0:
                self.run_status.set_text("Setup finished.")
            else:
                self.run_status.set_text("Setup did not finish. The log above has the details.")
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

        def on_close_request(self, _win):
            self.stop = True
            proc = self.proc
            if proc is not None and proc.poll() is None:
                try:
                    os.killpg(proc.pid, 15)
                except OSError:
                    pass
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
            self.queue.put(("finished", code, url))

        def url_from_log(self):
            return getattr(self, "_worker_url", "")

        def install(self, job):
            self._worker_url = ""
            dest = job["folder"]
            if not os.path.isfile(os.path.join(dest, "install.sh")):
                self.queue.put(("line", "Fetching Danaco Agent from GitHub..."))
                if self.run_proc(clone_command(dest), os.path.dirname(os.path.abspath(dest))) != 0:
                    self.queue.put(("line", "Could not download Danaco Agent."))
                    return 1
            if self.stop:
                return 1
            script = os.path.join(dest, "install.sh")
            if not os.path.isfile(script):
                self.queue.put(("line", "The download did not include install.sh."))
                return 1
            env = os.environ.copy()
            env["DANACO_NONINTERACTIVE"] = "1"
            env["DANACO_MODEL_MODE"] = job["mode"]
            env["DANACO_LOCAL_MODEL"] = job["local"]
            env["DANACO_BIND_HOST"] = job["bind"]
            env["DANACO_AI_ACT"] = job["ai_act"]
            env["DANACO_PASSWORD"] = job["password"]
            if job["reuse"]:
                env["DANACO_REUSE_CHOICE"] = job["reuse"]
            command = ["bash", script]
            if shutil.which("stdbuf"):
                # Unbuffered stdout so carriage-return progress is not held until a newline.
                command = ["stdbuf", "-o0", "-eL", "bash", script]
            code = self.run_proc(command, dest, env)
            env["DANACO_PASSWORD"] = ""
            job["password"] = ""
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
