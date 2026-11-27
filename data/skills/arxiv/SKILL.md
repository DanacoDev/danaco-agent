---
name: arxiv
description: Searches arXiv and reads one paper. args query, author, category, id, action, max, sort, start, offset.
version: 1.0.0
---

Use this for an arXiv paper. A search, the text of one paper, or a BibTeX entry comes from here. Do not open arxiv.org or export.arxiv.org with fetch_url, browse_url, or run_command.

args query, author, category, id. action is search, read, or bibtex. search is the default. max is 5 unless you set it, and it is at most 8. sort is relevance, date, or updated. start is the result offset. offset continues a paper. id is 2402.03300, 2402.03300v2, or an abs or pdf address.

search returns the title, the versioned id, the dates, the authors, the categories, a short abstract, and the abs and pdf links. A withdrawn paper is labeled withdrawn. One search per call.

read takes one id. The first call includes the abstract and the start of the article. Call read again with the offset it gives you. One paper per call.

bibtex takes one id and returns one entry. Keep the version it prints.
