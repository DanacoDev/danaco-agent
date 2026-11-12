---
name: delegate
description: Sends one task to a subagent, including the Consultant. args agent, instruction, project, task_id. Only the main agent calls this.
version: 1.0.0
---

args agent, instruction, project, task_id. agent is researcher, writer, coder, analyst, marketer, or consultant. One subagent at a time. Pick the agent from the team section. Delegating a card moves it to in_progress. Pass task_id when that card already exists. A subagent can only add a result to that card. When it returns, list the kanban, check that the work was done, and you move or update the card before the next step, even if you have no recollection of any tasks.

project must be the open project folder. Pass only the task instructions and the summary the subagent needs. consultant is the Consultant persona on an external paid model, for a hard multi-step problem. Delegate to it only when the team note says the Consultant is on. It costs token credits. In general chat it can still take an assignment, with no project tag.
