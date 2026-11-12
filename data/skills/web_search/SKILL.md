---
name: web_search
description: Searches the web for one idea. args query. Only the researcher runs the search. The main agent delegates it.
version: 1.0.0
---

args query. One idea, as in a normal search box: two to four plain words. Every word must match, so extra words and synonyms make it miss. Quotes are only for one exact title of several words, plus at most one plain word. Do not quote a single word, do not stack quoted phrases, and do not use OR, AND, NOT, or parentheses to join alternatives. Search the other wording on its own. site:example.com is the only operator. Returns a title, link, and snippet. Then read a result with fetch_url.

Rewrite only when the message starts with "This query was not searched" or gives a shorter query, and call once more. If the search provider rejected the request, end the run. Do not call another tool. Do not fetch pages, rewrite the query, or log research. Reply with that failure so the main agent hears it. If the results page could not be read, stop. Do not call web_search again this turn.

Only the researcher runs this. The main agent delegates the search and does not say it cannot look something up.
