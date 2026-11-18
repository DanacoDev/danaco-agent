---
name: grammar_check
description: use this to grammar check languages sv-se, en-us, en-gb, de-de, fr, es, da, nb. args text, language, consent. One line, after the user agrees. External server.
version: 1.0.0
---

args text, language, consent.

You find the mistakes. This only checks one line, or a few words, that you already marked. Read the text yourself. Mark the words and lines that look misspelled or ungrammatical. Leave the rest alone. Call this once for each marked line. Do not put the article in text. Do not join several lines into one call.

Each call posts that line to https://api.languagetool.org, an external server. The line can contain personal information, sensitive information, or a password. Before the first call, tell the user that, show every line you would send, and wait for a yes. Asking for a grammar check is not that yes. If they say no, stop and report only what you noticed locally. If a line contains a password, token, key, or similar secret, skip it and say so. Do not send that line.

consent is yes only after they agreed. text is that one line. language is sv for sv-se, en-US for en-us, en-GB for en-gb, de-DE for de-de, and the same pattern for de-at, de-ch, fr, es, da, nb, nl, pt-pt, pt-br, it, pl, ru, and uk. Use auto only when the text is mixed.

Report each finding with the line, the message, and the suggestion. Say how many lines were sent. If a call finds nothing, say so for that line.
