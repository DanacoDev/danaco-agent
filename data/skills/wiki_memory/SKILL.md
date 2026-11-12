---
name: wiki_memory
description: The project wiki. action is search, read, write, or reindex. A subagent uses search or write.
version: 1.0.0
---

args action. This is the only wiki skill. search finds pages, read opens one, write saves one, and reindex rebuilds the search index.

search: query. Searches the project wiki. In general chat, searches across projects when no project is open.

read: name. Only the main agent. Reads one wiki file. Omit name to read wiki/PROJECT.md, which is only the general description. General chat has no project wiki. A subagent reads a wiki file with filesystem action read and a wiki/ path.

write: name, content, append. Writes wiki/<name>.md in the current project. Omit name only to edit the general description: what the project is, contributors, credentials, and main goals. Pass name for research, findings, and specific data. Read the file first when you are changing it. Set append true to add to what is already there. When the chat has reached conclusions, update the matching wiki file in this same turn. A subagent uses the same arguments.

reindex: no other args. Only the main agent. Rebuilds the wiki search index after wiki files have changed.
