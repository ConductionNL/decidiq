#!/usr/bin/env bash
#
# Decidesk Newman aggregate runner.
#
# Runs every Postman/Newman collection in tests/integration/ against a live
# Nextcloud instance serving the decidesk app. Each collection is self-contained
# and idempotent (it seeds + tears down its own OpenRegister objects), so the
# order they run in does not matter.
#
# Collections covered:
#   - decidesk.postman_collection.json       (council/local-government surface:
#                                             meeting + motion + voting-round +
#                                             decision + settings)
#   - board-portal.postman_collection.json   (enterprise board-portal surface:
#                                             Board / BoardMember / BoardMeeting /
#                                             Resolution / BoardVote / RegulatorExport /
#                                             MultilingualReconciliation — Phase 9 of
#                                             openspec/changes/board-meeting-resolutions)
#
# Usage:
#   ./run-all.sh                                  # defaults: localhost:8080 admin/admin
#   BASE_URL=http://localhost:8080 ./run-all.sh
#   ADMIN_USER=admin ADMIN_PASS=admin ./run-all.sh
#
# Uses a globally-installed `newman` if present, otherwise falls back to
# `npx newman`. Runs are serialised via flock (when available) so concurrent
# CI agents do not trip the Nextcloud brute-force protection.
#
# Exits non-zero on the first collection failure (and continues no further).
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-decidesk.lock"
if [ "${DECIDESK_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export DECIDESK_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
INTEGRATION_DIR="${REPO_ROOT}/tests/integration"

BASE_URL="${BASE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

# Authenticated requests use baseUrl; the authorization (no-auth) tests use a
# DIFFERENT host alias so the session cookie that authenticated requests
# establish (host-scoped) is never sent to them.
if [ -n "${NO_AUTH_BASE:-}" ]; then
  NOAUTH_BASE="${NO_AUTH_BASE}"
elif [[ "${BASE_URL}" == *"localhost"* ]]; then
  NOAUTH_BASE="${BASE_URL/localhost/127.0.0.1}"
else
  NOAUTH_BASE="${BASE_URL/127.0.0.1/localhost}"
fi

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

COLLECTIONS=(
  "${INTEGRATION_DIR}/decidesk.postman_collection.json"
  "${INTEGRATION_DIR}/decidesk-lifecycle.postman_collection.json"
  "${INTEGRATION_DIR}/decidesk-minutes.postman_collection.json"
  "${INTEGRATION_DIR}/decidesk-user-settings.postman_collection.json"
  "${INTEGRATION_DIR}/decidesk-voting-rules.postman_collection.json"
  "${INTEGRATION_DIR}/decidesk-admin-settings.postman_collection.json"
  "${INTEGRATION_DIR}/board-portal.postman_collection.json"
)

OVERALL_RC=0
for COLLECTION in "${COLLECTIONS[@]}"; do
  if [ ! -f "${COLLECTION}" ]; then
    printf 'run-all.sh: skipping missing collection %s\n' "${COLLECTION}" >&2
    continue
  fi

  printf '\n=== Running %s ===\n' "$(basename "${COLLECTION}")"

  if ! "${NEWMAN[@]}" run "${COLLECTION}" \
    --env-var "baseUrl=${BASE_URL}" \
    --env-var "noAuthBase=${NOAUTH_BASE}" \
    --env-var "adminUser=${ADMIN_USER}" \
    --env-var "adminPass=${ADMIN_PASS}" \
    --ignore-redirects \
    --reporters cli \
    --color on \
    "$@"
  then
    OVERALL_RC=1
    printf '\n!!! Collection failed: %s\n' "$(basename "${COLLECTION}")" >&2
    # Continue running the remaining collections so a single broken contract
    # does not hide regressions in unrelated families.
  fi
done

exit "${OVERALL_RC}"
