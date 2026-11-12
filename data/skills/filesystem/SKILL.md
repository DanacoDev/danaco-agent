---
name: filesystem
description: Project files. action is list, read, write, delete, move, or copy. The main agent uses list and read. A subagent changes files.
version: 1.0.0
---

args action. This is the only project-file skill. Do not call list_files, read_file, write_file, delete_file, or move_file.

list: path, optional. Lists the files under that path. When a project is open, path is inside that project. In general chat, omit path to list uploads/. When full system control is on, a path that starts with / lists that folder.

read: path. Reads that file, including a wiki file. In general chat, path is under uploads/, such as uploads/notes.txt. When full system control is on, a path that starts with / reads that file. An svg is text, so use read rather than read_image. The main agent can list and read.

write: path, content. A subagent creates or overwrites a file. It can create folders in the path. Markdown belongs in wiki/. A skill is not a project file. The coder uses write_skill for a skill. The main agent delegates this.

delete: path. A subagent moves a file or folder into the trash above the project. path is relative, such as old/notes.txt. This cannot delete backups or trash. The main agent delegates this.

move: from, to. A subagent moves a file or folder. It can create folders in the path. The destination must not already exist. The main agent delegates this.

copy: from, to. A subagent leaves the original where it is and writes a copy at to, including the contents of a folder. The destination must not already exist. The main agent delegates this.
