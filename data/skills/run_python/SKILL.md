---
name: run_python
description: Runs one project Python file inside the project. args path. It cannot see other folders or a database file. Prefer PHP.
version: 1.0.0
---

args path. path is a .py file inside the project, such as data/backtest.py. This does not need full system control.

Write the program in PHP when you can. This app is PHP. A skill's program is PHP and is called by its skill name. Use Python only when the task needs a Python library, then call run_python. Do not use run_command to start Python, and do not ask for full system control to run a script.

The process can read and write files in the project. It cannot see the rest of this computer, and it cannot open a file in database/ or a file ending in .db, .sqlite, or .sqlite3. Read and write those rows with sqlite_helper. Save the rows as JSON or CSV, and have the script read that file. Do not import sqlite3.

It may use the network. It cannot install packages. If a library is missing, the result names it. Stop and report that name. The main agent installs it into the project after the user agrees, and then you run the file again. The run stops after three minutes.
