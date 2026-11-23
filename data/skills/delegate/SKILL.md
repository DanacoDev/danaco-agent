---
name: delegate
description: Sends one task to a subagent, including the Consultant. args agent, instruction, project, task_id. Only the main agent calls this.
version: 1.0.0
---

args agent, instruction, project, task_id. agent is researcher, writer, coder, analyst, marketer, or consultant. One subagent at a time. Pick the agent from the team section. Delegating a task sets it to in_progress. task_id is required. Create the task first. Then list the tasks and read the id from that list. Then delegate with that id only when the list line does not say scheduled. A scheduled task waits. The scheduler starts it when the time arrives. Do not invent an id. A number from an older message is not a task. If that id is not in the latest list, do not delegate. A subagent adds the result and crosses off the step that just finished. When it returns, list the task list and read that result. If the task is done, do not delegate it again. If a step is still open, delegate only that step. If that step is assigned to human, do not delegate. Ask the user and wait. Do not start a finished step over.

project must be the open project folder. Pass only the task instructions and the summary the subagent needs. consultant is the Consultant persona on an external paid model, for a hard multi-step problem. Delegate to it only when the team note says the Consultant is on. It costs token credits. In general chat it can still take an assignment, with no project tag.
