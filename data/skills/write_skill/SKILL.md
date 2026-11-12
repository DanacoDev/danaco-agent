---
name: write_skill
description: Creates or updates a custom skill in the Skills library. args name, description, optional source, and the program in script when the skill runs code. The same name updates a custom skill. A system skill cannot be changed.
version: 1.0.0
---

Do not call this unless the main agent explicitly asked you to create or change a skill. This is the only way to create or edit one. It writes a skill folder outside the project, so the Skills page lists it and later runs, including scheduled tasks, can call it by name.

args:
- name. Lowercase letters, numbers, underscores, or hyphens. atr_ranker and atr-ranker are both valid. A prebuilt skill is the same either way, so write_file and write-file are one skill. This becomes the folder name.
- description. What the skill does and when to use it. On a new skill, when source is omitted, this is saved as the instructions. On an existing custom skill, omit it to keep the current description.
- source. Optional. Short markdown instructions only: what it does, when to use it, and the arguments. Do not paste the program here. A SKILL.md that starts with --- is accepted, and the text after the frontmatter is still instructions, not code. On an existing custom skill, omit it to keep the current instructions.
- script. The PHP or Python program, when calling the skill should run code. It reads one JSON object from stdin and prints the result. This is saved as scripts/run.php or scripts/run.py, and that file is what runs. Omit it when the skill is only instructions. On an existing custom skill, omit it to keep the current program.
- lang. py or php, matching script. php is the default. When lang is omitted, it is taken from the script.
- overwrite. Not required. The same name updates a custom skill.

Calling write_skill with the name of a custom skill updates it. Send the fields that change. An omitted description, source, or script stays as it is. A new source replaces the instructions. A new script replaces the program. A system skill cannot be edited or overwritten.

SKILL.md is not the program. Calling the skill runs script. It does not return the source code. If the program is pasted into source and script is empty, the program is moved into script and only the instructions stay in SKILL.md. If both source and script contain the program, the script you sent is kept and the program is removed from the instructions.

A script is syntax-checked and is not kept when the check fails. Send that script again in one call. Do not write a skill into the project, and do not create a skills or data/skills folder there.

A subagent creates or edits a skill only when the main agent explicitly asks for that new or changed skill. Delegate this to the coder. The main agent calls it when the coder cannot finish. After it is saved, call test_skill. A skill created in this run cannot be called by its own name until a later run. An updated skill can.
