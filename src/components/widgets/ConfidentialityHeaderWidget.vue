<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ConfidentialityHeaderWidget — the header row of the confidentiality page
 (board DcGeheimhouding, confidentiality-term-and-lapse REQ-EMB-023): the line
 "Bekrachtiging door <body> uiterlijk <date>" while ratification is open, and
 the stepper Opgelegd, Bekrachtigd (Vervallen once lapsed), Opgeheven. Each
 step says its state in text, so the stepper does not rely on colour.
 Registered as catalog type "confidentiality-header" by
 registerDetailWidgets.js and placed in the header through `headerWidget`.

 @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board
-->
<template>
	<div class="cn-confidentiality-header" data-testid="confidentiality-header">
		<p
			v-if="headerLine"
			class="cn-confidentiality-header__line"
			data-testid="confidentiality-header-line">
			{{ headerText }}
		</p>
		<ol
			class="cn-confidentiality-header__stepper"
			:aria-label="t('decidiq', 'Confidentiality steps')"
			data-testid="confidentiality-stepper">
			<li
				v-for="step in steps"
				:key="step.key"
				class="cn-confidentiality-header__step"
				:class="'cn-confidentiality-header__step--' + step.state"
				:aria-current="step.state === 'current' ? 'step' : undefined"
				:data-testid="`confidentiality-step-${step.key}`">
				<span class="cn-confidentiality-header__label">{{ stepLabel(step.key) }}</span>
				<span class="cn-confidentiality-header__state">{{ stateLabel(step.state) }}</span>
			</li>
		</ol>
	</div>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import {
	buildConfidentialityStepper,
	plainObject,
	ratificationHeaderLine,
	resolveObjectLabel,
} from './registerDetailWidgets.js'

const STEP_LABELS = {
	imposed: () => t('decidiq', 'Imposed'),
	ratified: () => t('decidiq', 'Ratified'),
	lapsed: () => t('decidiq', 'Lapsed'),
	dissolved: () => t('decidiq', 'Lifted'),
}

const STATE_LABELS = {
	done: () => t('decidiq', 'done'),
	current: () => t('decidiq', 'now'),
	planned: () => t('decidiq', 'planned'),
	skipped: () => t('decidiq', 'not applicable'),
}

export default {
	name: 'ConfidentialityHeaderWidget',

	props: {
		content: { type: Object, default: () => ({}) },
		objectId: { type: [String, Number], default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
		objectData: { type: Object, default: () => ({}) },
		objectType: { type: String, default: '' },
		store: { type: Object, default: null },
	},

	data() {
		return { bodyLabel: '' }
	},

	computed: {
		/** @spec exclude defensive objectData accessor, no behavioural requirement of its own */
		record() {
			return plainObject(this.objectData)
		},

		/** @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board */
		steps() {
			return buildConfidentialityStepper(this.record)
		},

		/** @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board */
		headerLine() {
			return ratificationHeaderLine(this.record)
		},

		/** @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board */
		headerText() {
			const date = this.formatDate(this.headerLine?.deadline)
			if (this.bodyLabel) {
				return t('decidiq', 'Ratification by {body} no later than {date}', { body: this.bodyLabel, date })
			}
			return t('decidiq', 'Ratification no later than {date}', { date })
		},
	},

	watch: {
		/** @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board */
		'headerLine.bodyId': {
			immediate: true,
			handler() {
				this.resolveBody()
			},
		},
	},

	methods: {
		/**
		 * @spec exclude presentation helper for step labels
		 * @param {string} key The step key.
		 * @return {string} The label.
		 */
		stepLabel(key) {
			return STEP_LABELS[key] ? STEP_LABELS[key]() : key
		},

		/**
		 * @spec exclude presentation helper for step state labels
		 * @param {string} state The step state.
		 * @return {string} The label.
		 */
		stateLabel(state) {
			return STATE_LABELS[state] ? STATE_LABELS[state]() : state
		},

		/**
		 * @spec exclude presentation helper for date formatting, no behavioural requirement of its own
		 * @param {string} value ISO date string.
		 * @return {string} The localised date.
		 */
		formatDate(value) {
			if (!value) return ''
			const d = new Date(value)
			return Number.isNaN(d.getTime())
				? String(value)
				: d.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
		},

		/**
		 * The ratifying body's name for the header line; empty when it cannot be read.
		 *
		 * @spec openspec/changes/archive/2026-11-24-confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board
		 * @return {Promise<void>}
		 */
		async resolveBody() {
			const id = this.headerLine?.bodyId
			this.bodyLabel = ''
			if (!id) return
			let store = this.store
			if (!store) {
				try {
					store = useObjectStore()
				} catch {
					return
				}
			}
			const label = await resolveObjectLabel(store, {
				typeSlug: 'governance-body',
				schemaSlug: 'governance-body',
				registerSlug: this.register || 'decidiq',
				id,
				labelField: 'name',
			}).catch(() => '')
			if (id === this.headerLine?.bodyId && label !== id) this.bodyLabel = label || ''
		},
	},
}
</script>

<style scoped>
.cn-confidentiality-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cn-confidentiality-header__line {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-header__stepper {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cn-confidentiality-header__step {
	display: flex;
	flex-direction: column;
	min-width: 120px;
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.cn-confidentiality-header__step--done {
	border-color: var(--color-success);
}

.cn-confidentiality-header__step--current {
	border-color: var(--color-primary-element);
	font-weight: bold;
}

.cn-confidentiality-header__step--skipped,
.cn-confidentiality-header__step--planned {
	color: var(--color-text-maxcontrast);
}

.cn-confidentiality-header__state {
	font-size: 0.9em;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}
</style>
