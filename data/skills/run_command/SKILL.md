---
name: run_command
description: Runs a shell command in the project. args command. Unavailable while full system control is off.
version: 1.0.0
---

args command. When full system control is on, the command may use sudo. It starts in the project folder when there is one. A path that starts with / is anywhere on this computer. Do not install software. Only the main agent can install a dependency, and the user is asked first. Do not run Python. A .py file runs through run_python.

When full system control is off, this skill is unavailable. Use another skill instead. A Python file still runs through run_python.
