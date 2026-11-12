---
name: wiki_write
description: Writes a markdown file in the project wiki. args name, content, append.
version: 1.0.0
---

args name, content, append. Writes wiki/<name>.md in the current project. Omit name only to edit the general description: what the project is, contributors, credentials, and main goals. Pass name for research, findings, and specific data. Read the file first when you are changing it. Use append to add to what is already there. When the chat has reached conclusions, update the matching wiki file in this same turn.

A subagent uses the same arguments. name is a markdown filename in wiki/. Omit it only to update the general description. Pass a new name for research, findings, and specific data.
