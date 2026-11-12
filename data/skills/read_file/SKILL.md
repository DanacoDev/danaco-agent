---
name: read_file
description: Reads a file in the project, or a file under uploads in general chat. args path.
version: 1.0.0
---

args path. Reads that file. Any file in the open project, including the wiki.

In general chat, path is a file under uploads/, such as uploads/notes.txt. When full system control is on, a path that starts with / reads that file. An svg is text, so use this rather than read_image.
