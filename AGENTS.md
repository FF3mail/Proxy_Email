# AGENTS.md — rules for AI coding agents
These rules apply to every task in this repository (Cursor and any other AI coding agent).
## 1. Source of truth
1. The single source of truth for the business logic is `docs/SPEC.md`. Read the relevant sections before starting any task.
2. `docs/DELTA-transit_anchor.md` is a historical document. Where it conflicts with `docs/SPEC.md`, `docs/SPEC.md` wins.
3. Sections of `docs/SPEC.md` marked "Не заполнено." are not specified yet. Do not assume behavior for them; stop and report.
4. Differences between the code and `docs/SPEC.md` are recorded as DEVIATION items (DEV-n) in section 13.2 of `docs/SPEC.md`. They are fixed only by an explicit task that names them.
## 2. Behavior changes
1. Do not change the business behavior of the system unless the task explicitly says so.
2. If a task touches behavior, update `docs/SPEC.md` in the same branch and say so in the report.
3. Do not add anything to `docs/SPEC.md` on your own initiative. When the task gives exact wording, insert it word for word.
4. `docs/SPEC.md` is written in Russian and does not use the words MUST, SHALL, SHOULD.
## 3. Facts and reports
1. Take facts about the code from the code itself and report them with the file path and line numbers.
2. If something is unclear or cannot be verified, say so; do not guess.
3. Reports are written in English.
## 4. Workflow
1. One ISSUE = one branch.
2. Start every task with synchronization: run `git status`; if there is anything uncommitted or unpushed, stop and report; then `git fetch`, check out the required branch, `git pull --ff-only`, and report the HEAD sha.
3. Commit and push only on the task branch, and only when the task says so.
4. Never merge, and never push to `master`. Merging is done by the human owner.
5. The human owner does not work in the terminal: every command is executed by the agent.
## 5. Secrets
1. Never print, commit or write to logs passwords, tokens, API keys or encryption keys.
2. If a secret is found in the repository, in a log or in a task text, do not use it; report it so that it can be revoked.
