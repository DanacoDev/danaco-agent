---
name: task_breakdown
description: Only the main agent calls this. Adds in_progress cards for work that should run now. Check the board for a duplicate first. args project_id, tasks.
version: 1.0.0
---

Only the main agent calls this. A subagent does not. Not for an idea, and not for a later task with no time.

args project_id, tasks. Each task has title and agent. This adds one in_progress card per step for work that should run now. Check the board for a duplicate or a similar task first. An exact duplicate is not added and returns an error. This is not a user todo.
