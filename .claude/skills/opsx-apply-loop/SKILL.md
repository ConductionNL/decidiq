---
name: opsx-apply-loop
description: Iteratively run apply→verify in a loop until verify passes, then auto-archive — runs per-app in Docker context
metadata:
  category: Workflow
  tags: [workflow, automated, loop, docker, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — the apply→verify loop needs strong reasoning to implement tasks and evaluate verification results. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

**Check container authentication** — the container needs credentials to call the Claude API. The Claude CLI authenticates via a credentials file, not environment variables alone. Two methods are supported, checked in order of preference:

1. **Credentials file** (preferred) — `~/.claude/.credentials.json`, created automatically by `claude auth login`. Uses your existing Claude subscription (no extra cost).
2. **`ANTHROPIC_API_KEY`** (fallback) — uses prepaid API credits (costs money). Get one at console.anthropic.com.

**Important**: The `apply-loop` container image runs as user `claude` with `HOME=/home/claude`. The credentials file must be mounted to `/home/claude/.claude/.credentials.json` inside the container. Passing `CLAUDE_CODE_AUTH_TOKEN` as an env var alone is NOT sufficient — the CLI requires the actual credentials file.

```bash
CREDS_FILE="$HOME/.claude/.credentials.json"
if [ -f "$CREDS_FILE" ]; then
  echo "✅ Credentials file found at $CREDS_FILE (will mount into container)"
elif [ -n "${ANTHROPIC_API_KEY}" ]; then
  echo "⚠️ ANTHROPIC_API_KEY is set (using paid API credits — consider running 'claude auth login' instead)"
else
  echo "❌ No authentication configured"
fi
```

Store the result: `{AUTH_METHOD}` = `credentials_file` or `api_key`.

**If neither is available — stop immediately:**
> "⛔ The apply-loop container needs authentication. No credentials file (`~/.claude/.credentials.json`) or `ANTHROPIC_API_KEY` found.
>
> **Recommended: use your existing subscription (free)**
> 1. Run `claude auth login` in your terminal
> 2. This creates `~/.claude/.credentials.json` automatically
> 3. Re-run this command — the file will be mounted read-only into the container
>
> **Alternative: use API credits (costs money)**
> 1. Go to console.anthropic.com → API Keys → Create Key
> 2. Add credits to your account (Billing → Add credits)
> 3. Add the key to your shell profile:
>    ```bash
>    export ANTHROPIC_API_KEY='sk-ant-...'
>    ```
>
> There is no alternative — the loop always runs inside an isolated Docker container."

**Do not suggest running apply→verify on the host as an alternative. There is no fallback.**

---

**AUTONOMOUS MODE — This skill is a fully automated orchestrator.** The standard CLAUDE.md workflow (ask clarifying questions → present plan → wait for approval) does NOT apply here. Do NOT pause between steps to ask for confirmation or approval unless a step explicitly says to use AskUserQuestion. Proceed through all steps automatically. When an inline skill completes and returns control, immediately continue to the next numbered step without waiting.

---

Automated orchestrator: runs `opsx-apply` → `opsx-verify` in a loop until verify is clean, optionally runs targeted tests (on host), then runs `opsx-archive`. The apply→verify loop runs inside an isolated Docker container (Claude CLI + app files only, no git, no GitHub). Tests run outside the container. Host handles testing, archive, branch creation, GitHub sync, and git commits.

Each app in this workspace has its own git repository. The container mounts the app's directory and a read-only copy of the shared `.claude/` skills. Nextcloud containers must be running on the host for environment checks and post-container testing.

```
[host] issue check → branch (from development) → container start (app dir + .claude skills)
  [container] apply → verify → loop (max 5) → verify-clean → exit
[host] test loop (optional, max 3) → deferred tests (optional, once) → archive (once) → git commit → github sync
```

**Input**: Optionally specify `<app> <change-name>` (e.g., `/opsx-apply-loop procest add-sla-tracking`). If omitted, prompt for app and change.

---

## Step 1: Select app and change

**If both app and change name are provided**, use them directly.

**If only one argument is provided**, treat it as the change name and scan all apps for a match.

**Otherwise**, scan all app directories for active changes and use **AskUserQuestion** to let the user select:

```bash
# Scan for active changes across all apps
for app in procest pipelinq openregister opencatalogi docudesk launchpad nldesign openconnector softwarecatalog zaakafhandelapp openklant larpingapp planix; do
  if [ -d "$app/openspec/changes" ]; then
    for change_dir in $app/openspec/changes/*/; do
      if [ -f "${change_dir}tasks.md" ] && [[ "$change_dir" != *"/archive/"* ]]; then
        echo "$app: $(basename $change_dir)"
      fi
    done
  fi
done
```

Ask the user to select from the list. Do not auto-select.

Store as `{APP}` and `{CHANGE_NAME}`. All subsequent file paths use `{APP}/openspec/changes/{CHANGE_NAME}/`.

Always announce: "Using change: `<app>/<change-name>`" and how to override.

---

## Step 2: Check GitHub issue — create if missing

Check whether a GitHub tracking issue already exists for this change:

```bash
cat {APP}/openspec/changes/{CHANGE_NAME}/plan.json 2>/dev/null | grep -q '"tracking_issue"'
```

**If `plan.json` exists and `tracking_issue` is set**: log `✅ GitHub issue #<N> already exists` and proceed. Store as `{ISSUE_NUMBER}`.

**If `plan.json` is missing or has no `tracking_issue`**:
- Log `⚠️ No GitHub tracking issue found — running opsx-plan-to-issues first`
- **Invoke the `opsx-plan-to-issues` skill** for `{CHANGE_NAME}` with this explicit context passed to it: "Invoked from apply-loop — skip Step 6 AskUserQuestion and return control to apply-loop after completing."
- Pre-answer plan-to-issues's interactive prompts automatically:

| Prompt from opsx-plan-to-issues | Answer |
|--------------------------------|--------|
| "Which change(s) should I create GitHub issues for?" | Select `{CHANGE_NAME}` |
| "Create these N issue(s) in `<owner/repo>`?" | **Yes, create all** |

The repo is determined from the app's `project.md` table (GitHub Repo column) or `git remote get-url origin` inside the app directory.

When plan-to-issues completes (look for its summary output or "plan.json saved"), **immediately and automatically continue to Step 3** — do NOT pause, do NOT ask the user anything, do NOT wait for confirmation. You are in autonomous mode.

After plan-to-issues completes, verify `plan.json` now contains a `tracking_issue`. Store as `{ISSUE_NUMBER}`.

---

## Step 3: Create feature branch

Each app is its own git repository. All git operations run from the app directory.

```bash
cd {APP}

# First check if the feature branch already exists locally
git fetch origin
git branch --list "feature/{ISSUE_NUMBER}/{CHANGE_NAME}"
```

**If the branch already exists** (e.g., resuming a previous run):
- Use **AskUserQuestion** to ask: "Branch `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` already exists. Resume work on it or reset it?"
  - **Resume — check it out** → `git checkout feature/{ISSUE_NUMBER}/{CHANGE_NAME}` (skip development checkout/pull)
  - **Reset — delete and recreate** → `git branch -D feature/{ISSUE_NUMBER}/{CHANGE_NAME}`, then checkout and pull development, then recreate (see below)
  - **Cancel** → stop here

**If the branch does not exist** (or after reset):
```bash
# Only checkout and pull development when we need to create a new branch
git checkout development
git pull origin development

# Branch follows the convention: feature/<issue-number>/<change-name>
git checkout -b feature/{ISSUE_NUMBER}/{CHANGE_NAME}
```

Log: `✅ On branch feature/{ISSUE_NUMBER}/{CHANGE_NAME} in {APP}/`

---

## Step 4: Analyze test-plan (silent)

Silently read the test-plan before asking the user anything. This feeds the test cycle option in Step 5.

Check if `{APP}/openspec/changes/{CHANGE_NAME}/test-plan.md` exists.

**If it exists:** read all `test command` field values, deduplicate, then classify each:

| Fits in loop? | Commands | Reason |
|---|---|---|
| **Yes** | `/test-functional` | Single agent, uses Playwright on host against live Nextcloud — tests GIVEN/WHEN/THEN from specs |
| **Yes** | `/test-api` | Single agent, REST API and ZGW compliance via curl — text output, no browser needed |
| **Yes** | `/test-security` | Single agent, uses Playwright on host — include only if change touches auth, roles, or permissions |
| **Yes** | `/test-accessibility` | Single agent, uses Playwright on host to inject axe-core — include only if change touches frontend UI |
| **No (deferred)** | `/test-counsel` | 8 parallel agents |
| **No (deferred)** | `/test-app` | Multi-agent or full-app sweep |
| **No (deferred)** | `/test-persona-*` | Too broad, not change-specific |
| **No (deferred)** | `/test-regression`, `/test-performance` | Cross-feature or non-blocking |

Rules:
- Any persona-specific command (`/test-persona-*`) that appears in the test-plan → replace with `/test-functional` in `{TEST_COMMANDS_IN_LOOP}` (same coverage, single agent)
- If no test-plan exists but tests are opted in → default `{TEST_COMMANDS_IN_LOOP}` = `[/test-functional]`
- All "fits in loop" commands run on the **host** (Step 9) via Playwright MCP and the live Nextcloud app — none of them run inside the Docker container

Store:
- `{TEST_COMMANDS_IN_LOOP}` — the filtered commands to run in the automated test loop
- `{TEST_COMMANDS_DEFERRED}` — the excluded commands to surface at the end (Step 13)
- `{TEST_PLAN_EXISTS}` — true/false

---

## Step 5: Confirm and show plan

Use **AskUserQuestion** to ask:

> "Ready to run `opsx-apply-loop` for `{APP}/{CHANGE_NAME}`?
>
> **Branch:** `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` in `{APP}/`
> **GitHub issue:** #`{ISSUE_NUMBER}`
>
> **Apply→verify loop** (inside isolated Docker container):
> - Max 5 iterations; CRITICAL issues stop the loop; warnings-only proceeds to archive
>
> **Optional: test cycle** (outside container, requires running Nextcloud environment):
> - After verify is clean, runs: `<list TEST_COMMANDS_IN_LOOP, or 'test-functional (default)' if no test-plan>`
> - Max 3 test iterations; if tests fail, loops back into apply→verify
> - ⚠️ These tests run on your host using the live Nextcloud app — NOT inside the container"

Options:
- **Start with test cycle** — include Phase 4 tests (set `{TESTS_ENABLED}=true`)
- **Start without tests** — skip test cycle (set `{TESTS_ENABLED}=false`)
- **Cancel** — stop here

---

## Step 6: Set up the apply-loop container

### 6.1 Create the log folder for this run

Before building or starting the container, create the dedicated log folder. All log files for this run go here — change name is in the folder, not the file names.

**Important:** Use an absolute path to the apps-extra root. Each app is its own git repo, so go one level up from the current app directory.

```bash
APPS_EXTRA_ROOT="$(cd .. && pwd)"

LOG_SUBDIR="$(date +%Y-%m-%d)-{APP}-{CHANGE_NAME}"
LOG_DIR="${APPS_EXTRA_ROOT}/.claude/logs/${LOG_SUBDIR}"
CONTAINER_LOG_DIR="/workspace/.claude-logs/${LOG_SUBDIR}"
mkdir -p "${LOG_DIR}"
```

> `LOG_DIR` is the absolute host-side path; `CONTAINER_LOG_DIR` is the same folder as seen inside the container (via the `.claude/logs` → `/workspace/.claude-logs` volume mount).

Log: `📁 Log folder: ${LOG_DIR}`

### 6.2 Scan for prior run history (host — do NOT delegate to container or test skills)

**You** (the host apply-loop orchestrator) scan the log folder now, before building the container. This step runs entirely in the host session — do not delegate to the container, to opsx-apply, to opsx-verify, or to any test skill.

```bash
ls "${LOG_DIR}" 2>/dev/null
```

If the folder is empty or does not yet contain any log files, set `{PRIOR_RUN_CONTEXT}` = `""` and continue.

If files exist, read each one yourself (use the Read tool) and build a plain-text summary. Files to look for and how to interpret them:

| File pattern | What it contains | How to use it |
|---|---|---|
| `apply-loop-*-result.log` | Final status of a previous container run (`STATUS=verify-clean`, `exhausted`, `blocked`) | Record status + iteration count |
| `apply-loop-*-live.log` | Per-minute monitoring status lines (file changes, CPU/mem, docker output) | Useful to see what the container was doing at each point in time |
| `apply-loop-*-container.log` | Full container output (captured on exit) | Skim for CRITICAL issues reported by verify, apply errors, and quality check failures — extract unresolved ones |
| `apply-loop-*-test-failures-*.log` | Host-side test failures written by Step 9b | Extract all FAILED test commands and their failure descriptions |
| `prompt.txt` | The startup prompt used for the last container run | Read to understand what context was already given |

**Build `{PRIOR_RUN_CONTEXT}`** as a structured plain-text block covering:

1. **Previous runs summary** — for each prior container run found: the run time, status, and number of iterations
2. **Unresolved CRITICAL issues** — list every CRITICAL issue reported by opsx-verify in prior runs that does NOT appear to have been fixed (i.e., it appeared in a result or log file and the final STATUS was not `verify-clean`, or it appeared in the last clean result's WARNING list). Be specific: file name, issue description, severity.
3. **Unresolved test failures** — list every test failure from `test-failures-*` files that was not subsequently resolved (i.e., the test was re-run after the failure was written and still failed, or no re-run was found). Include the failed command, affected area, and failure description.
4. **What was already attempted** — briefly note what apply tried to fix in prior iterations so the container does not repeat the same approach if it failed.

If no unresolved issues are found (e.g., the only prior run has `STATUS=verify-clean`), note that and set `{PRIOR_RUN_CONTEXT}` to a short "Previous run: verify-clean — no unresolved issues." string.

Log: `📋 Prior run history: <N result file(s), M test-failure file(s) found — <brief one-line summary>>`

### 6.3 Check versions and build/rebuild the container image

The Dockerfile lives at [assets/apply-loop.Dockerfile](assets/apply-loop.Dockerfile). **Always** check host versions against the Dockerfile — if versions have drifted, update the Dockerfile and rebuild.

**Step 1 — Read host versions:**
```bash
# Claude CLI version — must match the pinned version in the Dockerfile
claude --version
# → e.g. 2.1.83

# openspec version — must match the pinned version in the Dockerfile
openspec --version
# → e.g. 1.2.0
```

**Step 2 — Compare with Dockerfile and update if needed:**

Read `assets/apply-loop.Dockerfile` and check the `claude-code@X.X.X` and `@fission-ai/openspec@X.X.X` version pins. If either differs from the host version:
- Update the version in the Dockerfile in-place (edit `assets/apply-loop.Dockerfile` directly)
- Log: `📦 Updated Dockerfile: claude-code@<old> → <new>` (and/or openspec)
- Force a rebuild (even if the image already exists)

**Step 3 — Build if needed:**
```bash
# Check if image exists
docker image inspect apply-loop:latest >/dev/null 2>&1 && echo "exists" || echo "build needed"
```

Build (or rebuild after version update):
```bash
docker build -t apply-loop:latest -f .claude/skills/opsx-apply-loop/assets/apply-loop.Dockerfile .
```

> **Container user**: The image creates a non-root user `claude` with `HOME=/home/claude`. The `/home/claude/.claude/` directory is pre-created with correct ownership so that volume-mounting the credentials file does not cause permission issues with the CLI's `session-env/` directory.

Skip the build only if the image exists AND versions match.

### 6.4 Create the restricted Docker network (first time only)

```bash
docker network inspect apply-loop-net >/dev/null 2>&1 || \
  docker network create apply-loop-net
```

> **Note on full network isolation**: The network above still allows general outbound internet. To restrict it to the Claude API only (`api.anthropic.com`) you need iptables rules on the host — see the **Container Limitations** section at the bottom of this skill.

### 6.5 Start the container

Do **not** pass `--rm` — the container must be kept alive after exit so logs can be captured before removal.

Three volumes are mounted:
- The **app directory** → `/workspace` (read-write, contains code + openspec changes)
- The **shared `.claude/` directory** → `/workspace/.claude` (read-only, provides skill files to the container's Claude session)
- The **`.claude/logs/` directory** → `/workspace/.claude-logs` (read-write, for result and test-failure files — already gitignored in the config repo)

If this is a **test-failure re-entry** (Step 9c), use the re-entry prompt variant in Step 6.5 below.

Set the run time prefix for all log files created by this container run:
```bash
RUN_TIME=$(date +%H:%M)
```

Run the container in **detached mode** (`-d`) so the host session can monitor it while it runs.

**First, write the startup prompt to a file** to avoid shell quoting issues:

```bash
cat > "${LOG_DIR}/prompt.txt" << PROMPT_EOF
You are running inside an isolated Docker container for Nextcloud app {APP}, change {CHANGE_NAME}.
You have no git, no gh CLI, and no GitHub access. Do not attempt git or GitHub operations.
Archive is handled on the host after you exit — do NOT run opsx-archive.

Working directory is /workspace (= the {APP}/ app directory).
Skill files are at /workspace/.claude/skills/ (read-only mount of the shared .claude/).

Execute Steps 7–8 from the opsx-apply-loop skill at /workspace/.claude/skills/opsx-apply-loop/SKILL.md.
Read that file first to get the full instructions.

App: {APP}
Change name: {CHANGE_NAME}
Max iterations: 5
Log directory: ${CONTAINER_LOG_DIR}
Log file prefix: apply-loop-${RUN_TIME}
Result file: ${CONTAINER_LOG_DIR}/apply-loop-${RUN_TIME}-result.log
PROMPT_EOF
```

**Always append the prior run context** (even if empty — write the header so the container always sees a consistent block):

```bash
cat >> "${LOG_DIR}/prompt.txt" << PRIOR_EOF

## Prior run history for today (same app + change)

{PRIOR_RUN_CONTEXT}

If any unresolved CRITICAL issues or test failures are listed above, treat them as known bugs that your apply pass MUST address — do not skip them even if the task list does not explicitly mention them. If the prior run history says "no unresolved issues", proceed normally from the task list.
PRIOR_EOF
```

**If this is a test-failure re-entry**, additionally append:
```bash
echo "Test-failure re-entry: Also read ${CONTAINER_LOG_DIR}/apply-loop-{FAIL_TIME}-test-failures-{TEST_ITERATION} for the latest host-side test failures — use it alongside the prior run history above to guide what apply should fix in this iteration." >> "${LOG_DIR}/prompt.txt"
```

Then start the container:

```bash
docker run -d \
  --name "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" \
  -v "$(pwd)/{APP}:/workspace" \
  -v "$(pwd)/.claude:/workspace/.claude:ro" \
  -v "$(pwd)/.claude/logs:/workspace/.claude-logs" \
  $(if [ -f "$HOME/.claude/.credentials.json" ]; then echo "-v $HOME/.claude/.credentials.json:/home/claude/.claude/.credentials.json:ro"; elif [ -n "${ANTHROPIC_API_KEY}" ]; then echo "-e ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}"; fi) \
  -w /workspace \
  -e "CONTAINER_LOG_DIR=${CONTAINER_LOG_DIR}" \
  -e "RUN_TIME=${RUN_TIME}" \
  --network apply-loop-net \
  apply-loop:latest \
  sh -c 'claude --dangerously-skip-permissions --print "$(cat ${CONTAINER_LOG_DIR}/prompt.txt)"'
```

```bash
echo "$(date '+%H:%M:%S') — 🚀 Container started (detached). Monitoring begins..." >> "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
```

Log: `🚀 Container started (detached). Monitoring begins...`

### 6.6 Monitor the container (host)

The monitoring script runs **one check per invocation** (~60s), then exits. Claude re-runs it in a loop and posts a brief status update to the user after each invocation — giving you a live progress line in chat approximately every minute.

**Setup** (one-time):
```bash
chmod +x .claude/skills/opsx-apply-loop/assets/apply-loop-check.sh
```

**Monitoring loop** — run this command repeatedly (timeout: 120000ms per invocation):
```bash
.claude/skills/opsx-apply-loop/assets/apply-loop-check.sh "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" "$(pwd)/{APP}" "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
```

After **each invocation**, immediately:
1. **Show the output to the user** as a brief inline status — e.g. `⚙️ 14:32 — active | CPU: 85% MEM: 420MiB | changed: src/views/Foo.vue, src/store/bar.js`
2. Check the exit code and act:

| Exit code | Meaning | Action |
|-----------|---------|--------|
| **0** | Container still running | Re-run immediately (no new user approval needed) |
| **1** | Container stopped | Proceed to Step 6.7 |
| **2** | Stuck (5+ min no activity) | Use **AskUserQuestion**: "⚠️ Container appears stuck — no file changes or output for 5+ minutes." Options: **Keep waiting** (re-run the monitor — same command, already approved), **Show logs** (`docker logs apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} --tail=50`), **Kill and retry** (stop container, restart from Step 6.5), **Kill and stop** (stop container, report failure) |

The `CONTAINER_STOPPED` marker in the output confirms the container exited and includes the docker exit code.

### 6.7 Handle container exit

When the container stops (for any reason), immediately capture its full logs before doing anything else:

```bash
docker logs "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" 2>&1 | tee "${LOG_DIR}/apply-loop-${RUN_TIME}-container.log"
# These files served their purpose during the run — clean them up now
rm -f "${LOG_DIR}/prompt.txt"
rm -f "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
# Fallback: also catch any live.log not matched by RUN_TIME (e.g. resumed sessions)
find "${LOG_DIR}" -name "*-live.log" -delete 2>/dev/null || true
```

`container.log` is written to `${LOG_DIR}` (gitignored) and survives on the host regardless of what happens to the container next.

Determine the exit scenario:

```bash
EXIT_CODE=$(docker inspect "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" --format '{{.State.ExitCode}}')
RESULT=$(cat "${LOG_DIR}/apply-loop-${RUN_TIME}-result.log" 2>/dev/null || echo "STATUS=unknown")
```

**Scenario A — Verify clean** (`EXIT_CODE=0`, `STATUS=verify-clean`):
- Remove the container automatically:
  ```bash
  docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"
  ```
- **Sync GitHub issue checkboxes immediately** — read current `{APP}/openspec/changes/{CHANGE_NAME}/tasks.md` (pre-archive location) and check off every task marked `[x]` in issue #`{ISSUE_NUMBER}`:
  - **MCP (preferred):** `get_issue` → patch all `- [ ]` → `- [x]` for done tasks → `update_issue`
  - **CLI (fallback):** `gh issue view {ISSUE_NUMBER} --repo <owner/{APP}> --json body` → update checkboxes → `gh issue edit ...`
  - Log: `✅ GitHub issue #<N> checkboxes synced (post-container)`
- **Update apply-loop status comment** — post or update a single comment starting with `## Apply-Loop Status` on issue #`{ISSUE_NUMBER}`. Search existing comments for one with that header; update via PATCH if found, create if not:
  ```markdown
  ## Apply-Loop Status

  | Stage | Status | Details |
  |-------|--------|---------|
  | Implementation | ✓ Complete | All tasks done |
  | Quality Checks | ✓ Pass | |
  | Verification | ✓ Pass | verify-clean |
  | Host Tests | pending | |
  | Archive | pending | |

  *Updated: YYYY-MM-DD HH:MM UTC*
  ```
  - **MCP (preferred):** `list_issue_comments` → find comment → `update_issue_comment` or `add_issue_comment`
  - **CLI (fallback):** `gh api repos/{owner}/{repo}/issues/{n}/comments` → PATCH or POST
- Proceed to Step 9 (host test loop)

**Scenario B — Loop exhausted** (`EXIT_CODE=0`, `STATUS=exhausted`):
- Show the remaining CRITICAL issues from `${LOG_DIR}/apply-loop-${RUN_TIME}-result.log` and the tail of the log
- Use **AskUserQuestion** to ask:
  > "Loop exhausted. Logs saved to `${LOG_DIR}`. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → go to the Loop exhausted section
  - **Yes, keep it for now** → print `docker exec -it apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} bash` → do NOT remove → go to Loop exhausted section

**Scenario C — Apply blocked** (`EXIT_CODE=0`, `STATUS=blocked`):
- Show the blocker details from `${LOG_DIR}/apply-loop-${RUN_TIME}-result.log` and the tail of the log
- Use **AskUserQuestion** to ask:
  > "Apply was blocked. Logs saved to `${LOG_DIR}`. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop and wait for user to resolve the blocker
  - **Yes, keep it for now** → print the `docker exec` command → do NOT remove → stop and wait

**Scenario D — Container crashed** (`EXIT_CODE≠0`):
- Show the full log output
- Use **AskUserQuestion** to ask:
  > "The container exited unexpectedly (exit code `{EXIT_CODE}`). Logs saved to `${LOG_DIR}`. Keep the container for debugging?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop, report the crash
  - **Yes, keep it for debugging** → print `docker exec -it apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} bash` → do NOT remove → stop, report the crash

**Scenario E — User interrupted (SIGTERM/Ctrl+C)**:
- Capture logs (already done above), then use **AskUserQuestion** to ask:
  > "The loop was interrupted. Logs saved to `${LOG_DIR}`. Some files may be partially written. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop
  - **Yes, keep it for now** → print the `docker exec` command → do NOT remove → stop

> The steps below (7–8) are the instructions executed **inside the container** by the container's Claude CLI session.

---

## Step 7: Initialise the loop log (inside container)

Create an in-memory iteration log. Append to it after each apply and verify pass. Use **plain-text status lines** (not a markdown pipe table) — pipe tables look misaligned in terminal output and raw log files when column content widths vary. The formatted summary table is produced once in Step 15.

```
## apply-loop log — {APP}/{CHANGE_NAME}
```

Set `iteration = 1`. Set `max_iterations = 5`.

The log directory and log file prefix for this run were passed in your startup prompt. Use them for all output files:
- `<log-dir>` = the log directory (e.g. `/workspace/.claude-logs/YYYY-MM-DD-{APP}-{CHANGE_NAME}`)
- `{RUN_TIME_PREFIX}` = the log file prefix (e.g. `apply-loop-11:26`) — use this as the prefix for result files: `<log-dir>/{RUN_TIME_PREFIX}-result`

Working directory is `/workspace` (= the app directory). All openspec paths are relative to this: `openspec/changes/{CHANGE_NAME}/`.

---

## Step 8: Apply → Verify loop (inside container)

Repeat the following until verify is clean or `iteration > max_iterations`.

### 8a. Run apply (via opsx-apply)

Log: `⚙️ Iteration <N> — running apply`

**Invoke the `opsx-apply` skill** for `{CHANGE_NAME}`. Pre-answer its interactive prompts to keep the loop automated:

| Prompt from opsx-apply | Answer |
|------------------------|--------|
| "Ready to implement N remaining tasks?" | **Start implementing** |
| "Which task number?" (if shown) | Not applicable — start from first pending |
| "What would you like to do next?" (end of apply) | Do NOT answer — return control to apply-loop |

**Note**: The apply skill will attempt GitHub issue updates — these will silently fail inside the container (no gh CLI/GitHub access). This is expected. GitHub sync is handled after the container exits (Step 12).

**Seed data (ADR-016)**: When apply implements tasks that introduce or modify OpenRegister schemas, the seed data entries in `lib/Settings/{app}_register.json` must also be created/updated. The apply skill handles this — ensure it does not skip this step even inside the container.

**If this is a test-failure re-entry**: read the test-failures file specified in your startup prompt (e.g. `<log-dir>/apply-loop-HH:MM-test-failures-N`) for context on what the host-side tests reported. Use those failures to guide which code areas to focus on during apply.

Quality checks run directly in the container (PHP and Composer are installed in this image). The `docker compose exec` approach is not available — run directly:
```bash
cd /workspace && composer check:strict 2>&1
# If check:strict not available:
composer phpcs 2>&1 && composer phpmd 2>&1 && composer psalm 2>&1
```

For auto-fixable issues:
```bash
cd /workspace && composer phpcs:fix 2>&1
# OR: composer cs:fix
```

Frontend quality checks (if `package.json` exists with lint scripts):
```bash
cd /workspace && npm run lint 2>&1
npm run stylelint 2>&1
```

**If apply is blocked** (missing artifacts, design issues, unclear requirements): stop the loop, report the blocker, write the result file to `<log-dir>`, and exit cleanly:
```bash
echo "STATUS=blocked" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
echo "REASON=<blocker description>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
```
The host (Step 6.7 Scenario C) handles user prompting and container removal.

Append to iteration log:
`[Iter <N> | apply ] <N> tasks implemented — Quality: ✓ pass / N issues fixed / N issues remain`

### 8b. Run verify (via opsx-verify)

Log: `🔍 Iteration <N> — running verify`

**Invoke the `opsx-verify` skill** for `{CHANGE_NAME}`. Pre-answer its interactive prompts:

| Prompt from opsx-verify | Answer |
|-------------------------|--------|
| "Would you also like to run API and/or browser tests?" | **Skip testing** — no network access to Nextcloud in this container |
| "Found issues. Would you like me to fix them?" | **No, leave as-is** — the next apply iteration handles fixes |
| "Ready to archive this change?" | **No, not yet** — archive is handled on the host |
| "Archive with warnings?" | **No, not yet** — archive is handled on the host |

**Note**: The verify skill will attempt GitHub issue sync — this will silently fail inside the container. Expected. Sync happens after container exits (Step 12).

**Classify all findings** as CRITICAL, WARNING, or SUGGESTION (as reported by opsx-verify).

**Append to iteration log**, e.g.:
`[Iter <N> | verify] ✓ Clean` or `[Iter <N> | verify] X CRITICAL, Y WARNING — <brief summary>`

### 8c. Evaluate and decide

**If CRITICAL issues found (with or without WARNINGs) and `iteration < max_iterations`**:
- Display the findings clearly
- Log: `[Iter <N> | verify] X CRITICAL — continuing loop`
- Increment `iteration`
- Go back to **Step 8a**

**If only WARNING (or SUGGESTION) issues found and `iteration < max_iterations`**:
- Assess whether each warning is **actionable** (a further apply pass could plausibly fix it) or **non-actionable** (e.g., unverifiable inside this container, requires a live runtime environment, depends on external data, or is by design):
  - **All actionable** → continue loop:
    Log: `[Iter <N> | verify] Y WARNING(s) — actionable, continuing loop`
    Increment `iteration`, go back to **Step 8a**
  - **All non-actionable** (or no actionable ones remain) → proceed without restarting:
    Log: `[Iter <N> | verify] Y WARNING(s) — loop not restarted: <reason for each non-actionable warning>`
    Write result file with `WARNINGS_ONLY=true` and exit (same path as the max-iterations warnings case below)

**If CRITICAL issues found and `iteration == max_iterations`**:
- Stop — CRITICAL issues cannot be carried to archive
- Write `<log-dir>/apply-loop-{RUN_TIME_PREFIX}-result` with `STATUS=exhausted` and the list of remaining issues
- Exit the container

**If only WARNING (or SUGGESTION) issues remain and `iteration == max_iterations`**:
- Log: `[Iter <N> | verify] Y WARNING(s) — max iterations reached, proceeding`
- Write the result file and exit:
  ```bash
  echo "STATUS=verify-clean" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "APP={APP}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "CHANGE={CHANGE_NAME}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "ITERATIONS=<N>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "WARNINGS_ONLY=true" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  ```

**If no CRITICAL and no WARNING issues**:
- Log: `[Iter <N> | verify] ✓ Clean`
- Write the result file and exit:
  ```bash
  echo "STATUS=verify-clean" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "APP={APP}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "CHANGE={CHANGE_NAME}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "ITERATIONS=<N>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  ```

Container exits cleanly. **Do NOT run opsx-archive — that is handled on the host.**

---

> The steps below (9–15) execute on the **host**, after the container has exited.

---

## Step 9: Host test loop (conditional)

**Skip this step entirely if `{TESTS_ENABLED}=false`.** Proceed directly to Step 10.

**Check Nextcloud environment** — quick check before running tests:

```bash
DOCKER_DEV_ROOT="$(cd ../../../.. && pwd)"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/status.php 2>/dev/null)
```

**If `HTTP_CODE` is `200`**: Nextcloud is running. Verify the app is enabled:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" exec -T nextcloud php occ app:list --enabled 2>/dev/null | grep -q "^  - {APP}$" && echo "✅ {APP} enabled" || echo "⚠️ {APP} not found in enabled apps"
```

Log: `✅ Nextcloud ready, {APP} enabled`

**If `HTTP_CODE` is not `200`** (or app is not enabled): start containers and wait up to 60s:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" up -d nextcloud proxy
```

Poll `http://localhost:8080/status.php` every 5s. If still not `200` after 60s, show logs and stop:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" logs master-nextcloud-1 --tail=20
```

> "⛔ Nextcloud did not start in time. Fix the environment and re-run `/opsx-apply-loop`."

Set `test_iteration = 1`. Set `max_test_iterations = 3`.

### 9a. Run the in-loop test commands

Log: `🧪 Test iteration <T> — running: <TEST_COMMANDS_IN_LOOP>`

**Use the Agent tool (NOT the Skill tool)** to run each command in `{TEST_COMMANDS_IN_LOOP}` sequentially. The Agent tool runs as a subprocess and returns results directly to you — this is what allows the loop to continue after each test without getting stuck. Never use the Skill tool here; it loads the skill inline and the conversation will terminate instead of returning control.

For each command (e.g. `/test-functional` → skill name `test-functional`), determine the absolute path to the skill file. The `.claude/` directory is at the apps-extra workspace root, one level above `{APP}/`. Construct the path as:

```
CLAUDE_SKILLS="$(cd {APP}/.. && pwd)/.claude/skills"
SKILL_FILE="${CLAUDE_SKILLS}/{skill-name}/SKILL.md"
```

Launch a **general-purpose Agent** with a prompt that includes:
1. "Read and follow the skill instructions at `{SKILL_FILE}`."
2. "App: `{APP}`. Change: `{CHANGE_NAME}`. Save all test output (screenshots, result files) inside `{APP}/test-results/` — never in the workspace root."
3. "You are in READ-ONLY mode — do NOT modify any code files. Your job is to test the current state, identify failures, and produce a structured report."
4. "End with the structured result line (e.g. `FUNCTIONAL_TEST_RESULT: PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>`). Output nothing after the result line."
5. Any relevant prior-run context (test failures from earlier iterations if this is a re-entry).

**CRITICAL: Test skills must NEVER make code changes.** They must only:
1. Run tests against the current code
2. Identify what is failing and why
3. Produce a report describing what needs to change (file, line, issue, suggested fix)

Each command automatically targets `{APP}` when invoked (or pass the app name as an argument if the skill supports it).

After each Agent call returns, read its result summary to extract pass/fail from the structured result line (e.g., `FUNCTIONAL_TEST_RESULT: PASS | FAIL`). A command **fails** if it reports FAIL or any CRITICAL/HIGH-level finding. If a test skill does not output a result line, treat its recommendation as: APPROVE/COMPLIANT/SECURE = PASS, anything else = FAIL.

Update the loop log:
```
| T<N> | test:<cmd> | ✓ Pass / X FAIL | <summary> |
```

After the Agent returns, **immediately continue to the next command or Step 9b** — do NOT pause, do NOT ask the user anything, do NOT wait for confirmation.

### 9b. Evaluate test results

**If all commands pass**:
- Log: `✅ Test iteration <T> — all tests pass`
- Proceed to **Step 10** (deferred tests)

**If any command fails and `test_iteration < max_test_iterations`**:
- Log: `⚠️ Test iteration <T> — failures found, re-entering apply→verify`
- Write test failures to a file for the container to read:
  ```bash
  FAIL_TIME=$(date +%H:%M)
  echo "TEST_ITERATION=<T>" > "${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log"
  echo "FAILED_COMMANDS=<list>" >> "${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log"
  # Append the full failure output from each failed test command
  ```
- Increment `test_iteration`
- Go to **Step 9c**

**If any command fails and `test_iteration == max_test_iterations`**:
- Log: `⛔ Test loop exhausted after 3 iterations — failures remain`
- Use **AskUserQuestion** to ask:
  > "Tests still failing after 3 iterations. How would you like to proceed?"
  - **Archive anyway** — proceed to Step 10 with a warning note
  - **Fix manually, then re-run** — stop here; user can run `/opsx-apply-loop {APP} {CHANGE_NAME}` again
  - **Skip tests and archive** — proceed to Step 10, skip remaining test steps
  - **Cancel** — stop here, do not archive

### 9c. Re-enter container for test-failure fixes

Start a new container run (reuse Step 6.5 with test-failure re-entry variant). The container will:
1. Read the test-failures file passed in the startup prompt (e.g. `${CONTAINER_LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log`) for context
2. Run `opsx-apply` targeting the failing areas
3. Run `opsx-verify` to check the fix
4. Exit with `STATUS=verify-clean` or `STATUS=exhausted`

Wait for container exit (monitoring via Step 6.6). Handle exit scenarios per Step 6.7.

After a **verify-clean** exit from the re-entry container, sync GitHub issue checkboxes immediately (same as Scenario A in Step 6.7) — read current `{APP}/openspec/changes/{CHANGE_NAME}/tasks.md` and update issue #`{ISSUE_NUMBER}`.

Clean up the container after re-entry exits (keep all log and test-failure files):
```bash
docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"
```

Return to **Step 9a**.

---

## Step 10: Deferred tests (conditional)

**Skip this step if any of these are true:**
- `{TESTS_ENABLED}=false`
- `{TEST_COMMANDS_DEFERRED}` is empty
- The test loop exhausted in Step 9 with unresolved failures and the user chose to cancel

If applicable, use **AskUserQuestion** to ask:

> "The following test commands were in your test-plan but were not included in the automated loop (multi-agent or broad-scope):
>
> <list {TEST_COMMANDS_DEFERRED} with reason each was excluded>
>
> Would you like to run these now? If any fail, one final apply→verify cycle will run to address the findings before archiving."

Options:
- **Yes, run them** — proceed
- **Skip** — proceed to Step 11 (archive)

**If yes:**

Use the **Agent tool** (NOT the Skill tool) to run each command in `{TEST_COMMANDS_DEFERRED}` sequentially, exactly as described in Step 9a — construct the skill file path, launch a general-purpose Agent in READ-ONLY mode, and read its structured result line. Run all commands once (not looped). After each Agent returns, immediately continue to the next — do NOT pause between commands.

> **Note — reduced parallelism**: Sub-agents spawned via the Agent tool do not have access to the Agent tool themselves, so multi-agent skills like `/test-counsel` (which normally runs 8 persona agents in parallel) will run sequentially instead. Coverage is the same; it just takes longer. This is expected and acceptable for the deferred test pass.

**If all pass**: log `✅ Deferred tests all passed` — **immediately and automatically continue to Step 11** (archive).

**If any fail**:
- Log: `⚠️ Deferred test failures found — running one final apply→verify cycle`
- Write failures: `FAIL_TIME=$(date +%H:%M)` then write to `${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log`
- Start one final container run (Step 6.5, test-failure re-entry variant)
- Wait for exit; handle per Step 6.7
- **Immediately and automatically continue to Step 11** regardless of `STATUS` (report exhaustion if needed, but archive once)

---

## Step 11: Archive (host)

**Use the Agent tool (NOT the Skill tool)** to run `opsx-archive`. The Agent tool runs as a subprocess and returns results directly — this is what allows the orchestrator to continue to Steps 12–15 after archiving. Using the Skill tool inline would terminate the conversation instead of returning control.

Construct the skill file path:
```
CLAUDE_SKILLS="$(cd {APP}/.. && pwd)/.claude/skills"
SKILL_FILE="${CLAUDE_SKILLS}/opsx-archive/SKILL.md"
```

Launch a **general-purpose Agent** with a prompt that includes:
1. "Read and follow the skill instructions at `{SKILL_FILE}`."
2. "Change: `{CHANGE_NAME}`. Working directory: `$(pwd)/{APP}/`."
3. "You are invoked from apply-loop — do NOT close the GitHub issue (that is handled by the host in Step 13c). Return control to apply-loop after completing."
4. Pre-answered prompts:

| Prompt from opsx-archive | Answer |
|--------------------------|--------|
| "Sync delta specs first?" | **Sync now** |
| "Convert test cases to test scenarios?" (step 4.5) | **Skip** — apply-loop asks after all loops finish (Step 14) |
| "Close GitHub issue #N?" | **No, leave it open** |

5. "End with the line `ARCHIVE_RESULT: DONE  ARCHIVE_PATH: <path>`. Output nothing after the result line."

The archive skill handles: artifact completion check, delta spec sync, spec link updates in main specs, `docs/features/` updates, and `CHANGELOG.md`.

When the Agent returns, extract `{ARCHIVE_PATH}` from the result line.

**Immediately and automatically continue to Step 12** — do NOT pause or wait for user input.

Log: `📦 Change archived`

---

## Step 12: Git commit (host)

Commit all changes — implementation, test fixes, and archive artifacts — in one commit. Run from the app directory:

```bash
cd {APP}
git add .
git status  # review what changed
git commit -m "feat: implement {CHANGE_NAME}

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

Log: `✅ Changes committed to feature/{ISSUE_NUMBER}/{CHANGE_NAME} in {APP}/`

---

## Step 13: GitHub sync (host)

The container skipped all GitHub operations. Run them now from the host using the gh CLI.

**13a. Final checkbox sync** — verify the issue reflects the fully archived state of `tasks.md`. Earlier syncs (Step 6.7 Scenario A and Step 9c) updated checkboxes from the pre-archive location; this final sync reads the archived copy to ensure nothing was missed:
- Read `{APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/tasks.md` (archived location)
- For every task marked `[x]`, ensure the corresponding checkbox is checked in issue #`{ISSUE_NUMBER}` — update any that are still unchecked:
  - **MCP (preferred):** `get_issue` → find any remaining `- [ ]` task lines → change to `- [x]` → `update_issue` (single call)
  - **CLI (fallback):** `gh issue view {ISSUE_NUMBER} --repo <owner/app> --json body --jq '.body'` → update checkboxes → `gh issue edit {ISSUE_NUMBER} --repo <owner/app> --body "<updated>"`

**13b. Add a completion comment** to the issue:
- **MCP (preferred):** `add_issue_comment` → `{owner, repo, issue_number: {ISSUE_NUMBER}, body: "✓ apply-loop complete — all tasks implemented and verified. Change archived to {APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/"}`
- **CLI (fallback):** `gh issue comment {ISSUE_NUMBER} --repo <owner/app> --body "..."`

**13c. Ask about closing the issue** using **AskUserQuestion**:

> "Close GitHub issue #{ISSUE_NUMBER}?"

Options:
- **Yes, close it** — `gh issue close {ISSUE_NUMBER} --repo <owner/app>`
- **No, leave it open** — skip (e.g., if a PR review will handle it)

Log: `✅ GitHub issue synced`

---

## Step 14: Post-archive — test scenario conversion

Check if `{APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/test-plan.md` exists.

**If test-plan.md exists**, use **AskUserQuestion** to ask:

> "The change had a test-plan.md. Convert test cases to reusable test scenarios?
>
> Test scenarios are picked up automatically by `/test-counsel`, `/test-app`, and persona test commands."

Options:
- **Yes, convert all** — run the test scenario conversion step from opsx-archive
- **Let me choose** — list each TC, user picks which to convert
- **Skip** — do not create test scenarios

**If test-plan.md does not exist**: skip silently.

---

## Step 15: Final report and what's next

Before composing the report, **read all log files** in `${LOG_DIR}` to ensure the summary reflects everything that actually happened across all container runs and test iterations:

```bash
ls -t "${LOG_DIR}"/
```

Read each file:
- All `apply-loop-*-result.log` files — collect STATUS, ITERATIONS, WARNINGS_ONLY from each run
- All `apply-loop-*-container.log` files — skim for apply/verify outcomes per iteration
- All `apply-loop-*-test-failures-*.log` files — note which test iterations failed and what was reported
- The in-memory iteration log you maintained throughout this session

Reconcile any discrepancies between the in-memory log and the file contents — the files are authoritative.

Then display the complete report:

```
## opsx-apply-loop — {APP}/{CHANGE_NAME}

### Loop Log
| Iter | Phase         | Result                    | Notes                       |
|------|---------------|---------------------------|-----------------------------|
| 1    | apply         | 4 tasks implemented       | Quality: ✓ pass             |
| 1    | verify        | 1 CRITICAL                | Missing unit test           |
| 2    | apply         | Fixed: added test         | Quality: ✓ pass             |
| 2    | verify        | ✓ Clean                   | —                           |
| T1   | test-functional | ✓ Pass                  | —                           |
| —    | archive       | ✓ Archived               | {APP}/openspec/changes/archive/ |

### Summary
- App: {APP}
- Branch: feature/{ISSUE_NUMBER}/{CHANGE_NAME} (committed)
- Apply→verify iterations used: 2 / 5
- Test iterations used: 1 / 3 (or: tests skipped)
- Final verify status: ✓ Clean
- Warnings noted: 0
- Archive: ✓ {APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/
- GitHub issue: #{ISSUE_NUMBER} synced + closed / left open
```

Write the same report to a file **first, before any cleanup**:

```bash
cat > "${LOG_DIR}/final-result.md" << 'EOF'
<the full report text above>
EOF
```

Then run the **per-file log cleanup**. The goal is to delete files whose content is fully superseded (all issues they mention were resolved), while keeping any file that references an issue still open in the final state.

**What counts as currently unresolved**: any CRITICAL or WARNING that appears in the final `result` or final `container.log` and was NOT fixed in a subsequent iteration. Specifically:
- `WARNINGS_ONLY=true` in the final result → there are still warnings; the final `container.log` is needed as evidence
- A test-failures file → its failures are unresolved UNLESS a subsequent `container.log` or `result` shows those areas were fixed and verify passed clean after

Evaluate each file:

| File type | Delete if… | Keep if… |
|---|---|---|
| `apply-loop-*-result.log` (non-final) | All issues it reported were fixed in a later iteration | It's the most recent, or its issues are still open |
| `apply-loop-*-container.log` (non-final) | All CRITICALs/WARNINGs it reported were fixed in a later run | It references issues still unresolved in the final state |
| `apply-loop-*-container.log` (final/most recent) | — | Always keep |
| `apply-loop-*-test-failures-*.log` | The specific failures it lists were fixed (a later container run shows verify-clean covering those areas) | The failures were never resolved |

Always keep: `final-result.md`, the most recent `container.log`, the most recent `result`.

Log which files were deleted and which were kept, with the reason for each kept file.

Then use **AskUserQuestion** to ask: "What would you like to do next?"

Options:
- **Create a PR** (`/create-pr`) — open a pull request from `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` in `{APP}/`
- **Sync app docs** (`/sync-docs app {APP}`) — update `{APP}/docs/` to reflect the new feature
- **Sync dev docs** (`/sync-docs dev`) — update `.claude/docs/`
- **Start a new change** (`/opsx-new`)
- **Done for now** — end the session

---

**If loop exhausted (CRITICAL issues remain after 5 apply→verify iterations)**:

```
⛔ Loop stopped after 5 iterations — CRITICAL issues remain.

### Remaining CRITICAL issues:
- <issue 1 with file:line reference>
- <issue 2 with file:line reference>
```

The container has exited. The feature branch in `{APP}/` has partial changes. Use **AskUserQuestion** to ask: "How would you like to proceed?"

- **Fix manually, then re-run** — edit files in `{APP}/` on the feature branch and run `/opsx-apply-loop {APP} {CHANGE_NAME}` again
- **Open verify interactively** — run `/opsx-verify {CHANGE_NAME}` from within `{APP}/` to inspect in detail
- **Commit partial work and open a draft PR** — commit what's there, open a draft PR for review
- **Abandon branch** — `cd {APP} && git checkout -` and `git branch -D feature/{ISSUE_NUMBER}/{CHANGE_NAME}`

---

## Container Limitations

The loop container has no git and no GitHub access by design. The following things the sub-skills normally do **will not work inside the container** — they are handled by the host steps instead:

| Capability | Why it fails in container | Handled by |
|-----------|--------------------------|------------|
| GitHub issue checkbox updates (opsx-apply, opsx-verify) | No gh CLI / GITHUB_TOKEN | Step 13a (host) |
| GitHub issue comments | No gh CLI / GITHUB_TOKEN | Step 13b (host) |
| GitHub issue closing (opsx-archive) | No gh CLI / GITHUB_TOKEN | Step 13c (host) |
| Quality checks via `docker compose exec` (opsx-apply) | No Docker socket in container | PHP + Composer + npm run directly in container |
| curl API tests against Nextcloud (opsx-verify) | No network path to Nextcloud containers | Pre-answered "Skip testing" |
| Browser tests (opsx-verify, test-functional, etc.) | No browser in container | Test loop runs on host (Step 9) |
| git commits | No git in container | Step 12 (host) |
| opsx-archive | Moved to host — runs after test loop | Step 11 (host) |

**Container volumes**:
- `/workspace` → `{APP}/` (read-write: app code + openspec changes)
- `/workspace/.claude` → `.claude/` (read-only: shared skill files for the container's Claude session)

**Restricting the container network to Claude API only** (optional but recommended):

The `apply-loop-net` bridge network allows general outbound by default. To lock it down to `api.anthropic.com` only, add these iptables rules on the host after creating the network:

```bash
SUBNET=$(docker network inspect apply-loop-net --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}')
iptables -I DOCKER-USER -s $SUBNET -p udp --dport 53 -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -p tcp --dport 53 -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -d api.anthropic.com -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -j DROP
```

Run `iptables -D DOCKER-USER ...` with the same rules to remove them when done.

---

## Guardrails

- **Orchestrator only — NO direct code changes** — the host (orchestrator) session MUST NOT edit, create, or delete code files directly. All code changes — including fixes to dependency bugs discovered during testing — must go through the container's apply→verify loop. If a discovered bug is in a file the container cannot access (e.g., a different app's directory not mounted in the container), use **AskUserQuestion** to ask the user how they want to handle it before proceeding.
- **Orchestrator only** — this skill does not implement, verify, archive, or test directly; it delegates to `opsx-apply`, `opsx-verify`, `opsx-archive`, and the test commands
- **Per-app isolation** — all git operations, quality checks, and openspec commands run from within `{APP}/`; never across app boundaries
- **Host handles git, GitHub, tests, and archive** — all git commits, GitHub API calls, browser tests, and archive happen on the host; the container only runs apply→verify
- **Max 5 apply→verify iterations** — CRITICAL issues stop the loop after all iterations are exhausted; warnings-only always proceeds
- **Max 3 test iterations** — test failures loop back into apply→verify a maximum of 3 times; on exhaustion the user chooses whether to archive anyway
- **Deferred tests run once** — multi-agent/broad tests deferred from the loop are run once at most; if they fail, exactly one more apply→verify cycle runs (no further test looping)
- **Container is stateless** — it writes file changes to the mounted app volume and result/failure files to `.claude/logs/` via the third volume mount (already gitignored); the host reads those on exit
- **Pre-answer all interactive prompts** — apply, verify, and archive prompts are answered automatically (see prompt tables); the only interactive moments are closing the GitHub issue (Step 13c) and deferred tests (Step 10)
- **Archive runs exactly once** — deferred tests (Step 10) run before archive (Step 11); there is no re-archive
- **Single git commit** — all changes from all apply→verify cycles, all test fixes, and the archive are committed together in Step 12
- **Test scenario conversion is deferred** — archive's step 4.5 is skipped; apply-loop asks in Step 14
- **No force push, no destructive git ops** — same git safety rules as all opsx skills
- **Branch naming convention** — `feature/<issue-number>/<change-name>` to match the opsx-pipeline convention used across this workspace

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
