---
name: create_pdf
description: Writes a PDF in the project. args path, text. The writer calls this. The main agent delegates it.
version: 1.0.0
---

args path, text. Writes a PDF in this project. Put the file in docs/ unless another folder was requested. path ends in .pdf and is not in wiki/. Headings and asterisks are typeset for you. An existing file is kept, and the new one is saved as the next free name, such as document2.pdf. Cite the path the tool wrote, alone on a line. Do not repeat the document.

The writer calls this. The main agent does not write the PDF in its own chat. Delegate it to the writer with the file name and the brief.
