---
name: app-apply
description: "Apply openspec/app-config.json changes to the actual Nextcloud app files — applies configuration decisions made in app-explore back into the codebase"
metadata:
  category: Workflow
  tags: [workflow, app-lifecycle, apply, sync, configuration]
---

# App Apply — Apply Configuration to App Files

Reads `openspec/app-config.json` and compares it against the current state of the app files. Shows a diff of what would change and applies approved changes.

This is the counterpart to `/app-explore`. Explore changes the config; apply pushes the config into code.

**Scope:** App Apply is **configuration and scaffold only**. It syncs app identity, metadata, CI settings, and basic structural files — not feature code. Specifically:

✅ **In scope:** `appinfo/info.xml`, CI/CD workflow parameters, PHP namespaces and app ID constants, `composer.json`/`package.json` names, `webpack.config.js` app ID, `src/App.vue` OpenRegister gate, `lib/AppInfo/Application.php` repair step wiring, `lib/Service/SettingsService.php` OpenRegister config loading, `README.md` header and goal sections.

❌ **Out of scope:** Feature implementation, business logic, Vue components, PHP controllers, OpenRegister schemas. Those belong in OpenSpec changes — use `/opsx-ff {feature-name}` to implement planned features.

**Input**: Optional app ID or directory name after `/app-apply`. If omitted, **always ask** — never infer from conversation context.

---

## Step 0: Select App

**ALWAYS run this step.** Never assume the app based on conversation context, prior commands, or session history — even if an app was used in a previous `/app:*` command this session. Always ask explicitly.

Scan `apps-extra/` for directories containing an `openspec/app-config.json`:

```bash
find apps-extra -maxdepth 3 -name "app-config.json" | sort
```

Present the list and ask using AskUserQuestion. If an app was mentioned or used in a previous `/app:*` command earlier in this conversation, list it first with a **(used earlier this session)** annotation — but still require the user to explicitly confirm it.

**"Which app do you want to apply config changes to?"**

Store as `{APP_DIR}`.

---

## Step 0b: Check for Prior app-verify Output

Before loading config, scan the current conversation history for any output from `/app-verify` (or `app-verify`) for the selected app.

**If a prior app-verify was found:**

Extract the issues it reported — drift items, mismatches, missing files, or anything flagged as incorrect. Hold these as `{VERIFY_FINDINGS}`.

Display a notice to the user:

```
ℹ️  app-verify was run earlier in this conversation for {APP_DIR}.
    It flagged the following issues that app-apply will address:

    • {finding 1}
    • {finding 2}
    ...

    These will be included in the change summary below. You will still be asked to approve each change.
```

These findings will be cross-referenced in Step 2 — any drift item reported by app-verify is treated as a confirmed pending change, even if the file comparison in Step 2 would otherwise have missed it (e.g. subtle formatting differences or values that are technically present but structurally wrong).

**If no prior app-verify output was found in conversation history:**

Continue silently — no notice needed.

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

Derive name variants if not already in config:
- `{APP_ID_SNAKE}` — replace `-` with `_` in `{APP_ID}`

---

## Step 2: Compare Configuration Against App Files

Read each tracked file and check whether the current values match the config. Build a list of **pending changes**.

If `{VERIFY_FINDINGS}` were captured in Step 0b, merge them into the pending changes list — treat each finding as a confirmed change even if the raw file comparison looks clean. Do not deduplicate silently: if verify flagged something the file comparison also catches, surface it once with a `(also flagged by app-verify)` annotation.

### Tracked files and what to check

**`appinfo/info.xml`**
- `<id>` matches `{APP_ID}`
- `<name lang="en">` matches `{APP_NAME}`
- `<summary lang="en">` matches `{APP_SUMMARY}`
- `<description lang="en">` contains `{APP_GOAL}` (first paragraph)
- `<namespace>` matches `{APP_NAMESPACE}`
- `<category>` matches `{APP_CATEGORY}`
- Navigation `<route>` uses correct app ID
- `<repository>`, `<bugs>`, `<website>`, `<discussion>` use correct GitHub URLs
- `<repair-steps>` block with `InitializeSettings` for both `<install>` and `<post-migration>` is present when `{REQUIRES_OPENREGISTER}` is true; absent when false

**`.github/workflows/code-quality.yml`**
- `app-name` matches `{APP_ID}`
- `additional-apps` matches `{CI_ADDITIONAL_APPS}` (empty array = line removed)
- `php-version` matches first entry in `{PHP_VERSIONS}`
- `php-test-versions` matches `{PHP_VERSIONS}`
- `nextcloud-test-refs` matches `{NC_REFS}`
- `enable-newman` matches `{ENABLE_NEWMAN}`

**`.github/workflows/release-beta.yml`**
- `app-name` matches `{APP_ID}`

**`.github/workflows/release-stable.yml`**
- `app-name` matches `{APP_ID}`

**`.github/workflows/issue-triage.yml`**
- `app-name` matches `{APP_ID}`

**`.github/workflows/openspec-sync.yml`**
- `app-name` matches `{APP_ID}`

**`composer.json`**
- `"name"` matches `conductionnl/{APP_ID}`
- PSR-4 namespace matches `OCA\\{APP_NAMESPACE}\\`

**`package.json`**
- `"name"` matches `{APP_ID}`

**`webpack.config.js`**
- `appId` constant matches `{APP_ID}`

**`lib/AppInfo/Application.php`**
- `APP_ID` constant matches `{APP_ID}`
- Namespace matches `OCA\{APP_NAMESPACE}\AppInfo`
- `use OCA\{APP_NAMESPACE}\Repair\InitializeSettings` import is absent — repair step registration is handled declaratively via `info.xml`, not PHP
- `$context->registerRepairStep(InitializeSettings::class)` call is absent (same reason)

**`lib/Service/SettingsService.php`** *(only when `{REQUIRES_OPENREGISTER}` is true)*
- `loadConfiguration()` reads `{APP_ID_SNAKE}_register.json` explicitly before calling `importFromApp` — includes file-exists, file_get_contents, and json_decode error guards
- `importFromApp` call passes `data: $configData, version: $configVersion` in addition to `appId` and `force`

**`src/App.vue`**
- OpenRegister gate present/absent based on `{REQUIRES_OPENREGISTER}`

**`README.md`**
- Title matches `{APP_NAME}`
- Goal section contains `{APP_GOAL}`

---

## Step 3: Present Change Summary

Display a clear summary of all pending changes found:

```
Changes pending for apps-extra/{APP_DIR}:

  appinfo/info.xml
    • <summary> "Old summary" → "{APP_SUMMARY}"
    • <category> "tools" → "{APP_CATEGORY}"

  .github/workflows/code-quality.yml
    • additional-apps — remove (OpenRegister not required)

  README.md
    • Goal section — update with current app goal

  No changes needed:
    • composer.json ✓
    • package.json ✓
    • webpack.config.js ✓
    • lib/AppInfo/Application.php ✓
```

If there are no pending changes, report:

```
apps-extra/{APP_DIR} is already in sync with openspec/app-config.json.
No changes needed.
```

And stop here.

---

## Step 4: Confirm Before Applying

Ask the user using AskUserQuestion:

**"Apply all {N} changes? Or select specific files to update?"**
- **Apply all** → proceed with all changes
- **Select files** → list files and let the user pick which ones to apply
- **Cancel** → stop without changes

---

## Step 5: Apply Approved Changes

For each approved file, read the file first, then apply changes using the Edit tool. **Never use sed or awk.**

After all files are updated, update `openspec/app-config.json`:
- Set `"updatedAt"` to today's date

---

## Step 6: Run Quality Check (Optional)

Ask the user using AskUserQuestion:

**"Run `composer check:strict` to verify the PHP changes are clean?"**

If yes:
```bash
cd apps-extra/{APP_DIR} && composer check:strict
```

Report any failures and offer to fix them before the user commits.

---

## Step 7: Report

```
Apply complete for apps-extra/{APP_DIR}

Applied changes:
  ✓ {file1} — {what changed}
  ✓ {file2} — {what changed}

Skipped:
  - {file3} (no change needed)

openspec/app-config.json → updatedAt set to {TODAY}

Next steps:
  • Commit: cd apps-extra/{APP_DIR} && git add -A && git commit -m "Apply app config changes"
  • Verify: /app-verify {APP_ID}
  • Continue exploring: /app-explore {APP_ID}
  • Start implementing: feature specs are in openspec/specs/ — use /opsx-ff {feature-name} to build
```

---

## Guardrails

- **Never apply without confirmation** — always show the diff summary first
- **Never overwrite custom code** — only update the specific tracked values (names, IDs, metadata), not logic
- **Never implement features** — if the user asks to add feature code, direct them to `/opsx-ff {feature-name}` instead
- **Never use sed/awk** — use the Edit tool only
- **Read before editing** — always read the current file content before making changes
- **Preserve manual changes** — if a file has custom content beyond what the template provides, preserve it
- **Scope reminder** — if a user asks to make a change that is out of scope (e.g., "add a new settings field", "create an API endpoint"), explain the scope and suggest the right tool (`/app-explore` to define it, `/opsx-ff` to implement it)
