#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Decidesk Contributors
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Decidesk's OpenRegister register + schemas on a freshly installed
# Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/decidesk/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable decidesk` runs a post-migration repair step which is supposed
# to import `lib/Settings/decidesk_register.json` (plus the 24 fragment
# overlays in `lib/Settings/register.d/`) into OpenRegister. Two things make
# that unreliable as the sole fresh-install path, and BOTH fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import is denied outright with
#      "User 'Anonymous' does not have permission to 'create' objects in schema
#      '…'". The repair step catches `\Throwable` and downgrades it to a
#      warning, so `occ app:enable decidesk` still exits 0.
#   2. The repair path calls `loadConfiguration(force: false)`. The non-forced
#      path is version-guarded: it can advance the recorded configuration
#      version WITHOUT applying the register, so a second run then sees
#      "already current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the register simply is
# not there. The suite's failure mode in that state is every UI spec timing out
# on an empty list and every `expect(resp.ok()).toBe(true)` against
# `/apps/openregister/api/objects/decidesk/<schema>` failing — messages that
# accuse the selectors, not the missing import.
#
# So this script does the import EXPLICITLY over the admin HTTP API (which has
# a real session and passes RBAC), forced, and then VERIFIES the register and
# schemas actually exist. A failed provision becomes ONE loud step failure here
# instead of two dozen misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side and re-running only
# re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD / NC_ADMIN_USER / NC_ADMIN_PASS.
# Accept all of them, and fall back to the CI runner's own
# `php -S 0.0.0.0:8080` only when actually running on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently import a register into
# somebody else's environment. Off CI, an unset target is a hard error.
# Mirrors tests/e2e/base-url.ts.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

# ── 0. Make Nextcloud emit the pretty URLs the specs navigate to ─────────────
# decidesk's SPA router is `createWebHistory(generateUrl('/apps/decidesk'))`
# (src/main.js). The JS `generateUrl` prefixes `/index.php` unless
# `OC.config.modRewriteWorking` is true, and that flag is derived from the
# `htaccess.IgnoreFrontController` system config — which `occ
# maintenance:install` leaves at its default of FALSE.
#
# So on an unconfigured CI instance the router's base is
# `/index.php/apps/decidesk`, while every spec navigates to
# `${BASE}/apps/decidesk/...`. vue-router 4 only strips a base that the current
# path actually starts with, so the resolved path stays `/apps/decidesk/...`,
# matches NO route, and the view never mounts. The failure surfaces as a
# selector timeout naming an element — i.e. it reads like a broken component,
# not like a misconfigured base.
#
# The workflow's `ci-router.php` front controller already serves pretty URLs
# (the job asserts `/apps/<app>/` does not 404), so telling Nextcloud the front
# controller is active is accurate here, not a workaround.
#
# Only attempted when `occ` is actually next to us — this step's cwd is the
# Nextcloud server root.
if [ -f ./occ ]; then
	php ./occ config:system:set htaccess.IgnoreFrontController --value=true --type=boolean >/dev/null 2>&1 || true

	# Read it BACK and gate on it. `occ config:system:set` can fail silently
	# (read-only config, a config partition it will not write), and an
	# unverified set is exactly the shape of a check whose absence looks like
	# its success.
	#
	# ⚠️ occ prints unrelated notices to STDOUT ahead of the value on some
	# instances, so take the LAST non-empty line rather than the whole output.
	FC_VALUE="$(php ./occ config:system:get htaccess.IgnoreFrontController 2>/dev/null \
		| grep -v '^[[:space:]]*$' | tail -1 | tr -d '[:space:]' || true)"
	echo "[ci-seed] htaccess.IgnoreFrontController -> '${FC_VALUE}'"
	if [ "$FC_VALUE" != "true" ] && [ "$FC_VALUE" != "1" ]; then
		echo "::error::htaccess.IgnoreFrontController is '${FC_VALUE}', not true."
		echo "::error::generateUrl() will emit /index.php/apps/decidesk as the vue-router base while"
		echo "::error::the specs navigate to /apps/decidesk/... — no route matches and every UI spec"
		echo "::error::fails on a selector timeout that names an element rather than the real cause."
		exit 1
	fi
else
	echo "[ci-seed] no ./occ in $(pwd) — skipping the front-controller config (not a server root?)."
fi

# ── 1. Import the Decidesk configuration ─────────────────────────────────────
# Decidesk's `appinfo/routes.php` returns
# `\OCA\OpenRegister\AppHost\Routes::standard([...])`, whose canonical table
# ships `settings#load` at POST /api/settings/load. On decidesk that name
# resolves to OCA\Decidesk\Controller\SettingsController::load(), which calls
# `loadConfiguration(force: true)` — precisely the forced import the repair step
# cannot perform, and the only entry point that merges the register.d/ fragment
# overlays on top of the base register.
#
# It is admin-only (`#[AuthorizedAdminSetting(AdminSettings::class)]`), so HTTP
# Basic as admin is required.
#
# `OCS-APIRequest: true` is load-bearing, not decoration: the method carries no
# #[NoCSRFRequired], and Nextcloud's Request::passesCSRFCheck() short-circuits
# to true on that header (the strict-cookie precondition is satisfied because a
# Basic-auth request carries no session cookie at all). Without the header this
# POST is rejected as a CSRF failure.
IMPORT_URL="${BASE}/index.php/apps/decidesk/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL} (forced import)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] settings#load HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

# HTTP 200 is necessary but NOT sufficient: SettingsController::load() returns
# `{"success": false, "message": "..."}` with a 200 when the import itself
# failed (OpenRegister missing, register JSON unreadable, RBAC denial). Treat
# anything that is not an explicit success as a reason to try the generic
# importer below, and let the verification step decide the outcome.
IMPORT_OK=0
if [ "$IMPORT_CODE" = "200" ] && grep -q '"success":[[:space:]]*true' "$IMPORT_BODY"; then
	IMPORT_OK=1
	echo "[ci-seed] decidesk settings#load reported success."
else
	echo "[ci-seed] decidesk settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of decidesk's own controller wiring, so it still provisions the
# register if `settings#load` is unavailable (e.g. an OpenRegister build whose
# AppHost route table predates `settings#load`) or if the decidesk
# SettingsService rejects the file. Admin-only. It reads the upload under the
# literal form key `file`; a raw JSON request body is NOT one of its accepted
# shapes. `force` is compared `=== 'true' || === true` there, so the
# form-encoded string is fine.
#
# ⚠️ This fallback imports ONLY the base register — it cannot merge the
# register.d/ fragments, which is decidesk-specific logic living in
# SettingsService::mergeRegisterFragments(). It is a floor, not a substitute:
# the verification below still has to pass.
if [ "$IMPORT_OK" != "1" ]; then
	REGISTER_JSON="${APP_DIR}/lib/Settings/decidesk_register.json"
	if [ ! -f "$REGISTER_JSON" ]; then
		echo "::error::decidesk_register.json not found at ${REGISTER_JSON}."
		exit 1
	fi

	OR_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${OR_URL} (file=decidesk_register.json, force=true)"
	OR_BODY="$(mktemp)"
	OR_CODE="$(
		curl -sS -o "$OR_BODY" -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST \
			-H 'OCS-APIRequest: true' \
			-F "file=@${REGISTER_JSON}" \
			-F 'force=true' \
			-F 'appId=decidesk' \
			"$OR_URL" || echo 000
	)"
	echo "[ci-seed] configurations/import HTTP ${OR_CODE}"
	head -c 2000 "$OR_BODY"; echo
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# An import reporting success is not the same as the register existing. Verify
# against OpenRegister directly, using the same slugs the specs resolve by
# (they build object URLs as
# /index.php/apps/openregister/api/objects/decidesk/<schema>).
#
# ⚠️ The required slugs below are READ OUT OF lib/Settings/decidesk_register.json
# verbatim (`components.schemas.<Name>.slug`), NOT mechanically kebab-cased from
# the schema names. OpenRegister resolves a URL segment via LOWER(slug), so a
# structural mismatch (hyphen vs underscore, a prefix) is a 404 — and the ones
# in this register are not all what a naive transform would produce.
#
# The HTTP status is captured and checked separately from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if
# you only look at the parsed list. A wrong lookup manufactures an absence for
# free, so the two are reported as different errors.
verify() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
required = {
    # x-openregister.app in decidesk_register.json.
    'registers': ['decidesk'],
    # Verbatim `slug` values from components.schemas in
    # lib/Settings/decidesk_register.json — the ones the e2e specs address.
    'schemas': [
        'meeting', 'agenda-item', 'decision', 'action-item', 'governance-body',
        'participant', 'person', 'minutes', 'voting-round', 'vote',
        'transcript', 'public-consultation', 'consultation-reaction',
        'participatory-budget', 'budget-proposal', 'board-evaluation',
        'publication-record', 'engagement-record',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the '
          f'slug list below proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present: {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::Decidesk {kind} missing after import: {missing}')
    print('::error::The e2e suite cannot address meetings, decisions, agendas or '
          'voting rounds without them; every UI spec would fail on an empty list.')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
REG_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$REG_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" || echo 000)"
verify "$REG_BODY" registers "$REG_CODE"

SCH_BODY="$(mktemp)"
SCH_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$SCH_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" || echo 000)"
verify "$SCH_BODY" schemas "$SCH_CODE"

# The register existing is still not the same as it being READABLE by the admin
# session the specs use. Several specs assert `expect(resp.ok()).toBe(true)` on
# exactly this URL shape; probe it here so that failure mode has a name at the
# seed step rather than surfacing as a dozen unexplained assertion failures.
for slug in meeting decision governance-body participant; do
	OBJ_CODE="$(curl -sS -o /dev/null -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/decidesk/${slug}?_limit=1" || echo 000)"
	echo "[ci-seed] objects/decidesk/${slug} probe -> ${OBJ_CODE}"
	if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
		echo "::error::The decidesk ${slug} collection is not readable (HTTP ${OBJ_CODE})."
		echo "::error::Specs that assert resp.ok() on this URL would fail with no indication the cause is provisioning."
		exit 1
	fi
done

echo "[ci-seed] Decidesk register + schemas provisioned."

# ── 3. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and that cost lands entirely
# on whichever spec happens to run first. Warming it here puts that cost in the
# environment-preparation step where it belongs, rather than inside an assertion
# timeout that would then have to keep drifting upward.
#
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and below.
for path in \
	"/index.php/apps/decidesk/" \
	"/index.php/apps/decidesk/api/health" \
	"/index.php/settings/admin/decidesk" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/decidesk/js/…` on the CI runner,
# `/custom_apps/decidesk/js/…` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/decidesk/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*decidesk-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

BUNDLE_BYTES=0
if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
	# Third field of the -w format. Default to 0 on anything unparseable so the
	# floor below treats "I could not measure it" as "it is too small", not as
	# a pass.
	BUNDLE_BYTES="$(printf '%s' "$BUNDLE_INFO" | awk '{print $3+0}')"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# The floor exists because the content-type check ALONE cannot fail on the
# case it was written for.
#
# `truncate -s 0 js/decidesk-main.js` leaves a file that Nextcloud still serves
# as **HTTP 200 with Content-Type application/javascript** — length zero. So a
# `case "$BUNDLE_INFO" in *javascript*)` gate passes a bundle that contains no
# application at all, and every UI spec then fails on a selector timeout whose
# message names a component. The truncation control run recorded on #378
# (30887496886) did not catch this either: it truncated the bundle AFTER the
# gate had already run, so the gate itself was never shown capable of failing.
#
# 1 MB is deliberately far below the measured 14,191,177 bytes (run
# 31040165156) — it is a floor against emptiness and gross truncation, not a
# size ratchet that would go red on a legitimate bundle-size change.
BUNDLE_MIN_BYTES=1000000

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# Note that this gate reads the SERVED response, not the file on disk, and it
# is placed at the very end so that a run which reaches the specs has provably
# been able to fetch real JavaScript for the SPA. (A file-on-disk check is also
# defeated by global-setup.ts's ensureBundleBuilt(), which does an
# fs.existsSync() and silently rebuilds — so a *truncated* bundle passes that
# check while serving zero bytes of app.)
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle content-type OK (JavaScript)."
			;;
		*)
			echo "::error::The Decidesk frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac

	if [ "$BUNDLE_BYTES" -lt "$BUNDLE_MIN_BYTES" ]; then
		echo "::error::The Decidesk frontend bundle served only ${BUNDLE_BYTES} bytes (floor: ${BUNDLE_MIN_BYTES})."
		echo "::error::A truncated bundle is served as HTTP 200 application/javascript, so the content-type check above CANNOT catch it — this floor is the check that can."
		echo "::error::The SPA will not mount and every UI spec would fail on a selector timeout naming a component rather than the bundle."
		exit 1
	fi
	echo "[ci-seed] bundle size OK (${BUNDLE_BYTES} bytes >= ${BUNDLE_MIN_BYTES})."
fi

echo "[ci-seed] done."
