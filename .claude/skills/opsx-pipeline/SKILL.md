---
name: "OPSX: Pipeline"
description: Process multiple OpenSpec changes in parallel using subagents — full lifecycle from proposal to merged PR
category: Workflow
tags: [workflow, parallel, pipeline, experimental]
---

Process one or more OpenSpec change proposals through the full lifecycle using parallel subagents. Each change gets its own agent, worktree, branch, and PR.

**Input**: Optionally specify change names, a repo name, or `all` to process all open proposals.

Examples:
- `/opsx:pipeline all` — process all open proposals across all repos
- `/opsx:pipeline procest` — process all open proposals in Procest
- `/opsx:pipeline sla-tracking routing` — process specific changes by name

**Overview**

This command automates the full OpenSpec lifecycle per change:

```
proposal → ff → plan-to-issues → apply → quality → verify (browser+API) → archive → push → PR
```

Each change runs as an independent subagent in an isolated git worktree on its own feature branch. The main agent orchestrates, monitors, and reports.

---

## Steps

### 1. Discover changes to process

Scan for open proposals (directories in `openspec/changes/` that contain a `proposal.md` but are NOT in `archive/`).

```bash
# For each app directory, find open proposals
for app in procest pipelinq docudesk openregister opencatalogi mydash nldesign larpingapp openconnector softwarecatalog zaakafhandelapp; do
  if [ -d "$app/openspec/changes" ]; then
    for change in $app/openspec/changes/*/proposal.md; do
      echo "$app:$(basename $(dirname $change))"
    done
  fi
done
```

Also check `.github/openspec/changes/` for org-wide proposals.

**Filter** based on input:
- `all` → process everything found
- `<repo-name>` → only changes in that repo
- `<change-name> [change-name...]` → only those specific changes (search across all repos)

**If no input provided**, list all discovered changes and use **AskUserQuestion** to let the user select which to process.

### 2. Build the execution plan

For each change, determine:
- **App directory**: e.g., `procest/`
- **Change name**: e.g., `brp-kvk-register-sets`
- **GitHub repo**: e.g., `ConductionNL/procest` (from git remote)
- **Existing issue**: Check if an `openspec`-labeled issue already exists for this change (search GitHub issues by title)

Display the plan:

```
## Pipeline Plan

| # | App | Change | GitHub Repo | Issue | Browser |
|---|-----|--------|-------------|-------|---------|
| 1 | procest | brp-kvk-register-sets | ConductionNL/procest | #103 | browser-2 |
| 2 | pipelinq | sla-tracking | ConductionNL/pipelinq | #79 | browser-3 |
| ... | ... | ... | ... | ... | ... |

Total: N changes across M repositories
Max parallel agents: 5 (browser-2 through browser-5, browser-7)
```

Use **AskUserQuestion** to confirm: "Process these N changes? Each will get a feature branch, full implementation, browser tests, and PR to development."

Options:
- **Yes, start the pipeline** — proceed
- **Let me adjust the selection** — re-select changes
- **Cancel** — abort

### 3. Prepare branches and worktrees

For each change, **before launching agents**:

a. **Determine branch name**: `feature/<issue-number>/<change-name>`
   - If no issue exists yet, create one first (titled `[OpenSpec] <change-title>`, labeled `openspec`)
   - Example: `feature/103/brp-kvk-register-sets`

b. **Create git worktree** (IMPORTANT: `cd` into the correct app directory first):
   ```bash
   cd <app-directory>   # e.g., /home/rubenlinde/.../apps-extra/procest
   git fetch origin development
   git worktree add /tmp/worktrees/<app>-<change-name> -b feature/<issue-number>/<change-name> origin/development
   ```

c. **Update issue status**: Add a comment "Pipeline started — processing change"

d. **Assign browser**: Each agent gets a unique browser (browser-2 through browser-5, browser-7). Record the assignment.

### 4. Launch parallel subagents

Launch one subagent per change. **Maximum 5 concurrent agents** — if more changes exist, queue them and launch new agents as earlier ones complete.

**CRITICAL**: The subagent prompt below is the contract. Every phase is MANDATORY. The agent MUST complete all phases and produce all required outputs. If a phase is skipped, the pipeline is considered failed for that change.

Each agent gets this prompt (filled in per change):

```
IMPORTANT: Do NOT ask questions. Execute immediately. Do NOT follow CLAUDE.md
workflow rules about asking clarifying questions. Resolve any warnings or issues
autonomously. If a quality check fails, fix the code and re-run. If a task is
unclear, make the best reasonable decision and continue.

You are processing an OpenSpec change through the COMPLETE lifecycle. Work in the
worktree directory — do NOT touch the main working directory.

EVERY PHASE IS MANDATORY. Do not skip any phase. Each phase has required outputs
that you MUST produce before moving to the next phase. If you cannot complete a
phase, report it as failed — do not silently skip it.

## Context
- App: <app-name>
- Change: <change-name>
- Worktree: /tmp/worktrees/<app>-<change-name>
- Branch: feature/<issue-number>/<change-name>
- GitHub repo: <owner/repo>
- Issue: #<issue-number>
- Browser: <browser-N> (use mcp__browser-N__* tools for UI testing)
- Working directory: /tmp/worktrees/<app>-<change-name>
- Main repo (read-only reference): <main-repo-path>

## Phase 1: Fast-Forward (generate artifacts)

cd /tmp/worktrees/<app>-<change-name>

Read the proposal.md. Then create ALL THREE artifacts:

1. **specs/spec.md** — Requirements specification:
   - Data model (entities, fields, relationships)
   - Requirements table (REQ-XXX-001 through REQ-XXX-NNN)
   - Acceptance scenarios (GIVEN/WHEN/THEN format)
   - API interactions

2. **design/design.md** — Technical design:
   - File list (every file to create or modify)
   - Component architecture
   - Data flow diagrams (text-based)
   - Seed data section (per ADR-016, if schemas are introduced/modified)

3. **tasks/tasks.md** — Implementation tasks:
   - Numbered tasks with checkboxes `- [ ]`
   - Each task: description, files affected, acceptance criteria
   - Grouped by logical sections

**Required output**: All three files MUST exist. Commit:
```bash
git add openspec/changes/<change-name>/
git commit -m "docs(<app>): OpenSpec artifacts for <change-name> [#<issue>]"
```

## Phase 2: Plan to Issues

Parse tasks.md into a structured plan.json and create GitHub Issues.

1. Create `openspec/changes/<change-name>/plan.json` with structure:
   ```json
   {
     "change": "<change-name>",
     "project": "<app-name>",
     "repo": "<owner/repo>",
     "created": "<ISO-date>",
     "tracking_issue": null,
     "tasks": [
       {
         "id": 1,
         "title": "<task title>",
         "description": "<task description>",
         "github_issue": null,
         "status": "pending",
         "acceptance_criteria": ["<criteria>"],
         "files_likely_affected": ["<files>"],
         "labels": ["openspec", "<change-name>"]
       }
     ]
   }
   ```

2. Ensure required labels exist:
   ```bash
   gh label list --repo <owner/repo> | grep -q "openspec" || gh label create "openspec" --repo <owner/repo> --color "0075ca"
   gh label list --repo <owner/repo> | grep -q "tracking" || gh label create "tracking" --repo <owner/repo> --color "e4e669"
   gh label list --repo <owner/repo> | grep -q "<change-name>" || gh label create "<change-name>" --repo <owner/repo> --color "d93f0b"
   ```

3. Create tracking issue with section headers and task checklist:
   ```bash
   gh issue create --repo <owner/repo> \
     --title "[OpenSpec] <change-name>" \
     --body "<summary>\n\n## Tasks\n- [ ] Task 1\n- [ ] Task 2\n..." \
     --label "openspec,tracking"
   ```

4. Create one issue per task (prefix title with task number, include `Part of #<tracking>`):
   ```bash
   gh issue create --repo <owner/repo> \
     --title "[<change-name>] <task-number> <task title>" \
     --body "<description>\n\n## Acceptance Criteria\n- [ ] <criterion>\n\n---\n*Part of #<tracking>*" \
     --label "openspec,<change-name>"
   ```

5. Update plan.json with all issue numbers (tracking_issue + per-task github_issue)

6. Update tracking issue body to reference task issue numbers

**Required output**: plan.json with all issue numbers populated. Commit:
```bash
git add openspec/changes/<change-name>/plan.json
git commit -m "docs(<app>): plan.json with GitHub issues for <change-name> [#<issue>]"
```

## Phase 3: Implement (Apply)

Read ALL context files (proposal, specs, design, tasks, plan.json).

For each task in order:
1. Read the task's acceptance criteria and files_likely_affected
2. Read existing reference code from the main repo for patterns
3. Implement the code changes
4. **Write PHPUnit tests** for every new PHP service/controller:
   - File: `tests/Unit/<Namespace>/<ClassName>Test.php`
   - Minimum 3 test methods (happy path, error handling, edge case)
   - If vendor/ is not available in worktree, create the test files anyway (they'll run in CI)
5. Mark task as `[x]` in tasks.md
6. Update plan.json: set task status to "done"
7. Close the task's GitHub issue:
   ```bash
   gh issue close <task-issue> --repo <owner/repo> --comment "Implemented"
   ```
8. Update tracking issue checkbox:
   ```bash
   # Fetch body, replace "- [ ] #<task-issue>" with "- [x] #<task-issue>", update
   ```
9. **Commit after EACH task** (not batched):
   ```bash
   git add <specific-files>
   git commit -m "feat(<app>): <task-title> [#<task-issue>]"
   ```

**Required output**: All tasks marked [x], all task issues closed, one commit per task.

## Phase 4: Quality Checks

Run the full quality suite. This is MANDATORY — do not skip even if vendor/ is missing.

**PHP quality** (run from worktree directory):
```bash
cd /tmp/worktrees/<app>-<change-name>
# If composer.json exists with check:strict script:
composer install --no-interaction 2>&1 || true
composer check:strict 2>&1
# If check:strict not available, run individually:
vendor/bin/phpcs lib/ tests/ --standard=PSR12 2>&1
vendor/bin/phpmd lib/ text phpmd.xml 2>&1
vendor/bin/psalm --no-cache 2>&1
vendor/bin/phpunit 2>&1
```

**Frontend quality** (run from worktree directory):
```bash
npm ci 2>&1 || true
npm run lint 2>&1
npm run stylelint 2>&1
```

**Handle failures:**
- For auto-fixable: `composer phpcs:fix`, `npm run lint -- --fix`
- For remaining: fix manually in code
- Re-run quality checks to confirm
- Maximum 3 fix cycles

**If vendor/node_modules install fails**: Still check PHP syntax (`php -l`) on all new files, and verify code follows patterns from reference files. Note in report that full checks will run in CI.

**Required output**: Quality results table. Commit any fixes:
```bash
git commit -m "fix(<app>): quality check fixes for <change-name> [#<issue>]"
```

## Phase 5: Feature Documentation

Create/update feature documentation. This is MANDATORY.

1. **Check if `docs/features/README.md` exists**. If not, create it with an overview table.

2. **Create feature doc** at `docs/features/<change-name>.md`:
   - Feature title and one-line summary
   - Standards references (GEMMA, TEC, Forum Standaardisatie if applicable)
   - Overview section
   - Key capabilities (from spec requirements)

3. **Update `docs/features/README.md`**: Add a row for the new feature in the features table.

**Required output**: Feature doc file + updated README. Commit:
```bash
git add docs/features/
git commit -m "docs(<app>): feature documentation for <change-name> [#<issue>]"
```

## Phase 6: Verify (Browser + API Testing)

This phase is MANDATORY. Use browser <browser-N> for UI testing.

### 6a. API Testing (if the change adds/modifies API endpoints)

For each endpoint affected by this change:
```bash
# Test CRUD operations with curl
curl -s -u admin:admin -X POST -H "Content-Type: application/json" \
  -d '{"name":"Verify Test"}' http://localhost:8080/index.php/apps/<app>/api/<resource>
curl -s -u admin:admin http://localhost:8080/index.php/apps/<app>/api/<resource>
```

Check: response codes, JSON structure, error handling.

### 6b. Browser Testing (ALWAYS required)

1. **Navigate and authenticate**:
   ```
   mcp__browser-N__browser_navigate → http://localhost:8080
   mcp__browser-N__browser_fill_form → username: admin, password: admin (if login page)
   mcp__browser-N__browser_navigate → http://localhost:8080/index.php/apps/<app>
   ```

2. **Test spec scenarios**: For each GIVEN/WHEN/THEN in the specs:
   - GIVEN: Navigate to correct page, verify precondition
   - WHEN: Perform the action (click, type, fill form)
   - THEN: Take snapshot to verify outcome

3. **Take screenshots** as evidence:
   ```
   mcp__browser-N__browser_take_screenshot
   ```
   Take at minimum:
   - The feature's main view/page
   - A successful action (create/edit/search result)
   - An error/empty state (if applicable)

4. **Check for errors**:
   ```
   mcp__browser-N__browser_console_messages → level: error
   mcp__browser-N__browser_network_requests → check for 4xx/5xx
   ```

### 6c. Verification Report

Create a verification summary with:
- Task completion: N/N
- Spec coverage: which requirements have evidence
- API test results (if applicable)
- Browser test results with screenshot references
- Console errors found (if any)
- Issues found: CRITICAL / WARNING / SUGGESTION

**Required output**: Verification findings included in final report. Fix any CRITICAL issues found, re-verify after fixes.

## Phase 7: Archive

1. Move change to archive:
   ```bash
   mkdir -p openspec/changes/archive
   mv openspec/changes/<change-name> openspec/changes/archive/$(date +%Y-%m-%d)-<change-name>
   ```

2. Close tracking issue:
   ```bash
   gh issue close <tracking-issue> --repo <owner/repo> --comment "Change archived"
   ```

3. Commit:
   ```bash
   git add openspec/
   git commit -m "chore(<app>): archive <change-name> [#<issue>]"
   ```

**Required output**: Change directory moved to archive, tracking issue closed.

## Phase 8: Push and Report

```bash
cd /tmp/worktrees/<app>-<change-name>
git push origin feature/<issue-number>/<change-name>
```

**MANDATORY REPORT FORMAT** — Your final message MUST include ALL of the following sections. Missing sections = failed pipeline.

```
## Pipeline Report: <change-name>

### Phases Completed
- [ ] Phase 1: Fast-Forward (specs, design, tasks)
- [ ] Phase 2: Plan to Issues (plan.json, tracking issue, task issues)
- [ ] Phase 3: Implement (code changes, tests, per-task commits)
- [ ] Phase 4: Quality Checks (PHPCS, PHPMD, Psalm, ESLint, PHPUnit)
- [ ] Phase 5: Feature Documentation (docs/features/)
- [ ] Phase 6: Verify (browser tests, screenshots, API tests)
- [ ] Phase 7: Archive (moved to archive, issues closed)
- [ ] Phase 8: Push (branch pushed)

### Tasks
- Total: N
- Completed: N
- GitHub issues created: N (tracking: #X, tasks: #Y, #Z, ...)

### Files Created
- <list of new files>

### Files Modified
- <list of modified files>

### Quality Results
| Check | Status |
|-------|--------|
| PHP syntax | Pass/Fail |
| PHPCS | Pass/Fail/Skipped (reason) |
| PHPMD | Pass/Fail/Skipped (reason) |
| Psalm | Pass/Fail/Skipped (reason) |
| PHPUnit | Pass/Fail/Skipped (reason) — N tests |
| ESLint | Pass/Fail/Skipped (reason) |

### Verification Results
| Test | Status | Evidence |
|------|--------|----------|
| <scenario-1> | Pass/Fail | Screenshot/curl output |
| <scenario-2> | Pass/Fail | Screenshot/curl output |
| Console errors | None/Found | <details> |
| Network errors | None/Found | <details> |

### Commits
1. <hash> — <message>
2. <hash> — <message>
...

### Issues
- Tracking: #<N> (closed)
- Task issues: #<N1> (closed), #<N2> (closed), ...

### Branch
Pushed: feature/<issue>/<change-name>
```

Do NOT create the PR — the main agent handles that after reviewing the results.
Do NOT add Co-Authored-By trailers to commit messages.
```

**Agent configuration:**
- Use `run_in_background: true` for all agents
- Pre-create worktrees in Step 3 (do NOT use `isolation: "worktree"` — we need the worktree in a known location)
- Assign browser numbers: agent 1 → browser-2, agent 2 → browser-3, ..., agent 5 → browser-7
- Pass the main repo path as read-only reference for pattern matching

### 5. Monitor progress

While agents are running:
- Track which agents have completed
- As each agent completes, **review the mandatory report sections** — flag any phases marked as skipped/failed
- If an agent fails, log the error and continue with others
- Launch queued agents as slots become available

Display progress updates:

```
## Pipeline Progress

| # | App | Change | Status | Tasks | Issues | Quality | Browser | Docs |
|---|-----|--------|--------|-------|--------|---------|---------|------|
| 1 | procest | brp | Done | 7/7 | 8 created | Pass | Pass | Done |
| 2 | pipelinq | sla | Running | 3/5 | 6 created | — | — | — |
| 3 | pipelinq | routing | Queued | — | — | — | — | — |
```

### 6. Create Pull Requests

For each successfully completed change, create a PR from the feature branch to `development`:

```bash
gh pr create \
  --repo <owner/repo> \
  --base development \
  --head feature/<issue-number>/<change-name> \
  --title "feat(<app>): <Change Title>" \
  --body "$(cat <<'EOF'
## Summary
<1-3 bullet points from proposal.md>

## OpenSpec Change
- **Change:** <change-name>
- **Tracking issue:** #<tracking-issue>
- **Tasks:** N/N complete
- **Task issues:** #<list>

## Quality Checks
| Check | Status |
|-------|--------|
| PHPCS | result |
| PHPMD | result |
| Psalm | result |
| PHPUnit | result (N tests) |
| ESLint | result |

## Verification
| Test | Result |
|------|--------|
| Browser tests | Pass/Fail (N scenarios) |
| API tests | Pass/Fail (N endpoints) |
| Console errors | None/Found |

## Feature Documentation
- docs/features/<change-name>.md — created/updated

Closes #<original-issue-number>

Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Update the original issue with a link to the PR.

### 7. Clean up worktrees

After all PRs are created:
```bash
# For each completed change
cd <app-directory>
git worktree remove /tmp/worktrees/<app>-<change-name>
```

### 8. Final report

Display the complete pipeline results:

```
## Pipeline Complete

### Results
| # | App | Change | Branch | PR | Tasks | Issues | Quality | Browser | Docs |
|---|-----|--------|--------|-----|-------|--------|---------|---------|------|
| 1 | procest | brp | feat/103/brp | #105 | 7/7 | 8 | Pass | Pass | Done |
| 2 | pipelinq | sla | feat/79/sla | #82 | 5/5 | 6 | Pass | Pass | Done |

### Summary
- Changes processed: N
- Successful: N
- Failed: N (with reasons)
- PRs created: N
- Total tasks implemented: N
- Total tests written: N
- Total GitHub issues created: N
- Feature docs created: N
- Browser test scenarios: N

### Phase Completion Audit
For each change, verify all 8 phases were completed:
| Change | FF | Issues | Impl | Quality | Docs | Verify | Archive | Push |
|--------|-----|--------|------|---------|------|--------|---------|------|
| brp    | OK  | OK     | OK   | OK      | OK   | OK     | OK      | OK   |

### Failed Changes (if any)
- <change-name>: <reason for failure>
  Failed at phase: <phase-name>
  Worktree preserved at: /tmp/worktrees/<app>-<change-name>
  To resume: fix the issue and run `/opsx:pipeline <change-name>`
```

---

## Guardrails

- **Worktree isolation**: Each change works in `/tmp/worktrees/<app>-<change-name>` — NEVER modify the main working directory from a subagent
- **Branch naming**: Always `feature/<issue-number>/<change-name>` based off `origin/development`
- **No destructive git ops**: No force push, no reset, no clean, no rebase on shared branches
- **Max parallelism**: 5 concurrent agents (limited by browser pool and system resources)
- **Autonomous operation**: Subagents resolve issues themselves. Only escalate to user if fundamentally blocked (e.g., missing dependency, ambiguous requirement with no reasonable default)
- **Quality gates**: Every change must pass quality checks before PR. If checks fail after 3 fix cycles, mark as failed and preserve worktree for manual intervention
- **Issue hygiene**: Every change gets plan.json, tracking issue, and per-task issues. Every task closes its issue. Every PR references all issues.
- **No Co-Authored-By**: Commit messages must NOT include Co-Authored-By trailers
- **Commit per task**: Each implemented task gets its own commit with a descriptive message referencing the task issue
- **PR to development**: Always target `development` branch, never `main` or `beta`
- **All phases mandatory**: The subagent MUST complete all 8 phases. Skipping a phase = pipeline failure for that change.
- **Browser testing mandatory**: Every change gets browser-verified. Screenshots are evidence. No exceptions.
- **Feature docs mandatory**: Every change gets a feature doc in docs/features/. No exceptions.
- **Report format mandatory**: The subagent's final report MUST follow the exact format specified. Missing sections = failed pipeline.

## Error Handling

- **Agent timeout**: If an agent runs for more than 30 minutes with no progress, consider it stuck. Preserve worktree and report.
- **Quality check failures**: Agent fixes up to 3 cycles. After that, mark as failed with details.
- **Git conflicts**: If worktree creation fails due to branch conflicts, create from a fresh development checkout.
- **Missing openspec CLI**: If `openspec` command is not available, fall back to manual artifact creation (read proposal, create specs/design/tasks manually following the templates).
- **Org-wide changes (.github)**: These don't follow the same app structure. Skip ff/apply for these — they are documentation/compliance changes that need manual implementation per app.
- **Missing vendor/node_modules**: Install dependencies in the worktree. If install fails, run what you can (php -l syntax checks, pattern matching against reference code) and note that full checks will run in CI.
- **Browser not reachable**: If browser tools fail (e.g., Nextcloud not running), skip browser testing but mark Phase 6 as PARTIAL with reason. The pipeline is still valid but the PR description must note "Browser tests: Skipped (environment not available)".
