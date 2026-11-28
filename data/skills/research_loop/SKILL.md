---
name: research_loop
description: A long research mission in database/research-<slug>.db. Not a lookup log. args action, name. action is mission, add, next, commit, review, idea, status, board, active, auto, or read.
version: 1.0.0
---

A research mission is this skill and one database. The file is database/research-<slug>.db. The skill makes the slug from the title. UAP propulsion becomes research-uap-propulsion. The same title opens the same file. Do not pass a filename. Do not invent research.db, research1.db, or research2.db. Subjects inside one question are rows in that file, not more databases and not wiki files.

name is the slug the mission action returned. If the project has one mission, name may be omitted. If it has several, name is required. status with no name lists the slugs and stops.

Do not pass sql, a table, a column list, or a filename. Do not open the file with sqlite_helper, filesystem, run_command, or run_python. Do not write a wiki markdown for a finding, a source list, a hypothesis, or a phase. A lookup that is not a mission is saved with wiki_memory. It is not this skill.

Actions:

- mission: question, and title or scope. title defaults to the question. Creates the database and returns the slug. The same title returns the same slug. It does not create a task.
- add: name, and path or url or text, plus title, year, authors. Chunks the text and queues it. A pdf is read here. A page that will not extract is marked skipped. The same path or url is not added twice. Call mission first.
- next: name. Returns one packet, or stage propose, awaiting, reflect, conclude, solution, or idle. One call, then stop.
- commit: name, and kind delta, topic, reflect, or lint. A claim is never deleted. A superseded claim stays, with superseded_by set to chunk:<id>.
- review: name, id, and decision testing, parked, or noted, or statement for an edited wording, or direction for where that branch should go next. noted clears needs_review and leaves the status. A root statement is one to four words.
- idea: name, statement, and parent_id. The Research tab adds an idea this way. Do not call it. A root, parent_id 0, is one to four words. A child is a sentence under that parent.
- status: the question, one line per hypothesis with its parent, insight, and direction, open contradictions, queue depths, and whether the switch is on. It does not write a file. This is how you answer a question about progress.
- read: name, and optionally source_id or chunk_id. With neither, it returns the mission notes and the source list. source_id returns that source and its pages. chunk_id returns one page. It does not change the mission. A research pass does not call it.
- board: the tree for the Research tab. Do not call it.
- active: name, on 1 or 0. Turns that mission on or off. On pauses every other mission, in this project and in every other project. Only one mission runs. It keeps going until it is turned off. It waits while a decision is needed, while it is idle, and while chat or a task is using the model.
- auto: name, on 1 or 0. The user turns this on from the Research tab. The researcher does not call it. On keeps new ideas, lets a pass park a weak branch, and keeps going while any branch is still testing. It stops when every branch is settled.

action mission creates the database only. The file is database/research-<slug>.db. The mission shows on the Research tab. Do not call tasklist to create a task. Do not add a phase, a batch, a source list, a consultant pass, a daily inspection, a review task, or a human step. Do not delete a mission. The user deletes it on the Research tab.

The main agent may call mission, status, and active. It answers questions about progress from status. It does not call add, next, commit, review, or idea. The user or the main agent creates a mission. The researcher runs the fixed passes and stays on the slug it was given. One pass is one packet. The loop calls the next pass. Do not batch. Do not call tasklist. Do not call mission, active, or review from a pass.

- Call status with name set to the slug.
- If there is no mission, stop.
- If there are no hypotheses yet, commit kind reflect with hypotheses set to at most three roots. Each one is {"statement":"one to four words","status":"proposed","parent_id":0}. A root is a short title of that direction, not a sentence. The argument name is hypotheses. Then stop.
- If a hypothesis is still proposed and none are testing, stop. Do not ingest. A hypothesis the user kept is testing. Every testing hypothesis is explored. A proposed hypothesis that is still waiting does not stop those.
- Otherwise call next once. If the stage is idle or awaiting, stop. If the packet says the hypotheses are approved, add falsification queries for the testing hypotheses and do not add a hypothesis in that pass. If the stage is reflect, read constraints first. Lessons are refuted and parked nodes. Do not restate one. Findings are supported and narrowed nodes. Leave a finding as it is unless it has a direction. Propose at most three children. Each new item includes parent_id of the parent hypothesis. The one-to-four-word title is only for the first roots. A child is one full sentence, not a short title. It refines, corrects, or extends its parent. It is a claim a source can check, not a repeat of the parent or of a sibling. A child hangs from a hypothesis that is testing. A settled hypothesis gets a child only when it has a direction. To revisit a lesson, put counter #<id> in falsification.
- When you set a hypothesis to supported, narrowed, or refuted, include insight, one or two sentences. That sentence is the conclusion on that node. That settles only that node. Every other testing branch stays testing. The skill carries the lesson up to the parent.
- If the packet is a falsification query, search it with arxiv first, then web_search. A paper weighs a little more than a web page when both bear on the claim. Read a useful paper with arxiv action read. Otherwise judge that one packet and call commit. If that commit is rejected, send the corrected commit. Stop after the commit succeeds. Do not take another packet. Do not turn the mission off.

The user steers on the Research tab. Keep on a proposed hypothesis sets testing. Every kept hypothesis is explored. Park sets parked and closes the open cards under it. A finding under a parked node stays, and it gets no children. A direction note says where to look. It is not evidence. A direction on a parked node sets that node back to testing, unless a parent of it is parked. Do not ask a question in a pass. Do not create a task to ask. Parked and refuted nodes stay as lessons.

Commit kind delta passes chunk_id once and items, a list. When claims is empty, the item is {"kind":"novel","text":"one sentence"}. reinforces, contradicts, and temporally_invalidates are {"kind":"reinforces","claim_id":1}. claim_id is one number from claims. Do not pass claim_ids. kind is reinforces, extends, contradicts, temporally_invalidates, or novel. novel is only for a sentence that is not already a claim. reinforces does not enqueue a reevaluation. A wording tweak does not either.

Commit kind topic rewrites that topic's synthesis and returns every claim id on the topic. A missing claim is rejected. superseded_by is chunk:<id>. Name other topic ids only when a load-bearing assumption changed.

Commit kind reflect may add a hypothesis only as proposed. The first three are roots, parent_id 0. Every hypothesis after that includes parent_id. A reflect adds at most three new hypotheses. Read constraints.lessons first. A new statement that restates a lesson, a finding, a parent, or a sibling is rejected. counter #<id> in falsification is how a lesson can be revisited. It may add up to five falsification queries. A query is searched with arxiv and the web on the next pass. It does not replace the mission. A settled hypothesis needs insight. The skill then writes inherited on the ancestors from the child lessons. An idea that already has children keeps its wording. A commit that changes that statement is rejected. When the mission is autonomous and no branch is still testing, stage conclude asks for one conclusion on an existing idea. It does not change the other ideas. A new idea in that pass is rejected. A settled node stops. Every other testing branch stays testing.

When next returns stage reevaluate, you are the critic for that packet only:

- Compare authority, recency, and scope. Do not overwrite a claim because the new source is newer alone.
- Decide whether the new evidence falsifies the claim, narrows it, or leaves it contested.
- Mark a falsified claim superseded and set superseded_by to the new chunk id. Never drop the row.
- Return a shorter synthesis when the packet says the synthesis is over the cap.
- If a load-bearing assumption changed, name the other topic ids to enqueue. Do not edit any other topic in this pass.
