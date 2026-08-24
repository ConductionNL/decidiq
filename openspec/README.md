# OpenSpec — Specifications & Architecture

This folder contains feature specifications, architectural decisions, and implementation specs for this app.

## Structure

| File / Folder | Purpose |
|---|---|
| `app-config.json` | App identity, configuration, and tracked decisions — written by `/opsx:app-explore` |
| `config.yaml` | OpenSpec CLI project configuration — context and rules |
| `specs/` | Feature specs — what the app should do (input for OpenSpec changes) |
| `architecture/` | App-specific Architectural Decision Records (ADRs) |
| `changes/` | Individual change directories, each with a full set of specification artifacts (created on first change) |

> If `app-config.json` has `"requiresOpenRegister": true`, install [OpenRegister](https://github.com/ConductionNL/openregister) before enabling this app. Set to `false` if your app does not use OpenRegister.

## Artifact Progression

Each change in `changes/` moves through these artifacts:

```
proposal.md ──► specs/ ──► design.md ──► tasks.md ──► plan.json
                                                        │
                                                        ▼
                                                  GitHub Issues
                                                        │
                                                        ▼
                                                  implementation
                                                        │
                                                        ▼
                                                  review.md
                                                        │
                                                        ▼
                                                  archive/
```

## Workflow

1. **Explore** — Use `/opsx:app-explore` to think through goals, architecture, and features; captures decisions into `app-config.json`
2. **Plan** — When a feature spec reaches `planned` status, use `/opsx:ff` to create a change spec
3. **Implement** — Use `/opsx:apply` to implement the tasks
4. **Verify** — Use `/opsx:verify` to check implementation matches the spec
5. **Archive** — Use `/opsx:archive` to move completed changes to `changes/archive/`

## Commands

| Command | Purpose |
|---------|---------|
| `/opsx:app-design` | Full upfront design — architecture, features, wireframes (optional pre-step) |
| `/opsx:app-create` | Bootstrap a new app or onboard an existing repo |
| `/opsx:app-explore` | Think through goals, architecture, and features; updates `app-config.json` |
| `/opsx:app-apply` | Apply `app-config.json` decisions to actual app files |
| `/opsx:app-verify` | Audit app files against `app-config.json` (read-only) |
| `/opsx:explore` | Investigate a problem or idea before starting a change (no output) |
| `/opsx:ff {name}` | Create all artifacts for a new change at once |
| `/opsx:new {name}` | Start a new change (step-by-step) |
| `/opsx:continue` | Generate the next artifact in the sequence |
| `/opsx:plan-to-issues` | Convert tasks.md into plan.json and GitHub Issues |
| `/opsx:apply` | Implement tasks from a change |
| `/opsx:verify` | Verify implementation matches the spec |
| `/opsx:archive` | Archive a completed change |
