---
name: research_loop
description: A long research mission in database/research-<slug>.db. Not a lookup log. args action, name. action is mission, add, next, commit, review, status, board, or active.
version: 1.0.0
---

A research mission is this skill and one database. The file is database/research-<slug>.db. The skill makes the slug from the title. UAP propulsion becomes research-uap-propulsion. The same title opens the same file. Do not pass a filename. Do not invent research.db, research1.db, or research2.db. Subjects inside one question are rows in that file, not more databases and not wiki files.

name is the slug the mission action returned. If the project has one mission, name may be omitted. If it has several, name is required. status with no name lists the slugs and stops.

Do not pass sql, a table, a column list, or a filename. Do not open the file with sqlite_helper, filesystem, run_command, or run_python. Do not write a wiki markdown for a finding, a source list, a hypothesis, or a phase. A lookup that is not a mission is saved with wiki_memory. It is not this skill.

Actions:

- mission: question, and title or scope. title defaults to the question. Creates the database and returns the slug. The same title returns the same slug. It does not create a task.
- add: name, and path or url or text, plus title, year, authors. Chunks the text and queues it. A pdf is read here. A page that will not extract is marked skipped. The same path or url is not added twice. Call mission first.
- next: name. Returns one packet, or stage propose, awaiting, reflect, or idle. One call, then stop.
- commit: name, and kind delta, topic, reflect, or lint. A claim is never deleted. A superseded claim stays, with superseded_by set to chunk:<id>.
- review: name, id, and decision testing, parked, or noted, or statement for an edited wording, or direction for where that branch should go next. noted clears needs_review and leaves the status.
- status: the question, one line per hypothesis with its parent, insight, and direction, open contradictions, queue depths, and whether the switch is on. It does not write a file. This is how you answer a question about progress.
- board: the tree for the Research tab. Do not call it.
- active: name, on 1 or 0. Turns that mission on or off. On pauses every other mission, in this project and in every other project. Only one mission runs. It keeps going until it is turned off. It waits while a decision is needed, while it is idle, and while chat or a task is using the model.

action mission creates the database only. The file is database/research-<slug>.db. The mission shows on the Research tab. Do not call tasklist to create a task. Do not add a phase, a batch, a source list, a consultant pass, a daily inspection, a review task, or a human step. Do not delete a mission. The user deletes it on the Research tab.

The main agent may call mission, status, and active. It answers questions about progress from status. It does not call add, next, commit, or review. The researcher runs the fixed passes. One pass is one packet. The loop calls the next pass. Do not batch. Do not call tasklist. Do not call active or review from a pass.

- Call status with name set to the slug.
- If there is no mission, stop.
- If there are no hypotheses yet, commit kind reflect with at most three roots. Each item is {"statement":"one sentence","status":"proposed","parent_id":0}. Then stop.
- If a hypothesis is still proposed and none are testing, stop. Do not ingest. A hypothesis the user kept is testing. Every testing hypothesis is explored. A proposed hypothesis that is still waiting does not stop those.
- Otherwise call next once. If the stage is idle or awaiting, stop. If the stage is reflect, read constraints first. Lessons are refuted and parked nodes. Do not restate one. Findings are supported and narrowed nodes. Build on them. Propose at most three children. Each new item includes parent_id of the parent hypothesis. A root is only those first three, and a root is a direction. A child refines, corrects, or extends its parent. It is a claim a source can check, not a repeat of the parent or of a sibling. A child hangs from a hypothesis that is testing, supported, or narrowed. To revisit a lesson, put counter #<id> in falsification.
- When you set a hypothesis to supported, narrowed, or refuted, include insight, one or two sentences. That sentence is the conclusion on that node. The skill carries the lesson up to the parent.
- Otherwise judge that one packet, call commit, then stop. Do not take another packet.

The user steers on the Research tab. Keep on a proposed hypothesis sets testing. Every kept hypothesis is explored. Park sets parked and parks the open nodes under it. A direction note says where to look. It is not evidence. Do not ask a question in a pass. Do not create a task to ask. Parked and refuted nodes stay as lessons.

Commit kind delta items use kind reinforces, extends, contradicts, temporally_invalidates, or novel. contradicts and temporally_invalidates name the claim_id they touch. novel is only for a sentence that is not already a claim. Each item needs the chunk_id. reinforces does not enqueue a reevaluation. A wording tweak does not either.

Commit kind topic rewrites that topic's synthesis and returns every claim id on the topic. A missing claim is rejected. superseded_by is chunk:<id>. Name other topic ids only when a load-bearing assumption changed.

Commit kind reflect may add a hypothesis only as proposed. The first three are roots, parent_id 0. Every hypothesis after that includes parent_id. A reflect adds at most three new hypotheses. Read constraints.lessons first. A new statement that restates a lesson, a finding, a parent, or a sibling is rejected. counter #<id> in falsification is how a lesson can be revisited. It may add up to five falsification queries. A query becomes a source to fetch later. It does not replace the mission. A settled hypothesis needs insight. The skill then writes inherited on the ancestors from the child lessons.

When next returns stage reevaluate, you are the critic for that packet only:

- Compare authority, recency, and scope. Do not overwrite a claim because the new source is newer alone.
- Decide whether the new evidence falsifies the claim, narrows it, or leaves it contested.
- Mark a falsified claim superseded and set superseded_by to the new chunk id. Never drop the row.
- Return a shorter synthesis when the packet says the synthesis is over the cap.
- If a load-bearing assumption changed, name the other topic ids to enqueue. Do not edit any other topic in this pass.
