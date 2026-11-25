<script setup lang="ts">
/**
 * Detalhe do item: saldo por local, histórico (ledger) e lançamentos.
 * Movimentos nunca são editados; correções são estornos (com motivo).
 */
import { computed, ref, watch } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import type { Unit } from '@/modules/catalog/api'
import { inventoryApi, type StockItemDetail, type StockItemType, type StockMovement } from './api'
import { useAsync } from '@/shared/ui/useAsync'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import { formatDecimal } from '@/shared/format/decimal'
import { formatDateTime } from '@/shared/format/datetime'
import FButton from '@/shared/ui/FButton.vue'
import FPanel from '@/shared/ui/FPanel.vue'
import FState from '@/shared/ui/FState.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import StockMovementForm from './StockMovementForm.vue'

const props = defineProps<{ itemType: StockItemType | null; itemId: string | null; units: Unit[] }>()
const emit = defineEmits<{ close: []; changed: [] }>()

const session = useSessionStore()
const canWrite = computed(() => session.can('inventory.write'))
const canReverse = computed(() => session.can('inventory.reverse'))
const timeZone = computed(() => session.tenant?.timezone ?? 'America/Sao_Paulo')

const view = ref<'history' | 'opening' | 'adjustment'>('history')
const reversing = ref<StockMovement | null>(null)
const reversalReason = ref('')
const reversalForm = useForm()

const detail = useAsync<StockItemDetail>(() => inventoryApi.item(props.itemType!, props.itemId!))
const history = useAsync(() => inventoryApi.movements(props.itemType!, props.itemId!, 1, 50))

async function reload(): Promise<void> {
  await Promise.all([detail.run(), history.run()])
}

watch(
  () => [props.itemType, props.itemId],
  async ([type, id]) => {
    view.value = 'history'
    reversing.value = null
    if (type && id) await reload()
  },
  { immediate: true },
)

function signed(m: StockMovement): string {
  const unit = detail.data.value?.unit.code ?? ''
  const negative = m.quantityDelta.startsWith('-')
  return `${negative ? '−' : '+'}${formatDecimal(m.quantityDelta.replace('-', ''))} ${unit}`
}

async function onSaved(movement: StockMovement): Promise<void> {
  notify(`${movement.typeLabel} registrado: ${signed(movement)}.`)
  view.value = 'history'
  await reload()
  emit('changed')
}

function startReversal(m: StockMovement): void {
  reversing.value = m
  reversalReason.value = ''
  reversalForm.reset()
}

async function confirmReversal(): Promise<void> {
  if (!reversing.value) return
  const target = reversing.value
  const done = await reversalForm.submit(() => inventoryApi.reverse(target.id, reversalReason.value))
  if (done) {
    notify('Lançamento estornado.')
    reversing.value = null
    await reload()
    emit('changed')
  }
}

const title = computed(() => {
  if (view.value === 'opening') return 'Saldo inicial'
  if (view.value === 'adjustment') return 'Perda, devolução ou ajuste'
  return detail.data.value?.name ?? 'Estoque'
})
</script>

<template>
  <FPanel :open="!!itemId" :title="title" @close="emit('close')">
    <FState v-if="detail.loading.value && !detail.data.value" kind="loading" />
    <FState v-else-if="detail.error.value" kind="error" :message="detail.error.value" @retry="reload" />

    <template v-else-if="detail.data.value">
      <StockMovementForm
        v-if="view !== 'history'"
        :item="detail.data.value"
        :mode="view"
        :units="units"
        @saved="onSaved"
        @cancel="view = 'history'"
      />

      <template v-else>
        <section class="balance" :class="{ 'is-refreshing': detail.loading.value }" aria-label="Saldo" :aria-busy="detail.loading.value">
          <div v-for="b in detail.data.value.balances" :key="b.location.id" class="balance-box">
            <span class="balance-label">{{ b.location.name }}</span>
            <strong class="balance-value">{{ formatDecimal(b.quantity) }} {{ detail.data.value.unit.code }}</strong>
          </div>
        </section>

        <div v-if="canWrite" class="actions">
          <FButton v-if="!detail.data.value.hasMovements" @click="view = 'opening'">Lançar saldo inicial</FButton>
          <FButton :variant="detail.data.value.hasMovements ? 'primary' : 'secondary'" @click="view = 'adjustment'">Perda, devolução ou ajuste</FButton>
        </div>

        <h3 class="history-title">Histórico</h3>
        <FState v-if="history.loading.value && !history.data.value" kind="loading" />
        <FState v-else-if="history.error.value" kind="error" :message="history.error.value" @retry="history.run" />
        <FState
          v-else-if="history.data.value?.meta.total === 0"
          kind="empty"
          title="Sem movimentações"
          message="Comece lançando o saldo inicial com a quantidade que existe hoje."
        />
        <ol v-else-if="history.data.value" class="history" :class="{ 'is-refreshing': history.loading.value }" :aria-busy="history.loading.value">
          <li v-for="m in history.data.value.data" :key="m.id" :class="{ reversed: m.reversed, reversal: m.type === 'REVERSAL' }">
            <div class="row">
              <span class="type">{{ m.typeLabel }}</span>
              <span class="qty" :class="m.quantityDelta.startsWith('-') ? 'out' : 'in'">{{ signed(m) }}</span>
            </div>
            <div class="meta">
              {{ formatDateTime(m.occurredAt, timeZone) }} · {{ m.createdBy.name ?? '—' }}
              <span v-if="m.reversed" class="badge">Estornado</span>
            </div>
            <p v-if="m.reason" class="reason">{{ m.reason }}</p>

            <div v-if="canReverse && m.reversible && reversing?.id !== m.id" class="row-actions">
              <button type="button" class="link" @click="startReversal(m)">Estornar</button>
            </div>

            <form v-if="reversing?.id === m.id" class="reversal" novalidate @submit.prevent="confirmReversal">
              <p v-if="reversalForm.formError.value" class="form-error" role="alert">{{ reversalForm.formError.value }}</p>
              <FTextField v-model="reversalReason" label="Motivo do estorno" required :maxlength="255" :error="reversalForm.fieldError('reason')" />
              <p class="hint">Será lançado {{ signed({ ...m, quantityDelta: m.quantityDelta.startsWith('-') ? m.quantityDelta.slice(1) : '-' + m.quantityDelta }) }}. O lançamento original continua no histórico.</p>
              <div class="reversal-actions">
                <FButton variant="secondary" @click="reversing = null">Cancelar</FButton>
                <FButton type="submit" variant="danger" :loading="reversalForm.submitting.value">Confirmar estorno</FButton>
              </div>
            </form>
          </li>
        </ol>
        <p v-if="(history.data.value?.meta.total ?? 0) > 50" class="more">Mostrando os 50 lançamentos mais recentes.</p>
      </template>
    </template>
  </FPanel>
</template>

<style scoped>
.balance {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.balance-box {
  padding: 1rem;
  border-radius: var(--radius);
  background: var(--c-primary-soft);
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.balance-label {
  color: var(--c-text-muted);
  font-size: 0.9rem;
}

.balance-value {
  font-size: 1.6rem;
  font-variant-numeric: tabular-nums;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin: 1rem 0 0;
}

.history-title {
  font-size: 1rem;
  margin: 1.5rem 0 0.75rem;
}

.history {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.history li {
  padding: 0.75rem 1rem;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-sm);
}

.history li.reversed .type,
.history li.reversed .qty {
  text-decoration: line-through;
  color: var(--c-text-muted);
}

.history li.reversal {
  background: var(--c-surface-2);
}

.row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-weight: 600;
}

.qty {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.qty.in {
  color: var(--c-success);
}

.qty.out {
  color: var(--c-danger);
}

.meta {
  margin-top: 0.2rem;
  color: var(--c-text-muted);
  font-size: 0.88rem;
}

.reason {
  margin: 0.35rem 0 0;
  font-size: 0.92rem;
}

.row-actions {
  margin-top: 0.25rem;
}

.link {
  min-height: var(--touch);
  padding: 0 0.25rem;
  border: none;
  background: none;
  color: var(--c-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.reversal {
  margin-top: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.hint {
  margin: 0;
  color: var(--c-text-muted);
  font-size: 0.88rem;
}

.reversal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

.more {
  color: var(--c-text-muted);
  font-size: 0.88rem;
}
</style>
