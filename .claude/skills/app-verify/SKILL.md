---
name: app-verify
description: "Verify that a Nextcloud app's files match its openspec/app-config.json — read-only audit that reports drift between config and code"
metadata:
  category: Workflow
  tags: [workflow, app-lifecycle, verify, audit, drift]
---

# App Verify — Audit App Files Against Configuration

Read-only audit mode. Loads `openspec/app-config.json` and checks every tracked file against the configuration values. Reports what matches, what has drifted, and what is missing — without making any changes.

Use this after `/app-apply` to confirm changes landed correctly, or at any time to check whether an app has drifted from its declared configuration.

**Input**: Optional app ID or directory name after `/app-verify`. If omitted, **always ask** — never infer from conversation context.

---

## Step 0: Select App

**ALWAYS run this step.** Never assume the app based on conversation context, prior commands, or session history — even if an app was used in a previous `/app:*` command this session. Always ask explicitly.

Scan `apps-extra/` for directories containing an `openspec/app-config.json`:

```bash
find apps-extra -maxdepth 3 -name "app-config.json" | sort
```

Present the list and ask using AskUserQuestion. If an app was mentioned or used in a previous `/app:*` command earlier in this conversation, list it first with a **(used earlier this session)** annotation — but still require the user to explicitly confirm it.

**"Which app do you want to verify?"**

Store as `{APP_DIR}`.

---

## Step 1: Load Configuration

Read `apps-extra/{APP_DIR}/openspec/app-config.json`. Parse and store all values:

- `{APP_ID}` — id
- `{APP_NAME}` — name
- `{APP_NAMESPACE}` — namespace
- `{APP_SUMMARY}` — summary
- `{APP_GOAL}` — goal
- `{APP_CATEGORY}` — category
- `{APP_VERSION}` — version
- `{REQUIRES_OPENREGISTER}` — dependencies.requiresOpenRegister
- `{CI_ADDITIONAL_APPS}` — dependencies.additionalCiApps
- `{PHP_VERSIONS}` — cicd.phpVersions
- `{NC_REFS}` — cicd.nextcloudRefs
- `{ENABLE_NEWMAN}` — cicd.enableNewman

Derive name variants:
- `{APP_ID_SNAKE}` — replace `-` with `_` in `{APP_ID}`

---

## Step 2: Audit All Tracked Files

### OpenSpec config validation

Run `openspec validate --all` and capture the output. If validation fails, include the errors in the report as **WARNING** findings (invalid OpenSpec config does not break the app at runtime, but will cause workflow issues with `/opsx-*` commands).

### Tracked files and checks

Read every tracked file. For each check, assign a severity:

- **CRITICAL** — Identity mismatch (wrong app ID, namespace, or PHP class name). Will cause runtime errors or broken CI.
- **WARNING** — Metadata mismatch (wrong summary, category, goal text, URLs). Incorrect but not breaking.
- **INFO** — Minor or cosmetic drift (whitespace differences, optional fields). Note but do not flag as a problem.

**`appinfo/info.xml`**
- `<id>` matches `{APP_ID}` → **CRITICAL** if wrong
- `<namespace>` matches `{APP_NAMESPACE}` → **CRITICAL** if wrong
- Navigation `<route>` uses `{APP_ID_SNAKE}.dashboard.page` → **CRITICAL** if wrong
- `<name lang="en">` matches `{APP_NAME}` → **WARNING** if wrong
- `<summary lang="en">` matches `{APP_SUMMARY}` → **WARNING** if wrong
- `<description lang="en">` contains `{APP_GOAL}` as first paragraph → **WARNING** if missing
- `<version>` matches `{APP_VERSION}` → **WARNING** if wrong (config version bumped but info.xml not updated)
- `<category>` matches `{APP_CATEGORY}` → **WARNING** if wrong
- `<repository>`, `<bugs>`, `<website>`, `<discussion>` use correct GitHub URLs → **WARNING** if wrong
- `<repair-steps>` block with `InitializeSettings` for both `<install>` and `<post-migration>` is present when `{REQUIRES_OPENREGISTER}` is true → **WARNING** if absent; is absent when `{REQUIRES_OPENREGISTER}` is false → **WARNING** if present

**`.github/workflows/code-quality.yml`**
- `app-name` matches `{APP_ID}` → **CRITICAL** if wrong (CI will test the wrong app)
- `php-test-versions` matches `{PHP_VERSIONS}` → **WARNING** if wrong
- `nextcloud-test-refs` matches `{NC_REFS}` → **WARNING** if wrong
- `enable-newman` matches `{ENABLE_NEWMAN}` → **INFO** if wrong
- `additional-apps` present/absent matches `{CI_ADDITIONAL_APPS}` → **WARNING** if wrong

**`.github/workflows/release-beta.yml`**
- `app-name` matches `{APP_ID}` → **CRITICAL** if wrong

**`.github/workflows/release-stable.yml`**
- `app-name` matches `{APP_ID}` → **CRITICAL** if wrong

**`.github/workflows/issue-triage.yml`**
- `app-name` matches `{APP_ID}` → **WARNING** if wrong

**`.github/workflows/openspec-sync.yml`**
- `app-name` matches `{APP_ID}` → **WARNING** if wrong

**`composer.json`**
- `"name"` matches `conductionnl/{APP_ID}` → **WARNING** if wrong
- PSR-4 key matches `OCA\\{APP_NAMESPACE}\\` → **CRITICAL** if wrong

**`package.json`**
- `"name"` matches `{APP_ID}` → **WARNING** if wrong

**`webpack.config.js`**
- `appId` constant matches `{APP_ID}` → **WARNING** if wrong (frontend assets won't load)

**`lib/AppInfo/Application.php`**
- `APP_ID` constant matches `{APP_ID}` → **CRITICAL** if wrong
- Namespace declaration matches `OCA\{APP_NAMESPACE}\AppInfo` → **CRITICAL** if wrong
- `use OCA\{APP_NAMESPACE}\Repair\InitializeSettings` import is absent (repair step registration belongs in `info.xml`) → **WARNING** if present
- `$context->registerRepairStep(InitializeSettings::class)` call is absent (same reason) → **WARNING** if present

**`src/App.vue`**
- If `{REQUIRES_OPENREGISTER}` is `true`: OpenRegister gate (`v-if="!hasOpenRegisters"`) must be present → **WARNING** if absent
- If `{REQUIRES_OPENREGISTER}` is `false`: OpenRegister gate must be absent → **WARNING** if present
- No usage of deprecated `CnDetailViewLayout` in any `src/` file → **WARNING** if found (must migrate to `CnDetailPage`)

**`README.md`**
- Title contains `{APP_NAME}` — accepts both `# {APP_NAME}` (markdown) and `<h1 ...>{APP_NAME}</h1>` (HTML) → **WARNING** if the name is absent entirely
- Contains `{APP_GOAL}` text → **INFO** if missing

### Backend services and listeners

**`lib/Controller/SettingsController.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Controller` → **CRITICAL** if wrong
- Uses `OCA\{APP_NAMESPACE}\AppInfo\Application` → **CRITICAL** if wrong

**`lib/Service/SettingsService.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Service` → **CRITICAL** if wrong
- Config file path references `{APP_ID_SNAKE}_register.json` → **WARNING** if wrong
- When `{REQUIRES_OPENREGISTER}` is true: `importFromApp` call passes `data: $configData, version: $configVersion` in addition to `appId` and `force` → **WARNING** if using the bare `importFromApp(appId:..., force:...)` pattern (explicit file reading is required)

**`lib/Listener/DeepLinkRegistrationListener.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Listener` → **CRITICAL** if wrong
- `appId:` parameter matches `'{APP_ID}'` → **CRITICAL** if wrong (deep links will register under wrong app)

**`lib/Repair/InitializeSettings.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Repair` → **CRITICAL** if wrong
- Uses `OCA\{APP_NAMESPACE}\Service\SettingsService` → **CRITICAL** if wrong

**`lib/Settings/AdminSettings.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Settings` → **CRITICAL** if wrong

**`lib/Settings/{APP_ID_SNAKE}_register.json`**

> **Tool note:** Use `Read` directly with the known path `apps-extra/{APP_DIR}/lib/Settings/{APP_ID_SNAKE}_register.json`. Do NOT use Glob — it silently fails to find files in subdirectories when given an absolute `path` parameter. A "file does not exist" error from `Read` means the file is missing.

- File exists → **WARNING** if missing or named differently
- `"app"` field matches `{APP_ID}` → **WARNING** if wrong

**`src/main.js`**
- `loadTranslations('{APP_ID}', ...)` uses correct app ID → **WARNING** if wrong (translations won't load)

### Frontend components

**`src/navigation/MainMenu.vue`**
- Translation domain matches `t('{APP_ID}', ...)` → **WARNING** if wrong

**`src/views/settings/UserSettings.vue`**
- Translation domain matches `t('{APP_ID}', ...)` → **WARNING** if wrong
- Fetch URL contains `/apps/{APP_ID}/` → **WARNING** if wrong

### Support files

**`l10n/en.json`** and **`l10n/nl.json`**
- No references to `app-template` or `App Template` remain → **WARNING** if found

**`tests/unit/Controller/SettingsControllerTest.php`**
- Namespace matches `OCA\{APP_NAMESPACE}\Tests\Unit\Controller` → **CRITICAL** if wrong

**`openspec/config.yaml`**
- `Project:` contains `{APP_NAME}` → **WARNING** if wrong
- `Repo:` contains `{APP_ID}` → **WARNING** if wrong

**`project.md`**
- Title contains `{APP_NAME}` → **INFO** if wrong

**`CHANGELOG.md`**
- Most recent version entry matches `{APP_VERSION}` from `app-config.json` → **WARNING** if wrong (version was bumped in config but changelog not updated)
- File exists → **INFO** if missing (should document release history)

---

## Step 3: Report Findings

Present a structured audit report:

```
App Verify — apps-extra/{APP_DIR}
Config: openspec/app-config.json (updated: {updatedAt})

CRITICAL  (must fix — will break the app or CI)
  ✗ lib/AppInfo/Application.php
      APP_ID constant: "old-app" → expected "my-app"
  ✗ .github/workflows/release-beta.yml
      app-name: "old-app" → expected "my-app"

WARNING  (should fix — incorrect metadata or behaviour)
  ✗ appinfo/info.xml
      <summary>: "Old summary text" → expected "New summary text"
      <category>: "tools" → expected "organization"
  ✗ .github/workflows/code-quality.yml
      nextcloud-test-refs: '["stable30"]' → expected '["stable31","stable32"]'

INFO  (minor drift — consider fixing)
  ✗ README.md
      Goal text not found in current content

PASSING  (14 checks)
  ✓ appinfo/info.xml — <id>, <namespace>, <route>
  ✓ composer.json — name, PSR-4 namespace
  ✓ package.json — name
  ✓ webpack.config.js — appId
  ✓ src/App.vue — OpenRegister gate
  ✓ .github/workflows/openspec-sync.yml — app-name
  ✓ .github/workflows/issue-triage.yml — app-name
  ✓ .github/workflows/release-stable.yml — app-name

Summary: 2 CRITICAL · 3 WARNING · 1 INFO · 14 passing
```

If everything passes:

```
App Verify — apps-extra/{APP_DIR}
Config: openspec/app-config.json (updated: {updatedAt})

All checks passed (20/20)
  ✓ appinfo/info.xml
  ✓ lib/AppInfo/Application.php
  ✓ composer.json
  ✓ package.json
  ✓ webpack.config.js
  ✓ src/App.vue
  ✓ README.md
  ✓ .github/workflows/* (6 files)

apps-extra/{APP_DIR} is in sync with openspec/app-config.json.
```

---

## Step 4: Recommend Next Action

Based on findings, close with a clear recommendation:

- **If CRITICAL issues exist:**
  > "Run `/app-apply {APP_ID}` to fix these issues — CRITICAL drift will cause broken CI or runtime errors."

- **If only WARNING/INFO issues exist:**
  > "Run `/app-apply {APP_ID}` to clean up the remaining drift, or continue with `/app-explore {APP_ID}` to evolve the config further."

- **If everything passes:**
  > "No action needed. Continue with `/app-explore {APP_ID}` to evolve the config, or start implementing features from `openspec/specs/`."

---

## Guardrails

- **Read-only** — never modify any file, only report
- **Be precise** — show exact current value vs expected value for every failing check
- **No false positives** — read files carefully before flagging drift; whitespace and formatting differences that don't affect behaviour are INFO at most
- **Severity matters** — distinguish CRITICAL (broken) from WARNING (wrong) from INFO (cosmetic)
- **Use Read for known paths** — when a tracked file has a known path (e.g. `lib/Settings/{APP_ID_SNAKE}_register.json`), use the `Read` tool directly with the full absolute path. Do NOT use `Glob` to discover these files — the Glob tool silently returns no results when given subdirectory patterns with an absolute `path` parameter. A "file does not exist" error from `Read` means the file is genuinely missing.
