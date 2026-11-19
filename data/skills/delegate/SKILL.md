---
name: delegate
description: Sends one task to a subagent, including the Consultant. args agent, instruction, project, task_id. Only the main agent calls this.
version: 1.0.0
---

args agent, instruction, project, task_id. agent is researcher, writer, coder, analyst, marketer, or consultant. One subagent at a time. Pick the agent from the team section. Delegating a task sets it to in_progress. Pass task_id when that task already exists. A subagent adds the result and crosses off the finished steps. When it returns, list the task list and read that result. If the reply says the task is done, or it names the saved file, do not delegate that task again.

project must be the open project folder. Pass only the task instructions and the summary the subagent needs. consultant is the Consultant persona on an external paid model, for a hard multi-step problem. Delegate to it only when the team note says the Consultant is on. It costs token credits. In general chat it can still take an assignment, with no project tag.
