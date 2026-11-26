---
name: research_loop
description: A long research mission in database/research-<slug>.db. Not a lookup log. args action, name. action is mission, add, next, commit, review, or status.
version: 1.0.0
---

A research mission is this skill and one database. The file is database/research-<slug>.db. The skill makes the slug from the title. UAP propulsion becomes research-uap-propulsion. The same title opens the same file. Do not pass a filename. Do not invent research.db, research1.db, or research2.db. Subjects inside one question are rows in that file, not more databases and not wiki files.

name is the slug the mission action returned. If the project has one mission, name may be omitted. If it has several, name is required. status with no name lists the slugs and stops.

Do not pass sql, a table, a column list, or a filename. Do not open the file with sqlite_helper, filesystem, run_command, or run_python. Do not write a wiki markdown for a finding, a source list, a hypothesis, or a phase. A lookup that is not a mission is saved with wiki_memory. It is not this skill.

Actions:

- mission: question, and title or scope. title defaults to the question. Creates the database and returns the slug. The same title returns the same slug.
- add: name, and path or url or text, plus title, year, authors. Chunks the text and queues it. A pdf is read here. A page that will not extract is marked skipped. The same path or url is not added twice. Call mission first.
- next: name. Returns one packet, or stage propose, awaiting, or idle. One call, then stop.
- commit: name, and kind delta, topic, reflect, or lint. A claim is never deleted. A superseded claim stays, with superseded_by set to chunk:<id>.
- review: name, id, and decision testing or parked, or statement for an edited wording.
- status: the question, one line per hypothesis, open contradictions, and queue depths. It does not write a file.

action mission creates the database and the one task. The task is titled Research: and the short title. It has one step, One pass, agent researcher, recurrence 15min. The step text is fixed. Do not call tasklist to create it, and do not add or rewrite its steps. Do not add a second task for the same slug. Do not add a phase, a batch, a source list, a consultant pass, a daily inspection, a review task, or a human step.

The fixed step is this, and nothing more:

- Call status with name set to the slug.
- If there is no mission, stop. The result line is need mission.
- If there are no hypotheses yet, commit kind reflect with at most three hypotheses. Each item is {"statement":"one sentence","status":"proposed"}. statement is the sentence. Then stop. The result line is awaiting approval. Do not call tasklist result, so the step stays open. The researcher makes this commit. The main agent delegates the step.
- If a hypothesis is still proposed and none are testing, stop. The result line is awaiting approval. Do not ingest. Do not call tasklist result.
- Otherwise call next once. If the stage is idle, the result line is idle and you stop without a step result. Otherwise judge that one packet, call commit, then call tasklist result with one line: the stage, the chunk or topic id, and the queue depths. Put needs_review on that line when the commit says so. Do not take another packet.

The user approves in chat. Then call review with decision testing, parked, or an edited statement. Do not ask a question in a scheduled run. Do not create a task to ask. The 15-minute repeat is already set. To pause it, set schedule_time to never on this same task.

A pass does one packet and exits. Do not batch.

Commit kind delta items use kind reinforces, extends, contradicts, temporally_invalidates, or novel. contradicts and temporally_invalidates name the claim_id they touch. novel is only for a sentence that is not already a claim. Each item needs the chunk_id. reinforces does not enqueue a reevaluation. A wording tweak does not either.

Commit kind topic rewrites that topic's synthesis and returns every claim id on the topic. A missing claim is rejected. superseded_by is chunk:<id>. Name other topic ids only when a load-bearing assumption changed.

Commit kind reflect may add a hypothesis only as proposed. It may add up to five falsification queries. A query becomes a source to fetch later. It does not replace the mission.

When next returns stage reevaluate, you are the critic for that packet only:

- Compare authority, recency, and scope. Do not overwrite a claim because the new source is newer alone.
- Decide whether the new evidence falsifies the claim, narrows it, or leaves it contested.
- Mark a falsified claim superseded and set superseded_by to the new chunk id. Never drop the row.
- Return a shorter synthesis when the packet says the synthesis is over the cap.
- If a load-bearing assumption changed, name the other topic ids to enqueue. Do not edit any other topic in this pass.
