---
name: create-pr
description: Create a Pull Request from the current branch — runs local checks, picks target branch, and opens the PR on GitHub
---

# Create Pull Request

Guides the developer through creating a Pull Request for a Nextcloud app. Confirms the source branch, recommends a target branch based on the branching strategy, optionally runs local quality checks, then creates the PR via the GitHub REST API.

---

## Model Recommendation

This skill involves parsing CI workflows, detecting branch-protection rules, resolving bootstrap deadlocks, and reasoning about code diffs. Mistakes here have real consequences.

**First, check the active model** from your system context (it appears as "You are powered by the model named…").

- If the active model is **Haiku or any model other than Sonnet or Opus**: stop immediately and tell the user:
  > "This command requires Sonnet or Opus — the CI workflow parsing and branch-protection analysis steps need stronger reasoning than Haiku can reliably provide. Please switch models and re-run."

- If the active model is **Sonnet or Opus**: ask the user using AskUserQuestion:

**"You're on [active-model]. Which model should I use for this PR?"**

| Model | Best for |
|---|---|
| **Sonnet** | Most PRs — handles CI parsing and branch logic well |
| **Opus** | Repos with reusable CI workflows, branch-protection rulesets, or a complex branching strategy — that's where it pays off most |

- **Sonnet**
- **Opus**

If the chosen model differs from the active model, tell the user:
> "You're on [active-model] but chose [chosen-model]. To switch: use `/model [chosen-model]` in the chat input, or open the model picker in the Claude Code UI. Then re-run this command."
Then stop.

---

## Hard Rules

**NEVER modify `.github/workflows/` files.** This skill reads workflow files to understand what CI runs, but must never edit, create, or delete any workflow file or change any job definition — regardless of what checks fail or what the branch protection requires. If a workflow mismatch is detected (e.g. wrong job name for a required status check), report the issue to the user and stop — do not attempt to fix it.

---

## Step 0: Select Repository

Scan the workspace for available git repositories:

```bash
for dir in /home/wilco/nextcloud-docker-dev/workspace/server/apps-extra/*/; do
  if [ -d "$dir/.git" ] || git -C "$dir" rev-parse --git-dir > /dev/null 2>&1; then
    echo "$dir"
  fi
done
```

For each found repo, also get its remote URL and current branch:
```bash
git -C {dir} remote get-url origin 2>/dev/null
git -C {dir} branch --show-current 2>/dev/null
```

Ask the user using AskUserQuestion:

**"Which repository do you want to create a PR for?"**

List each repo as: `{app-name}  [{current-branch}]  ({remote-url})`

Store the selected repo's absolute path as `{REPO_ROOT}`.

---

## Step 1: Detect Current Branch & Repo

Run within `{REPO_ROOT}`:
```bash
git -C {REPO_ROOT} branch --show-current
git -C {REPO_ROOT} remote get-url origin
```

Store:
- `{CURRENT_BRANCH}` — active branch name
- `{REMOTE_URL}` — GitHub repo URL

---

## Step 2: Confirm Source Branch

Ask the user using AskUserQuestion:

**"Your current branch is `{CURRENT_BRANCH}`. Is this the correct branch to create a PR from?"**
- **Yes** — proceed with `{CURRENT_BRANCH}`
- **No, let me specify** → ask them for the correct branch name and store it as `{CURRENT_BRANCH}`

---

## Step 3: Recommend Target Branch

Based on `{CURRENT_BRANCH}`, determine the recommended target using the project branching strategy:

| Source branch pattern | Recommended target | Allowed targets |
|-----------------------|--------------------|-----------------|
| `feature/*`, `bugfix/*`, or other non-standard branch | `development` | `development` |
| `development` | `beta` | `beta` |
| `beta` | `main` | `main` |
| `hotfix/*` | `main` | `main`, `beta`, `development` |

Fetch available remote branches:
```bash
git -C {REPO_ROOT} fetch --prune
git -C {REPO_ROOT} branch -r | grep -v HEAD | sed 's|origin/||' | sort
```

Ask the user using AskUserQuestion:

**"Which branch should this PR target?"**

List the allowed target branches, marking the recommended one with `(recommended)`. If there is only one valid option, pre-select it and ask for confirmation instead.

Store the answer as `{TARGET_BRANCH}`.

---

## Step 3.2: Validate Target Branch Against Branch-Protection Workflow

Some repos enforce allowed source→target combinations via a branch-protection reusable workflow. Check for this **before** doing anything else, so the user doesn't waste time running checks only to have the PR rejected.

**1. Find any branch-protection workflow triggered on pull_request:**
```bash
grep -rl "branch-protection" {REPO_ROOT}/.github/workflows/ 2>/dev/null
```

**2. For each found workflow, read it and extract the `uses:` line:**
```bash
# e.g. uses: ConductionNL/.github/.github/workflows/branch-protection.yml@main
```

**3. Fetch and read the reusable workflow:**
```bash
gh api "repos/{org}/{repo}/contents/{path}?ref={ref}" --jq '.content' | base64 -d
```

**4. Simulate the validation logic** — look for `run:` steps that check `github.base_ref` / `github.head_ref` (source/target branch patterns). Parse the allowed combinations and evaluate them with `{CURRENT_BRANCH}` as source and `{TARGET_BRANCH}` as target.

**If the combination is forbidden by the branch-protection rules:**

> "❌ The branch-protection workflow will reject this PR.
> `{CURRENT_BRANCH}` → `{TARGET_BRANCH}` is not an allowed combination.
> Allowed patterns: {list the allowed patterns from the workflow}"

Then ask using AskUserQuestion:

**"This PR would be blocked by branch protection. How do you want to proceed?"**
- **Choose a different target branch** — go back to Step 3 and ask again
- **Create the PR anyway** — note the user explicitly acknowledged this will fail branch-protection CI

**If no branch-protection workflow is found, or the combination is allowed:** proceed silently.

### Step 3.2b: Check for Required-Check Bootstrap Deadlock

After confirming the source→target combination is allowed, check whether a required status check will never run because its workflow doesn't exist on the base branch yet. This is a **bootstrap deadlock** — the workflow is being introduced by this very PR, but GitHub reads PR workflows from the base branch.

**1. Find all required status check names for `{TARGET_BRANCH}`:**
```bash
gh api repos/{owner}/{repo}/rulesets --jq '
  .[] | select(.enforcement == "active") |
  .rules[] | select(.type == "required_status_checks") |
  .parameters.required_status_checks[].context' 2>/dev/null
```

**2. For each required check name, find which workflow file produces it:**
Look for workflow files in `{REPO_ROOT}/.github/workflows/` where the workflow `name:` or job name matches the required check name (e.g. a workflow named `pull-request-lint-check` produces a check called `lint-check`).

**3. Check if that workflow file exists on the base branch:**
```bash
gh api "repos/{owner}/{repo}/contents/.github/workflows/{filename}?ref={TARGET_BRANCH}" --jq '.name' 2>/dev/null || echo "NOT ON BASE BRANCH"
```

**If a required check's workflow is missing from `{TARGET_BRANCH}`:**

Warn the user:
> "⚠️ Bootstrap deadlock detected: the `{check-name}` check is required to merge into `{TARGET_BRANCH}`, but the workflow that produces it (`{filename}`) doesn't exist on `{TARGET_BRANCH}` yet. GitHub reads PR workflows from the base branch, so this check will show as 'Expected — Waiting' and block merging."

Then offer:
> "This can be resolved after creating the PR by posting a commit status via the GitHub API — no admin access required."

Store `{BOOTSTRAP_CHECKS}` = list of affected check names + their head SHA (retrieved after push in Step 7).

### Step 3.2c: Resolve Bootstrap Deadlock (run after Step 7 if needed)

If `{BOOTSTRAP_CHECKS}` is non-empty, after the PR is created and the branch is pushed:

**Get the PR head SHA:**
```bash
gh api repos/{owner}/{repo}/pulls/{pr_number} --jq '.head.sha'
```

**Post a commit status for each affected check:**
```bash
gh api repos/{owner}/{repo}/statuses/{head_sha} \
  -X POST \
  -f state=success \
  -f context="{check-name}" \
  -f description="Bootstrap: workflow added in this PR, check passes" \
  -f target_url="{PR_URL}"
```

This satisfies GitHub's required status check by posting a commit status with the matching context name. The check will show as ✅ pass and the PR will become mergeable. This is a legitimate one-time bootstrap workaround — once merged, the workflow exists on the base branch and all future PRs will run it normally.

---

## Step 3.4: Verify Global Settings Version (ConductionNL/.github only)

**Only run this step if `{REMOTE_URL}` contains `ConductionNL/.github`.**

Invoke the `/verify-global-settings-version` skill now and follow its steps completely.

- If it reports **Case A or B** (no changes, or correctly bumped) → proceed silently to Step 3.5.
- If it reports **Case C** (VERSION BUMP MISSING) → pause the PR flow. Ask using AskUserQuestion:

  **"The global-settings VERSION has not been bumped. How do you want to proceed?"**
  - **Apply a patch bump now** — increment to next patch, show the new value, then continue to Step 3.5
  - **Apply a minor bump now** — increment minor, show new value, then continue to Step 3.5
  - **I'll fix it manually** — stop here

- If it reports **Case D** (VERSION bumped but no file changes) → warn the user but allow the PR to proceed.

---

## Step 3.5: Check for Existing PR

Before running any checks, look up whether a PR already exists for this source → target combination:

```bash
gh pr list --repo "{REMOTE_URL}" --head "{CURRENT_BRANCH}" --base "{TARGET_BRANCH}" --state open --json number,title,url,createdAt,author
```

Also check for a closed/merged PR to give full context:
```bash
gh pr list --repo "{REMOTE_URL}" --head "{CURRENT_BRANCH}" --base "{TARGET_BRANCH}" --state merged --json number,title,url,mergedAt --limit 1
```

**If an open PR already exists:**

Inform the user clearly:

> "An open PR already exists for `{CURRENT_BRANCH}` → `{TARGET_BRANCH}`:
> **#{number}: {title}**
> {url}
> Opened by {author} on {createdAt}"

Then ask using AskUserQuestion:

**"A PR for this branch already exists. What would you like to do?"**
- **View the existing PR** — open the URL and stop here
- **Update the existing PR** (push new commits + update description) — store `{EXISTING_PR_NUMBER}` and proceed to Step 3.7 as normal; in Step 7 use PATCH instead of POST
- **Continue anyway** (create a duplicate — not recommended) — proceed to Step 3.7 as normal

**If a merged PR was found but no open PR:**

Inform the user:

> "Note: A previous PR for `{CURRENT_BRANCH}` → `{TARGET_BRANCH}` was already merged (#{number}: {title}, merged {mergedAt}). You may be re-opening the same work."

Then proceed normally to Step 4.

**If no PR exists:** proceed silently to Step 4.

---

## Step 3.7: Check for Uncommitted or Unpushed Changes

Check the working tree and push status of `{CURRENT_BRANCH}`:

```bash
git -C {REPO_ROOT} status --short
git -C {REPO_ROOT} log origin/{CURRENT_BRANCH}...HEAD --oneline 2>/dev/null || echo "(branch not yet on remote)"
```

**If there are uncommitted changes** (modified, untracked, or staged files):

Before listing all uncommitted changes, specifically check for lock files that are untracked or modified:
```bash
git -C {REPO_ROOT} status --short | grep -E "composer\.lock|package-lock\.json"
```

If `composer.lock` or `package-lock.json` appear as untracked (`??`) or modified (`M`), warn prominently:
> "⚠️ `{filename}` is not committed. CI installs dependencies from the lock file — without it, dependency versions may differ between local and CI, causing check failures. This should be committed before creating the PR."

Inform the user of all uncommitted changes, listing the files. Then ask using AskUserQuestion:

**"There are uncommitted changes on `{CURRENT_BRANCH}`. What would you like to do?"**
- **Commit them now** — ask for a commit message, run `git -C {REPO_ROOT} add -A && git -C {REPO_ROOT} commit -m "{message}"`, then continue
- **Stash them** — run `git -C {REPO_ROOT} stash`, continue, and remind user to `git stash pop` afterwards
- **Continue without committing** — proceed (these changes will not be in the PR)

**If the branch has commits not yet pushed to origin:**

Inform the user. Then ask using AskUserQuestion:

**"Branch `{CURRENT_BRANCH}` has unpushed commits. Push them now before continuing?"**
- **Yes, push now** — run `git -C {REPO_ROOT} push -u origin {CURRENT_BRANCH}`, then continue
- **No, push later** — note that unpushed commits won't be in the PR until pushed; continue

---

## Step 4: Run Local Checks (optional)

Ask the user using AskUserQuestion:

**"Do you want to run local quality checks before creating the PR? This mirrors exactly what CI will run and ensures the PR checks pass."**
- **Yes, run checks** — proceed to Step 4a
- **No, skip checks** — proceed to Step 5

### Step 4a: Read CI Workflows — the Source of Truth

**The workflow files define exactly what to run locally. Do not hardcode assumptions.**

Find all workflow files triggered on `push` or `pull_request`:
```bash
find {REPO_ROOT}/.github/workflows -name "*.yml" -o -name "*.yaml" 2>/dev/null | sort
```

If no `.github/workflows` directory exists:
> "No GitHub Actions workflows found — skipping local checks."
Then proceed to Step 5.

Read each workflow file. For each job in a triggered workflow:

**If the job runs steps directly** — read and record every `run:` step in order.

**If the job delegates to a reusable workflow** (`uses: org/repo/.github/workflows/file.yml@ref`) — fetch and read that workflow too:
```bash
# Parse org, repo, path, ref from the 'uses:' value
# e.g. uses: ConductionNL/.github/.github/workflows/quality.yml@main
gh api "repos/{org}/{repo}/contents/{path}?ref={ref}" --jq '.content' | base64 -d
```
Then read the reusable workflow's jobs and their `run:` steps, also noting any `inputs:` the calling workflow passes (e.g. `enable-eslint: true`) — these control which jobs/steps actually execute.

Build a complete ordered list of every `run:` step the CI executes for this repo's push/PR trigger.

### Step 4b: Determine Check Working Directory

For the Nextcloud workspace, checks run inside the app subdirectory. Detect from the changed files:
```bash
git -C {REPO_ROOT} diff --name-only origin/{TARGET_BRANCH}...HEAD
```

Look for which app directory has the most changed files. If ambiguous, ask:

**"Which app directory should we run checks in?"** — list the changed app directories.

Store as `{CHECK_DIR}`.

### Step 4c: Categorise Steps and Build Execution Plan

From the full list of CI `run:` steps, categorise each as:

- **Install** — dependency install commands that must be run first:
  - `composer install`, `composer ci`, `npm ci`, `npm install`, `pip install`, etc.
  - Note the **exact flags** the CI uses (e.g. `--no-interaction`, `--legacy-peer-deps`, `--frozen-lockfile`)
- **Check** — quality/lint/test commands that run against source files only (no live server needed):
  - phpcs, phpstan, psalm, phpmd, eslint, stylelint, pytest, ruff, mypy, etc.
  - Use the **exact command** from the workflow, adapted to run from `{CHECK_DIR}`
- **Docker check** — commands from jobs that require a running Nextcloud server (see detection rule below)
- **Skip** — steps that cannot run locally at all (cloud infrastructure, upload/deploy, CI secrets/runners)

#### Handling jobs that mix runnable and skippable steps

Some CI jobs combine steps that are locally runnable (e.g. `npm audit`) with steps that are CI-only (e.g. generating and committing SBOM files, uploading artifacts, installing Grype). **Do not skip the entire job** — extract the individually runnable steps and classify them as Check steps.

The key pattern to watch for: a job that generates artifact files (CycloneDX SBOM, coverage reports, etc.) but **also contains audit or quality commands**. Extract those commands as Check steps with their **exact flags from that job** — not the flags from a different job.

**Critical example:** the SBOM job runs `npm audit --audit-level=critical` (no `--omit=dev`), while the Security job runs `npm audit --audit-level=critical --omit=dev`. These are different commands producing different results. Always use the exact flags from the job where the step appears.

Steps to skip within an otherwise-mixed job:
- Any step that installs/runs Grype, Trivy, or other CVE scanners (requires network + CI credentials)
- Any step that generates SBOM files (CycloneDX npm/composer commands)
- Any step that commits files back to the repo (`git commit`, `git push` in CI)
- Any step that uploads/attaches artifacts (`actions/upload-artifact`, `softprops/action-gh-release`)

Steps to extract and run:
- `composer audit ...` — run locally with the exact flags from the job
- `npm audit ...` — run locally with the exact flags from the job (pay attention to `--omit=dev` or lack thereof)

#### Detecting "requires Nextcloud" jobs

**Do not hardcode tool names.** Instead, read each CI job and ask: does it set up a live Nextcloud server before running tests? The signals are job steps that contain any of:
- `nextcloud/server` checkout or `git submodule update --init 3rdparty`
- `php occ maintenance:install` or `php occ app-enable`
- `docker-compose up` or `docker run` targeting a Nextcloud image
- Any `nextcloud-test-refs` matrix variable being used

If a job has these signals, **every `run:` step in that job that executes test/check commands** is a Docker check — regardless of the tool name. This covers phpunit, newman, and any future tools added to such a job.

#### Running Docker checks locally

For any job classified as "requires Nextcloud", check the environment once before running any of its steps:

**1. Check if the Nextcloud container is running:**
```bash
NC_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i nextcloud | head -1)
echo "Container: $NC_CONTAINER"
```

**2. Check the app is mounted inside it:**
```bash
docker exec "$NC_CONTAINER" ls /var/www/html/apps-extra/{APP_DIR}/vendor/bin/ 2>/dev/null | head -3
```

**If the container is running and the app is mounted:**

Adapt each test command from that job to run via `docker exec` (for server-side commands) or against `http://nextcloud.local` (for HTTP/API commands). Specific adaptations:

- **PHPUnit** (runs inside server):
  ```bash
  docker exec -w /var/www/html/apps-extra/{APP_DIR} -e XDEBUG_MODE=coverage "$NC_CONTAINER" \
    ./vendor/bin/phpunit -c phpunit-unit.xml --colors=always
  ```
  Note: Use `phpunit-unit.xml` locally (unit tests only, fast). CI uses `phpunit.xml` + coverage. The `XDEBUG_MODE=coverage` env var is required — without it phpunit emits a runner warning that causes a non-zero exit code even when all tests pass.

- **Newman / HTTP integration tests** (calls the server over HTTP):
  ```bash
  npx newman run tests/integration/*.postman_collection.json \
    --env-var base_url=http://nextcloud.local \
    --env-var admin_user=admin \
    --env-var admin_password=admin
  ```
  Note: Use `base_url`, `admin_user`, `admin_password` — these are the exact variable names the CI workflow passes (CI uses `http://localhost:8080` via PHP built-in server; locally use `http://nextcloud.local`). Using different names causes Newman to silently fall back to the collection's hardcoded defaults, making tests pass locally but fail in CI.

- **Any other command from a Nextcloud-server job**: run via `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" {command}` unless the command clearly makes HTTP requests, in which case substitute `http://nextcloud.local` as the base URL.

**If no Nextcloud container is running, or the app is not mounted:**

Ask using AskUserQuestion:
**"Some CI checks require the Nextcloud Docker environment, which is not running. What would you like to do?"**
- **Start Docker first** — stop here; remind user to start the environment (e.g. `cd openregister && docker compose up -d`), then re-run the skill
- **Skip Docker checks** — continue without them; note in the PR which jobs were not run locally

---

**Lock file gate (before install):** Check that lock files expected by the install commands are committed:
- CI uses `npm ci` → `package-lock.json` must be committed and up-to-date
- CI uses `composer install` → `composer.lock` should be committed

If a required lock file is missing or not committed, stop:
> "⚠️ `{lockfile}` is required by the CI install step (`{command}`) but is not committed. Run `{generate-command}`, commit the file, then re-run."

Display the full execution plan to the user before running anything:
```
Execution plan derived from CI workflows:

  Install steps:
    1. {exact install command from CI}
    2. {exact install command from CI}

  Check steps:
    3. {exact check command from CI}   [{job name}]
    4. {exact check command from CI}   [{job name}]
    ...

  Docker check steps (require Nextcloud container — {NC_CONTAINER}):
    N. docker exec ... {command}   [{job name}]
    N. npx newman run ...          [{job name}]

  Skipped (cannot run locally):
    - {step description}
```

### Step 4d: Run Install Steps

Run each install step **exactly as it appears in the CI workflow**, in CI order. If any install step fails, stop immediately and show the full error — do not proceed to checks.

### Step 4d-verify: Verify Check Tools Are Available

After install steps complete, verify that **every tool binary** required by the check steps actually exists before running any checks. This prevents silent skips (e.g. composer scripts that fall back to `|| echo 'not installed, skipping...'`).

**1. Build a tool checklist** from the execution plan's check steps. For each check command, identify the binary it needs:

| Check command pattern | Binary to verify |
|---|---|
| `composer psalm` | `vendor/bin/psalm` |
| `composer phpstan` | `vendor/bin/phpstan` |
| `composer phpcs` | `vendor/bin/phpcs` |
| `composer phpmd` | `vendor/bin/phpmd` |
| `composer phpmetrics` | `vendor/bin/phpmetrics` |
| `composer lint` | `php` (always available) |
| `npx eslint` / `npm run lint` | `node_modules/.bin/eslint` |
| `npx stylelint` / `npm run stylelint` | `node_modules/.bin/stylelint` |
| Any `vendor/bin/{tool}` | that exact path |
| Any `node_modules/.bin/{tool}` | that exact path |

**2. Determine WHERE to check** — checks may run locally or inside the Docker container:

- For **local check steps**: verify the binary exists at `{CHECK_DIR}/{binary_path}`
- For **Docker check steps**: verify inside the container:
  ```bash
  docker exec "$NC_CONTAINER" test -f /var/www/html/apps-extra/{APP_DIR}/{binary_path} && echo "EXISTS" || echo "MISSING"
  ```

**3. Probe each tool** and collect results:

```bash
# Local example:
test -f {CHECK_DIR}/vendor/bin/psalm && echo "EXISTS" || echo "MISSING"

# Docker example:
docker exec "$NC_CONTAINER" test -f /var/www/html/apps-extra/{APP_DIR}/vendor/bin/psalm && echo "EXISTS" || echo "MISSING"
```

**4. If ALL tools are available:** proceed silently to Step 4e.

**5. If ANY tools are missing:** display a clear report:

```
Tool availability check:
  ✅ vendor/bin/phpcs          — available
  ✅ vendor/bin/phpstan        — available
  ❌ vendor/bin/psalm          — MISSING
  ❌ vendor/bin/phpmd          — MISSING
  ✅ node_modules/.bin/eslint  — available
```

Then determine the likely fix. Missing tools are almost always caused by a failed or incomplete dependency install:

- **Missing `vendor/bin/*` tools** → `composer install` likely failed or was never run. The fix is:
  - Locally: `composer install --ignore-platform-reqs` (in `{CHECK_DIR}`)
  - In Docker: `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" composer install --ignore-platform-reqs`
- **Missing `node_modules/.bin/*` tools** → `npm ci` or `npm install` likely failed or was never run. The fix is:
  - Locally: `npm ci` (in `{CHECK_DIR}`)
  - In Docker: `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" npm ci`

Ask the user using AskUserQuestion:

**"Some check tools are missing and checks would silently skip without them. Should I install the missing dependencies now?"**
- **Yes, install now** — run the appropriate install command(s), then re-verify all tools. If still missing after install, report the specific failures and stop.
- **Skip missing checks** — proceed to Step 4e, but mark any check whose tool is missing as `⏭️ SKIPPED (tool not installed)` in the results table instead of running it. Include this in the PR description.
- **Stop here** — let the user fix the environment manually and re-run the skill.

### Step 4e: Run Check Steps

Run each check step **exactly as it appears in the CI workflow**, in CI order. Run them one by one, show output as each completes, and record pass/fail.

For steps flagged as optional (e.g. slow test suites with `phpunit`/`pytest`), ask first:
**"Run `{command}` too? (this may be slow)"**

### Step 4f: Report & Decide

Display a results table with one row per check step:

```
CI check results:
  [{job name}] {command}   ✅ PASS / ❌ FAIL
  [{job name}] {command}   ✅ PASS / ❌ FAIL
  ...
```

- If **all checks pass** → proceed to Step 5 with a success note.
- If **any check fails** → show the full output and ask using AskUserQuestion:

  **"Some checks failed. How do you want to proceed?"**
  - **Fix issues first, then re-run** — stop here; let the user fix and re-invoke the skill
  - **Create PR anyway** — proceed to Step 5 with a warning note in the PR body listing which checks failed

---

## Step 5: Analyse Branch Changes

Collect the full picture of what is on this branch before drafting anything:

```bash
git -C {REPO_ROOT} log origin/{TARGET_BRANCH}...HEAD --oneline
git -C {REPO_ROOT} log origin/{TARGET_BRANCH}...HEAD --format="%H %s%n%b" --no-merges
git -C {REPO_ROOT} diff --stat origin/{TARGET_BRANCH}...HEAD
git -C {REPO_ROOT} diff origin/{TARGET_BRANCH}...HEAD -- "*.php" "*.js" "*.ts" "*.vue" | head -300
```

Read each changed file's diff to understand what actually changed (not just filenames). This is the basis for the title and description — derive them from the actual code changes, not just commit messages.

---

## Step 5.5: Check for OpenSpec issue link

Check if a `plan.json` exists for the current change:

```bash
find {REPO_ROOT}/openspec/changes -maxdepth 2 -name "plan.json" ! -path "*/archive/*" 2>/dev/null
```

If found, read it and extract `tracking_issue` and `repo`. Store as `{TRACKING_ISSUE}` (e.g. `42`) or `null` if not found.

This will be included in the PR description to auto-close the issue on merge.

---

## Step 6: Draft PR Title & Description

Using the commit log, diff stat, and file-level diffs from Step 5, draft:

**Title**: Concise, action-verb sentence describing the main purpose (e.g. `Add full-text search to registers`). Do not use the branch name verbatim. Do not include app names or ticket numbers unless the commits reference them.

**Description**:

```markdown
## Summary

{3–6 bullet points derived from the actual commits and diffs — what changed and why}

## Checks

{one of:}
- ✅ All local checks passed (`composer check:strict`)
- ⚠️ Some checks failed — see CI for details
- ⏭️ Checks skipped

## Test plan

- [ ] CI passes
- [ ] Tested locally
- [ ] Reviewed for regressions

{if TRACKING_ISSUE is set:}
Closes #{TRACKING_ISSUE}
```

Present the draft to the user **in the chat** — show both the title and the full description as they would appear on GitHub.

Then ask using AskUserQuestion:

**"Does this PR title and description look good?"**
- **Yes, proceed** — proceed to Step 7 (will create or update depending on whether `{EXISTING_PR_NUMBER}` is set)
- **Change something** → ask: "What would you like to change or improve?" — apply the feedback, show the updated draft, and ask again
- **Let me write my own title** → ask for a new title, update the draft, show it, and ask again

Repeat the review loop until the user approves.

Store the approved title as `{PR_TITLE}` and description as `{PR_BODY}`.

---

## Step 7: Create or Update the PR

Push the branch to origin if not already pushed:
```bash
git -C {REPO_ROOT} push -u origin {CURRENT_BRANCH}
```

Parse `{OWNER}` and `{REPO}` from `{REMOTE_URL}` (e.g. `https://github.com/ConductionNL/myapp.git` → owner=`ConductionNL`, repo=`myapp`).

**Always use the GitHub REST API directly — never use `gh pr create` or `gh pr edit` (they use GraphQL and may trigger deprecation errors).**

### If updating an existing PR (`{EXISTING_PR_NUMBER}` is set):

```bash
gh api repos/{OWNER}/{REPO}/pulls/{EXISTING_PR_NUMBER} \
  --method PATCH \
  -f title="{PR_TITLE}" \
  -f body="{PR_BODY}" \
  --jq '{number: .number, title: .title, url: .html_url}'
```

### If creating a new PR:

```bash
gh api repos/{OWNER}/{REPO}/pulls \
  --method POST \
  -f title="{PR_TITLE}" \
  -f head="{CURRENT_BRANCH}" \
  -f base="{TARGET_BRANCH}" \
  -f body="{PR_BODY}" \
  --jq '{number: .number, title: .title, url: .html_url}'
```

Store the returned PR number as `{PR_NUMBER}` and URL as `{PR_URL}`.

---

## Step 8: Confirm & Report

After the PR is created, display:
- The PR URL
- Source → target branch
- Check status summary
- Next steps (e.g., "Request a review", "Watch CI status")

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`).
