---
name: create_pdf
description: Writes a PDF. args path, text. HTML headings are refused. A purple title is {size:18}{color:purple}# Title. **bold** *italic*. The writer calls this.
version: 1.0.0
---

args path, text. Writes a PDF in this project. Put the file in docs/ unless another folder was requested. path ends in .pdf and is not in wiki/. An existing file is kept, and the new one is saved as the next free name, such as document2.pdf. Cite the path the tool wrote, alone on a line. Do not repeat the document.

The writer calls this. The main agent does not write the PDF in its own chat. Delegate it to the writer with the file name and the brief.

The text is lines of writing. A line that starts with an HTML heading is refused, and a tag with a style is refused. <h1 style="color:purple">Scarface (1983)</h1> is not a title. Write the marks below instead.

Title. A line that starts with # is the title. It is bold, 13 points. ## is a section, 12 points. ### is a smaller heading, 11 points. Body text is 10 points and has no mark.

Emphasis. **word** is bold. *word* is italic. A heading is already bold, so it needs no asterisks.

List. A line that starts with - is a bullet.

Size. Put {size:18} at the start of the line, before the #. That line becomes 18 points. Use a whole number from 8 to 36. 18 is a large title. 13 is a normal title. 10 is body text. {size:18}# Scarface (1983) is a large title. The mark covers that line only. It needs no closing mark, and it does not continue onto the next line. One phrase inside a line is {size:14}these words{/size}.

Color. Body text is black. Put {color:purple} at the start of the line, before the #. That line becomes purple. {size:18}{color:purple}# Scarface (1983) is a large purple title. One phrase inside a line is {color:purple}these words{/color}. A name is black, white, red, green, blue, purple, orange, yellow, gray, grey, teal, navy, brown, pink, or gold. A hex code such as #6a0dad works too. The mark covers that line only. It needs no closing mark, and it does not continue onto the next line.

A large purple title, then the body:

{size:18}{color:purple}# Scarface (1983)

The paragraphs follow here, in black, at 10 points.

These are the only changes: size, color, bold, italic, a heading mark, and a bullet. The typeface is Helvetica. Lines stay left aligned. Underline, a background, a margin, centering, and another typeface have no mark. Leave them out.
