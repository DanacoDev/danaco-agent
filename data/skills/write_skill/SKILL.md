---
name: write_skill
description: Registers a skill folder in the Skills library. args name, description, source, optional script, lang, and overwrite.
version: 1.0.0
---

Do not call this unless the main agent explicitly asked you to create or replace a skill. This is the only way to create a skill. It writes a skill folder outside the project, so the Skills page lists it and later runs, including scheduled tasks, can call it by name.

args:
- name. Lowercase letters, numbers, and single hyphens. This becomes the folder name.
- description. What the skill does and when to use it.
- source. The markdown instructions, or a complete SKILL.md when it already starts with ---.
- script. Optional. A PHP or Python program that reads a JSON object from stdin and prints the result. Omit it when the skill is only instructions.
- lang. php or py, when script is set. php is the default.
- overwrite. true replaces a skill the agent created. A system skill cannot be replaced.

If that name already exists, it is not changed until overwrite is true. A script is syntax-checked and is not kept when the check fails. Send the complete source again in one call. Do not write a skill into the project, and do not create a skills or data/skills folder there.

A subagent creates a skill only when the main agent explicitly asks for that new or replaced skill. Delegate this to the coder. The main agent calls it when the coder cannot finish. After it is saved, call test_skill. A skill registered in this run cannot be called by its own name until a later run.
