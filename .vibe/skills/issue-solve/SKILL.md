---
name: issue-solve
description: Use when the user asks to pick, fix, or solve a given issue in the Barrejadis/audience-keypad repository. Covers reading the issue and AGENTS.md rules, writing the fix, and preparing the branch and pull request.
---

# Solve an issue in Barrejadis/audience-keypad

**Target repo:** Barrejadis/audience-keypad

## Mandatory delivery rules
- Never commit to `main`. Any code change MUST go through a pull request on a dedicated branch.
- Always open a PR referencing the issue (`Fixes #<number>`), never a direct commit.
- No self-merge: the PR stays open until an independent reviewer merges or closes it.

## Workflow
1. Get the issue: read it via `gh issue view` (number, title, or URL). If none is named, list open issues and propose the most actionable one before writing code.
2. Read the repo rules: fetch `AGENTS.md` from the repository root and follow its instructions (structure, coding style, test and commit conventions). Follow area-specific `AGENTS.md` files for files you touch.
3. Understand the codebase: read only the files relevant to the issue; locate the root cause (bugs) or integration points (enhancements).
4. Implement the fix: follow the issue description and AGENTS.md constraints; keep the change minimal; add/update tests when repo conventions require it.
5. Verify: run type checks, linters, and tests if the toolchain is available; otherwise state what was checked by inspection.
6. Deliver: push a dedicated branch (never `main`), open a PR with `Fixes #<number>`, and report what changed and why. Do not merge or close the PR yourself.

## Notes
- Write code, comments, commits, and PR descriptions in English even if the user writes in French.