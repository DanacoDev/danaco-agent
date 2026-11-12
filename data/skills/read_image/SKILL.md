---
name: read_image
description: Looks at a png, jpg, gif, or webp. args path, optional question.
version: 1.0.0
---

args path, optional question. Looks at a png, jpg, gif, or webp. The GPU model can see the picture, including text in it. Use it for a screenshot, photo, or image upload. A scanned PDF is read_pdf. An svg is text, so use read_file. Cite the path alone on a line when the user should see the picture.

When a project is open, path is inside that project. In general chat, path is under uploads/. When full system control is on, a path that starts with / is allowed.
