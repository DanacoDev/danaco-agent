---
name: test_skill
description: Runs a skill in the open project and returns what it printed. args name, input.
version: 1.0.0
---

args name, input. name is the skill. input is the JSON object a runner reads on stdin. The skill really runs, so files it writes stay. It also runs a skill that is turned off. A skill that is only instructions returns that markdown. Call this after write_skill and read the result. Do not finish while the result is wrong. A skill registered in this run is not callable by its own name until a later run.

Delegate the test to the coder. The main agent calls it when the coder cannot finish. Open a project first.
