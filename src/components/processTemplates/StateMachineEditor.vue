<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Structured state-machine editor: edit states[] and transitions[] of a process
 template, plus the initial state. Emits an updated form via v-model. A textual
 graph summary stands in for the planned visual diagram (residue).

 @spec openspec/specs/process-configuration/spec.md
-->
<template>
	<div class="state-machine-editor" data-testid="state-machine-editor">
		<h3>{{ t('decidesk', 'State machine') }}</h3>

		<div class="form-group">
			<NcSelect
				v-model="initialState"
				:input-label="t('decidesk', 'Initial state')"
				:options="stateNames"
				data-testid="state-machine-initial" />
		</div>

		<h4>{{ t('decidesk', 'States') }}</h4>
		<div v-for="(state, i) in value.stateMachine.states"
			:key="'state-' + i"
			class="row"
			data-testid="state-row">
			<input
				v-model="state.name"
				type="text"
				:aria-label="t('decidesk', 'State name')"
				:placeholder="t('decidesk', 'State name')"
				@input="emit">
			<NcButton type="tertiary" :aria-label="t('decidesk', 'Remove state')" @click="removeState(i)">
				{{ t('decidesk', 'Remove') }}
			</NcButton>
		</div>
		<NcButton data-testid="state-machine-add-state" @click="addState">
			{{ t('decidesk', 'Add state') }}
		</NcButton>

		<h4>{{ t('decidesk', 'Transitions') }}</h4>
		<div v-for="(tr, i) in value.stateMachine.transitions"
			:key="'tr-' + i"
			class="row"
			data-testid="transition-row">
			<NcSelect
				v-model="tr.from"
				:input-label="t('decidesk', 'From')"
				:options="stateNames"
				@input="emit" />
			<NcSelect
				v-model="tr.to"
				:input-label="t('decidesk', 'To')"
				:options="stateNames"
				@input="emit" />
			<label class="chair-only">
				<input v-model="tr.chairOnly" type="checkbox" @change="emit">
				{{ t('decidesk', 'Chair only') }}
			</label>
			<NcButton type="tertiary" :aria-label="t('decidesk', 'Remove transition')" @click="removeTransition(i)">
				{{ t('decidesk', 'Remove') }}
			</NcButton>
		</div>
		<NcButton data-testid="state-machine-add-transition" @click="addTransition">
			{{ t('decidesk', 'Add transition') }}
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
	props: {
		value: {
			type: Object,
			required: true,
		},
	},
	computed: {
		/** @spec openspec/specs/process-configuration/spec.md */
		stateNames() {
			return (this.value.stateMachine.states || []).map((s) => s.name).filter(Boolean)
		},
		initialState: {
			/** @spec openspec/specs/process-configuration/spec.md */
			get() {
				return this.value.initialState
			},
			/**
			 * @param v
			 * @spec openspec/specs/process-configuration/spec.md
			 */
			set(v) {
				this.$emit('input', { ...this.value, initialState: v })
			},
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		graphSummary() {
			const transitions = this.value.stateMachine.transitions || []
			return this.t('decidesk', '{states} states, {transitions} transitions', {
				states: this.stateNames.length,
				transitions: transitions.length,
			})
		},
	},
	methods: {
		/** @spec openspec/specs/process-configuration/spec.md */
		emit() {
			this.$emit('input', { ...this.value })
		},
		/**
		 * @param stateMachine
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		emitStateMachine(stateMachine) {
			this.$emit('input', {
				...this.value,
				stateMachine: {
					...this.value.stateMachine,
					...stateMachine,
				},
			})
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		addState() {
			const states = [...(this.value.stateMachine.states || []), { name: '' }]
			this.emitStateMachine({ states })
		},
		/**
		 * @param i
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		removeState(i) {
			const states = (this.value.stateMachine.states || []).filter((_, idx) => idx !== i)
			this.emitStateMachine({ states })
		},
		/** @spec openspec/specs/process-configuration/spec.md */
		addTransition() {
			const transitions = [...(this.value.stateMachine.transitions || []), { from: '', to: '', chairOnly: false }]
			this.emitStateMachine({ transitions })
		},
		/**
		 * @param i
		 * @spec openspec/specs/process-configuration/spec.md
		 */
		removeTransition(i) {
			const transitions = (this.value.stateMachine.transitions || []).filter((_, idx) => idx !== i)
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
