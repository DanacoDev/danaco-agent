---
name: write_article
description: Article workflow in five saved passes. args path, instructions, optional sources or source_paths, language, voice or voice_path. Follow every step before you present the file.
version: 1.0.0
---

Do these five steps in order. Save the wiki file at the end of each step before you start the next. Do not present the article until step 5 is saved. Do not skip a step because the draft looks fine.

args path, instructions, optional sources or source_paths, language, voice or voice_path. If path is set, that is the working file. Create it in step 2 if it does not exist. Markdown belongs in wiki/. If no path was passed, choose one markdown file in wiki/ and use that same path for every step. Read source_paths yourself. Read voice_path yourself when a voice sample is a file.

Language: use the language the user asked for. The whole article stays in that language. Names and quotes from the source may stay as they are. Do not leave sentences in another language. When the language is Swedish, the first draft will be wrong in places. On every pass, fix grammar a Swedish reader would notice. Write compounds as one word unless the source splits them. Keep en or ett with its noun. Use å, ä, and ö. Do not swap in an English word to dodge a Swedish one.

1. Sources and instructions. Read the instructions and every source. Note the facts you may use, and what you must not invent. If the task named files that are not in the arguments, read those files before you draft. Do not write the article in this step.
2. Draft. Write the full article into the working file and save it. One idea per section. Say who does what. Stop. Do not polish it in the same breath.
3. Repetition and contradiction. Read the saved file from the start. Delete a section that repeats an earlier one. When two sentences disagree, keep the one the sources support and delete the other. Save the file again.
4. Humanizer. Run humanizer with path set to the working file. If a voice sample was given, pass voice or voice_path too. Rewrite from that checklist, in the same language, and save the file. Do not paste the checklist into the article.
5. Spelling. Read the saved article line by line. Fix misspellings, wrong word forms, and agreement. Do not change the meaning and do not open a new style pass. Save the file. Then present that path. The file is the result. Do not show an earlier draft.
