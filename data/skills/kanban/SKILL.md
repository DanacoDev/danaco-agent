---
name: kanban
description: The task board. The main agent lists, creates, moves, and deletes cards. A subagent can list or add a result.
version: 1.0.0
---

args action. The main agent uses list, create, move, or delete. A subagent uses list or result.

list: no other args.
create: title, description, agent, status, schedule_time (YYYY-MM-DD HH:MM), recurrence (none, 5min, 15min, 30min, hourly, daily, weekly, or monthly). none means run once. Leave schedule_time empty only when the card should run immediately. A later card needs a real clock time. Do not invent one. Set schedule_time to never to inactivate a card and keep it. That card stays on the board and does not run. Before create, list the board and check for a duplicate or a similar task. Do not add a card for work that is already there. An exact duplicate is not added. The tool returns an error that names the existing card. Use that card.
move: id, plus any of status, description, result, agent, schedule_time, recurrence. A card marked held was stopped by the user. Leave it held unless the user asked you to change, resume, or end it. A new schedule or repeat resumes it. Set schedule_time to never to inactivate a card and keep it. It stays in in_progress and does not run. A real time, or an empty time, turns it back on. Moving a run-once card to review ends it.
delete: id. Removes that card from the board. Use it when a card is wrong, stale, or the user asks you to remove it.
result: id and result. A subagent adds a short result to the card it was given. That is the only change a subagent can make.

The board has two columns. in_progress = work happening now, a card with a time or a repeat, or a card set to never. A repeating card stays in in_progress. review = a finished run-once card waiting for the user; include result. There is no backlog. Never (inactivated) is how you disable a card and keep it. An idea for later is not a card. Write it in the project wiki. Only the main agent creates, moves, edits, or deletes cards. A subagent can only add a result. The main agent checks that result, then moves or updates the card. You cannot set done, and you cannot move a repeating card to review.

Cards are for an agent, or a reminder that will send something or take an action. Not a static todo for the user.
After the user has chosen now or scheduled, every future event needs its own card. A scheduled card needs schedule_time. Saying you will do it later does not schedule it. Split several events into separate cards. When the work repeats, set recurrence and have the coder register a skill with write_skill for the repeated steps when that is possible. Name that skill in the card description. A file in the project is not a skill.
A result is assigned to the subagent who produces it. Planning with the user is assigned to orchestrator.
Do not create a card while you are still planning. Move a task to in_progress when the user chose now and you start it, or when a scheduled card is due. Move a finished run-once card to review. Leave a repeating card in in_progress, with its repeat still set. Do not leave a finished run-once card in in_progress. Do not delegate new cards in the planning turn unless the user chose now.
After every delegated job, list the board and update the matching cards before you reply or delegate again, even when this chat does not mention any tasks. A cleared context still has cards. Do not explain the workflow in place of updating them.
