---
name: download_large
description: Resume a download over 100 MB in the background so the chat is not held up. args url, path, optional sha256.
version: 1.0.0
---

Use this for a file over 100 MB so the chat is not held up. A small page fetch does not need it.

args url, path, optional sha256. url is a public http or https address. path is a file inside the project, outside the wiki. sha256 is 64 hex characters when the source published one.

The download resumes in the background. Call this skill again with the same url and path to see progress. Do not start a second copy or a fresh file.
