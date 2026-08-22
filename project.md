# Decidiq

## Overview

Decidiq is a universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings. It manages meetings, agendas, motions, amendments, voting, minutes, and decision tracking with configurable workflows per organization type.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (all data stored as register objects)
- **Pattern**: Thin client — Decidiq provides UI/UX, OpenRegister handles persistence
- **License**: EUPL-1.2

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud AppFramework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Data | OpenRegister (JSON object store) |
| Testing | PHPUnit (unit + integration), Newman (API) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Key Files

| File | Purpose |
|------|---------|
| `lib/AppInfo/Application.php` | App bootstrap, listener + repair registration |
| `lib/Controller/SettingsController.php` | Settings API endpoints |
| `lib/Service/SettingsService.php` | Settings business logic, OpenRegister integration |
| `lib/Listener/DeepLinkRegistrationListener.php` | Registers deep link patterns with OpenRegister search |
| `lib/Repair/InitializeSettings.php` | Import register on install/upgrade |
| `lib/Settings/decidesk_register.json` | OpenAPI 3.0 register schema definition |
| `src/App.vue` | App shell (navigation + routing) |
| `src/navigation/MainMenu.vue` | App navigation sidebar |
| `src/views/settings/UserSettings.vue` | User settings dialog |
| `openspec/config.yaml` | OpenSpec project configuration |

## Development Setup

See the workspace-level `.claude/docs/` for:
- `commands.md` — available Claude commands
- `testing.md` — testing workflows
- `app-lifecycle.md` — full development lifecycle

## Standards

This app follows all [Conduction app standards](../.claude/openspec/architecture/).
