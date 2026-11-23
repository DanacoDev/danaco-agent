---
name: create_pdf
description: Writes a PDF. args path, text. HTML headings are refused. Text is black unless the task names a color, a size, or an image. **bold** *italic*. The writer calls this.
version: 1.0.0
---

args path, text. Writes a PDF in this project. Put the file in docs/ unless another folder was requested. path ends in .pdf and is not in wiki/. An existing file is kept, and the new one is saved as the next free name, such as document2.pdf. Cite the path the tool wrote, alone on a line. Do not repeat the document.

The writer calls this. The main agent does not write the PDF in its own chat. Delegate it to the writer with the file name, the brief, and any design the user named. When the user named none, say that every line stays black.

The text is lines of writing. A line that starts with an HTML heading is refused, and a tag with a style is refused. <h1 style="color:red">Scarface (1983)</h1> is not a title. Write the marks below instead.

Default. Every line is black. Do not add a color mark, a size mark, or an image unless the task names that color, that size, or that picture. A heading is black. Do not pick a color of your own.

Title. A line that starts with # is the title. It is bold, 13 points, and black. ## is a section, 12 points. ### is a smaller heading, 11 points. Body text is 10 points and has no mark.

Emphasis. **word** is bold. *word* is italic. A heading is already bold, so it needs no asterisks.

List. A line that starts with - is a bullet.

Size. Use this only when the task names a size. Put {size:18} at the start of the line, before the #. That line becomes 18 points. Use a whole number from 8 to 36. {size:18}# Scarface (1983) is a large title. The mark covers that line only. It needs no closing mark, and it does not continue onto the next line. One phrase inside a line is {size:14}these words{/size}.

Color. Use this only when the task names a color. Put {color:navy} at the start of the line, before the #. That line becomes navy. {color:navy}# Scarface (1983) is a navy title. One phrase inside a line is {color:red}these words{/color}. A name is black, white, red, green, blue, purple, orange, yellow, gray, grey, teal, navy, brown, pink, or gold. A hex code such as #1a1a1a works too. The mark covers that line only. It needs no closing mark, and it does not continue onto the next line. With no color mark, the line stays black.

Image. Use this only when the task names a picture or an illustration. Put the mark on its own line. {image:assets/chart.png} places that file. {image:assets/chart.png width:400} sets the width in points, from 40 to 495. A markdown line ![A chart](assets/chart.png) does the same, and the words in the brackets are the caption under it, in black. The file is png, jpg, gif, or webp, already in the project. An illustration that does not exist yet is drawn with make_graphic first, then named here. A missing file is refused.

A plain title, then the body, when the task names no design:

# Scarface (1983)

The paragraphs follow here, in black, at 10 points.

These are the only changes: size, color, bold, italic, a heading mark, a bullet, and an image. The typeface is Helvetica. Lines stay left aligned. Underline, a background, a margin, centering, and another typeface have no mark. Leave them out.
