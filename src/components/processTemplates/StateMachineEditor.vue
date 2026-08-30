<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Structured state-machine editor: edit states[] and transitions[] of a process
 template, plus the initial state. Emits an updated form via v-model. A textual
 graph summary stands in for the planned visual diagram (residue).

 VUE 3 v-model (ADR-066): the parent writes `<StateMachineEditor v-model="form">`,
 which under the default PURE Vue 3 build (webpack.config.js only enables
 @vue/compat behind VUE_COMPAT=true) compiles to `:modelValue` + `@update:modelValue`.
 The component previously declared the VUE 2 pair (`value` prop + `input` emit),
 so `value` — declared `required` — arrived undefined and `value.stateMachine.states`
 threw during render. Vue 3 catches a render error and substitutes a comment node
 for the whole subtree, so the editor silently rendered as an empty comment
 placeholder: no `state-machine-editor`, no `state-machine-add-state`, no
 console-visible crash in the parent.

 `inheritAttrs: false` because this component owns its root identity. Vue 3 merges
 fallthrough attrs AFTER the root vnode's own props, so the parent's
 `data-testid="process-template-state-machine"` overwrote this root's
 `data-testid="state-machine-editor"` — the testid the spec (and the component)
 declares as the contract.

 @spec openspec/specs/process-configuration/spec.md
-->
<template>
	<div class="state-machine-editor" data-testid="state-machine-editor">
		<h3>{{ t('decidiq', 'State machine') }}</h3>

		<div class="form-group">
			<NcSelect
				v-model="initialState"
				:inputLabel="t('decidiq', 'Initial state')"
				:options="stateNames"
				data-testid="state-machine-initial" />
		</div>

		<h4>{{ t('decidiq', 'States') }}</h4>
		<div
			v-for="(state, i) in modelValue.stateMachine.states"
			:key="'state-' + i"
			class="row"
			data-testid="state-row">
			<input
				v-model="state.name"
				type="text"
				:aria-label="t('decidiq', 'State name')"
				:placeholder="t('decidiq', 'State name')"
				@input="emit" />
			<NcButton
				variant="tertiary"
				:aria-label="t('decidiq', 'Remove state')"
				@click="removeState(i)">
				{{ t('decidiq', 'Remove') }}
			</NcButton>
		</div>
		<NcButton data-testid="state-machine-add-state" @click="addState">
			{{ t('decidiq', 'Add state') }}
		</NcButton>

		<h4>{{ t('decidiq', 'Transitions') }}</h4>
		<div
			v-for="(tr, i) in modelValue.stateMachine.transitions"
			:key="'tr-' + i"
			class="row"
			data-testid="transition-row">
			<NcSelect
				v-model="tr.from"
				:inputLabel="t('decidiq', 'From')"
				:options="stateNames"
				@input="emit" />
			<NcSelect
				v-model="tr.to"
				:inputLabel="t('decidiq', 'To')"
				:options="stateNames"
				@input="emit" />
			<label class="chair-only">
				<input v-model="tr.chairOnly" type="checkbox" @change="emit" />
				{{ t('decidiq', 'Chair only') }}
			</label>
			<NcButton
				variant="tertiary"
				:aria-label="t('decidiq', 'Remove transition')"
				@click="removeTransition(i)">
				{{ t('decidiq', 'Remove') }}
			</NcButton>
		</div>
		<NcButton data-testid="state-machine-add-transition" @click="addTransition">
			{{ t('decidiq', 'Add transition') }}
		</NcButton>

		<p class="graph-summary" data-testid="state-machine-summary">
			{{ graphSummary }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

export default {
	name: 'StateMachineEditor',
	components: { NcButton, NcSelect },
	inheritAttrs: false,
	props: {
		modelValue: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:modelValue'],
	computed: {
		/** @spec openspec/specs/process-configuration/spec.md */
		stateNames() {
			return (this.modelValue.stateMachine.states || [])
				.map((s) => s.name)
				.filter(Boolean)
		},

		initialState: {
			/** @spec openspec/specs/process-configuration/spec.md */
			get() {
				return this.modelValue.initialState
			},

			/**
			 * @param v
			 * @spec openspec/specs/process-configuration/spec.md
			 */
			set(v) {
				this.$emit('update:modelValue', {
					...this.modelValue,
					initialState: v,
				})
			},
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		graphSummary() {
			const transitions = this.modelValue.stateMachine.transitions || []
			return this.t('decidiq', '{states} states, {transitions} transitions', {
				states: this.stateNames.length,
				transitions: transitions.length,
			})
		},
	},

	methods: {
		/** @spec openspec/specs/process-configuration/spec.md */
		emit() {
			this.$emit('update:modelValue', { ...this.modelValue })
		},

		/**
		 * @param stateMachine
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		emitStateMachine(stateMachine) {
			this.$emit('update:modelValue', {
				...this.modelValue,
				stateMachine: {
					...this.modelValue.stateMachine,
					...stateMachine,
				},
			})
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		addState() {
			const states = [
				...(this.modelValue.stateMachine.states || []),
				{ name: '' },
			]
			this.emitStateMachine({ states })
		},

		/**
		 * @param i
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		removeState(i) {
			const states = (this.modelValue.stateMachine.states || []).filter(
				(_, idx) => idx !== i,
			)
			this.emitStateMachine({ states })
		},

		/** @spec openspec/specs/process-configuration/spec.md */
		addTransition() {
			const transitions = [
				...(this.modelValue.stateMachine.transitions || []),
				{ from: '', to: '', chairOnly: false },
			]
			this.emitStateMachine({ transitions })
		},

		/**
		 * @param i
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		removeTransition(i) {
			const transitions = (
				this.modelValue.stateMachine.transitions || []
			).filter((_, idx) => idx !== i)
			this.emitStateMachine({ transitions })
		},
	},
}
</script>

<style scoped>
.row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.chair-only {
	display: flex;
	align-items: center;
	gap: 4px;
	white-space: nowrap;
}

.graph-summary {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
