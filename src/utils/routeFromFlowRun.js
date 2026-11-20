// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The decision's route rows, read from its OpenRegister flow run.
 *
 * A route sent after approval-routes-on-flow-user-tasks has no decidiq stage
 * rows: every step is an OpenRegister task raised by the run. This turns the
 * route template and the run's tasks into the rows the route tab already
 * renders (sequence, decision maker, role, status, outcome, date, label), so
 * the tab reads one shape whichever engine ran the route.
 *
 * A step's task node is `step-<order>-<slot>`, the id the compiler gives it
 * (lib/Service/ApprovalRouteFlowCompiler.php). A step that was returned to and
 * opened again has several tasks: the newest is the row, the earlier ones are
 * its history, so an earlier outcome is never lost.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run
 */

/** Task states that mean the task has concluded. */
export const TERMINAL_TASK_STATES = ['completed', 'terminated', 'disabled']

/**
 * The route's steps grouped by order, each with its compiled node id.
 *
 * @param {object} route The ApprovalRoute object.
 * @return {Array<object>} The steps, in route order, each with `order`, `slot` and `node`.
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run
 */
export function compiledSteps(route) {
	const steps = Array.isArray(route?.steps) ? route.steps.filter((s) => s && typeof s === 'object') : []
	const withOrder = steps.map((step, index) => ({ step, order: Number(step.order) > 0 ? Number(step.order) : index + 1 }))
	const orders = [...new Set(withOrder.map((s) => s.order))].sort((a, b) => a - b)
	const out = []
	for (const order of orders) {
		withOrder.filter((s) => s.order === order).forEach((s, i) => {
			out.push({ ...s.step, order, slot: i + 1, node: `step-${order}-${i + 1}` })
		})
	}
	return out
}

/**
 * The node a task was raised by.
 *
 * @param {object} task An OpenRegister task.
 * @return {string} The node id, or ''.
 */
function nodeOf(task) {
	return String(task?.metadata?.flowNode || '')
}

/**
 * When a task was created, for ordering a step's tasks.
 *
 * @param {object} task An OpenRegister task.
 * @return {number} Milliseconds, 0 when unknown.
 */
function createdOf(task) {
	const t = Date.parse(task?.created || task?.createdAt || '')
	return Number.isNaN(t) ? 0 : t
}

/**
 * Whether a task concluded by its deadline passing rather than by a person.
 *
 * @param {object} task An OpenRegister task.
 * @return {boolean} True for a lapse.
 */
function lapsed(task) {
	return task?.metadata?.timedOut === true || (!!task?.onTimeout && !task?.completedBy && TERMINAL_TASK_STATES.includes(task?.state))
}

/**
 * One concluded task as a history entry.
 *
 * @param {object} task An OpenRegister task.
 * @return {object} The entry: outcome, decidedAt, by, comment, lapsed.
 */
function entryOf(task) {
	return {
		outcome: task.outcome || '',
		decidedAt: task.completedAt || '',
		by: task.completedBy || '',
		comment: task.comment || '',
		lapsed: lapsed(task),
	}
}

/**
 * The method a step's role implies, in the tab's vocabulary.
 *
 * @param {object} step The route step.
 * @return {string} The method.
 */
function methodOf(step) {
	if (step.stageType === 'advisory') return 'advice'
	if (step.actorType === 'body') return 'vote'
	if (step.stageType === 'endorsement') return 'signature'
	return 'manual'
}

/**
 * The fields of a route row that come from the compiled step itself.
 *
 * @param {object} step The compiled step.
 * @return {object} The step's own fields.
 */
function stepFields(step) {
	return {
		id: step.node,
		sequence: step.order,
		label: step.label || '',
		stageType: step.stageType || '',
		method: methodOf(step),
		decisionMakerType: step.actorType === 'body' ? 'body' : 'person',
		actor: step.actor || '',
	}
}

/**
 * The row's status from its latest task.
 *
 * @param {object|null} latest The step's latest task.
 * @param {boolean} done Whether that task is finished.
 * @return {string} pending, active or decided.
 */
function stageStatus(latest, done) {
	if (latest === null) return 'pending'
	return done ? 'decided' : 'active'
}

/**
 * The outcome fields of a finished task, empty for an unfinished one.
 *
 * @param {object|null} latest The step's latest task.
 * @param {boolean} done Whether that task is finished.
 * @return {{outcome: string, decidedAt: string, lapsed: boolean}} The outcome fields.
 */
function outcomeFields(latest, done) {
	if (!done) return { outcome: '', decidedAt: '', lapsed: false }
	return { outcome: latest.outcome || '', decidedAt: latest.completedAt || '', lapsed: lapsed(latest) }
}

/**
 * Who finished the step in place of the person it names, or '' when nobody did.
 *
 * @param {object} step The compiled step.
 * @param {object|null} latest The step's latest task.
 * @param {boolean} done Whether that task is finished.
 * @return {string} The substitute's user id.
 */
function substituteOf(step, latest, done) {
	const actor = step.actorType === 'person' ? String(step.actor || '') : ''
	const by = done ? String(latest.completedBy || '') : ''
	return actor !== '' && by !== '' && by !== actor ? by : ''
}

/**
 * One route row from a compiled step and the run's tasks.
 *
 * @param {object} step The compiled step.
 * @param {Array<object>} all The run's tasks.
 * @return {object} The row.
 */
function stageRow(step, all) {
	const own = all.filter((t) => nodeOf(t) === step.node).sort((a, b) => createdOf(a) - createdOf(b))
	const latest = own.length ? own[own.length - 1] : null
	const done = latest !== null && TERMINAL_TASK_STATES.includes(latest.state)
	return {
		...stepFields(step),
		status: stageStatus(latest, done),
		...outcomeFields(latest, done),
		substitute: substituteOf(step, latest, done),
		history: own.slice(0, -1).filter((t) => TERMINAL_TASK_STATES.includes(t.state)).map(entryOf),
		task: latest ? latest.uuid || latest.id || null : null,
	}
}

/**
 * The route rows, the current row and the decided count, from a flow run.
 *
 * @param {object} route The ApprovalRoute the run was started from.
 * @param {Array<object>} tasks The run's tasks.
 * @return {{stages: Array<object>, current: string|null, decided: number}} The rows the route tab renders.
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-006-a-substitute-can-act-while-the-approver-is-away
 */
export function routeFromFlowRun(route, tasks) {
	const all = Array.isArray(tasks) ? tasks : []
	const stages = compiledSteps(route).map((step) => stageRow(step, all))
	const currentRow = stages.find((s) => s.status === 'active') || null
	return {
		stages,
		current: currentRow ? currentRow.id : null,
		decided: stages.filter((s) => s.status === 'decided').length,
	}
}
