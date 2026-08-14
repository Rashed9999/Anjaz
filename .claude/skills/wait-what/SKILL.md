---
name: wait-what
description: Stop. That last message did not land — re-pitch it. Use when the user answers with confusion rather than a decision — «لم أفهم» · «ما قصدك» · «وضّح».
disable-model-invocation: true
---

Wait — I don't understand where you've got to here. Re-pitch that: give me a little bit of context, use short plain sentences, and stick to the project's own vocabulary.

**Adapted for أميال باي.** Upstream says "talk in ASD-STE100 Simplified Technical English" and points at `CONTEXT.md`. Neither survives here: the owner reads Arabic, and this repo has no `CONTEXT.md`. So — re-pitch **in Arabic**, and draw the vocabulary from `01_backend/CLAUDE.md` and `.claude/skills/README.md`.

Re-state the thing itself, not a summary of the failed message. Name what it changes on the owner's screen before naming what it changes in the code.
