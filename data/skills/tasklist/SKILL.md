---
name: tasklist
description: The task list. The main agent lists, creates, updates, and deletes tasks. A subagent can list or add a result.
version: 1.0.0
---

args action. The main agent uses list, create, update, or delete. A subagent uses list or result.

list: no other args.
create: title, steps, agent, status, schedule_time (YYYY-MM-DD HH:MM), recurrence (none, 5min, 15min, 30min, hourly, daily, weekly, or monthly). steps is a list of small pieces of the task. Do not write an instructions paragraph. One instruction is stored as step 1. none means run once. Leave schedule_time empty only when the task should run immediately. A later task needs a real clock time. Do not invent one. Set schedule_time to never to inactivate a task and keep it. That task stays on the list and does not run. Before create, list the tasks and check for a duplicate or a similar task. Do not add a task for work that is already there. An exact duplicate is not added. The tool returns an error that names the existing task. Use that task.
update: id, plus any of status, steps, result, agent, schedule_time, recurrence. A task has no instructions. Add the small pieces as steps. A task marked held was stopped by the user. Leave it held unless the user asked you to change, resume, or end it. A new schedule or repeat resumes it. Set schedule_time to never to inactivate a task and keep it. It stays in_progress and does not run. A real time, or an empty time, turns it back on. Set a finished run-once task to done and include result.
delete: id. Removes that task. Use it when a task is wrong, stale, or the user asks you to remove it.
result: id and result. A subagent adds a short result to the task it was given. That is the only change a subagent can make.

A task is in_progress or done. in_progress = work happening now, a task with a time or a repeat, or a task set to never. A repeating task stays in_progress. done = a finished run-once task; include result. There is no backlog. Never (inactivated) is how you disable a task and keep it. An idea for later is not a task. Write it in the project wiki. Only the main agent creates, updates, or deletes tasks. A subagent can only add a result. The main agent checks that result, then updates the task. You cannot archive a task, and you cannot set a repeating task to done.

Tasks are for an agent, or a reminder that will send something or take an action. Not a static todo for the user.
After the user has chosen now or scheduled, every future event needs its own task. A scheduled task needs schedule_time. Saying you will do it later does not schedule it. Split several events into separate tasks. When the work repeats, set recurrence and have the coder register a skill with write_skill for the repeated steps when that is possible. Name that skill in a step. A file in the project is not a skill.
A result is assigned to the subagent who produces it. Planning with the user is assigned to orchestrator.
Do not add a task while you are still planning. Set a task to in_progress when the user chose now and you start it, or when a scheduled task is due. Set a finished run-once task to done. Leave a repeating task in in_progress, with its repeat still set. Do not leave a finished run-once task in in_progress. Do not delegate new tasks in the planning turn unless the user chose now.
After every delegated job, list the tasks and update the matching ones before you reply or delegate again, even when this chat does not mention any tasks. A cleared context still has tasks. Do not archive. Do not explain these steps in place of updating them.
