---
name: ftp
description: Lists, downloads, or uploads on a project FTP server. args action, and account when there is more than one server.
version: 1.0.0
---

args action (list, download, or upload). list uses path. download uses remote and local. upload uses local and remote. When this project has one server, use it and do not ask which server. When it has several, ask which server to use before you call this, unless the user already named one or it is otherwise obvious. account is that server's name.
