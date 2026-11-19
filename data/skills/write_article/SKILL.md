---
name: write_article
description: Workflow for a poem, book, web content, or article. args path, instructions, optional sources or source_paths, language, voice or voice_path.
version: 1.0.0
---

This is the workflow for a poem, a book, web content, or an article. Calling it does not save the file and does not write the piece. You write the piece and save the working file. Do the saved passes in order. Save the working file at the end of each pass before you start the next. Do not skip a pass because the draft looks fine. Do not read the working file until a write has saved it.

args path, instructions, optional sources or source_paths, language, voice or voice_path. If path is set, that is the working file. Create it in step 2 if it does not exist. Markdown belongs in wiki/. The working file is not wiki/PROJECT.md or wiki/NOTES.md. If no path was passed, choose one markdown file in wiki/ and use that same path for every step. Read source_paths yourself. Read voice_path yourself when a voice sample is a file.

Language: use the language the user asked for. The whole piece stays in that language. Names and quotes from the source may stay as they are. Do not leave sentences in another language. A single word counts too. If a word is difficult, find it in the requested language. Do not replace a difficult word with an English one. One English word in a line is still wrong. The first draft will be wrong in places. On every pass, fix grammar, spelling, agreement, and word forms a reader of that language would notice, and replace any English word that slipped in. Write the way that language writes, including its compounds, articles, and letters.

The task has these steps. Do them in order. Cross off a step when it is saved. Do not start the piece over, and do not stop while a step is still open.
1. Write the piece and save it as markdown in wiki/. The passes below are this step.
2. Create the PDF in docs/ when the task asks for a PDF. Skip this step when it does not.
3. Read that PDF and check it. Skip this step when there is no PDF.
4. Report the file path with tasklist action result. Pass id, result set to that path, and done true. That crosses off the remaining steps. Do not stop before this call.

1. Sources and instructions. Read the instructions and every source. Note the facts you may use, and what you must not invent. If the task named files that are not in the arguments, read those files before you draft. Do not write the piece in this step.
2. Draft. Write the full piece into the working file and save it. Keep the form the task asked for. A poem stays a poem. A book may use chapters. Web content and an article may use sections. Say who does what when the piece has actors. Stop. Do not polish it in the same breath.
3. Repetition and contradiction. Read the saved file from the start. Delete a section that repeats an earlier one. A refrain or a repeated line that belongs to the form stays. When two sentences disagree, keep the one the sources support and delete the other. Save the file again.
4. Humanizer. Run humanizer with path set to the working file. If a voice sample was given, pass voice or voice_path too. Rewrite from that checklist, in the same language, and save the file. Do not paste the checklist into the piece.
5. Spelling. Read the saved file line by line. Fix misspellings, wrong word forms, and agreement. Replace a leftover English word with the word in the requested language. Do not change the meaning and do not open a new style pass. Save the file. When the task does not ask for a PDF, report that path with tasklist action result and done true. The file is the result. Do not show an earlier draft. Do not stop before that call.
6. PDF. Do this step only when the task asks for a PDF. Call create_pdf once with the finished text. Put the file in docs/ unless another folder was named. Call read_pdf on the path the tool wrote. When that read shows the text, delete the working markdown with filesystem action delete. Then report that path with tasklist action result, id, result set to the path, and done true. Do not stop before that call. Do not write the markdown again and do not call create_pdf again. When the read does not show the text, keep the markdown and say so. Do not call create_pdf again.

When the task does not ask for a PDF, the saved wiki file remains the result.
