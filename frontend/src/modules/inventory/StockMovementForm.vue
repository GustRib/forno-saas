<script setup lang="ts">
/**
 * Formulário de saldo inicial (FNO-031) ou de perda/devolução/ajuste
 * (FNO-033). O backend converte a unidade, valida o saldo e grava; aqui só
 * coletamos e normalizamos a digitação pt-BR.
 */
import { computed, reactive } from 'vue'
import type { Unit } from '@/modules/catalog/api'
import { adjustmentLabels, inventoryApi, type AdjustmentType, type StockItemDetail, type StockMovement } from './api'
import { useForm } from '@/shared/ui/useForm'
import { formatDecimal, parseDecimalInput } from '@/shared/format/decimal'
import { localInputToIso } from '@/shared/format/datetime'
import FButton from '@/shared/ui/FButton.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import FSelectField from '@/shared/ui/FSelectField.vue'

const props = defineProps<{
  item: StockItemDetail
  mode: 'opening' | 'adjustment'
  units: Unit[]
}>()
const emit = defineEmits<{ saved: [movement: StockMovement]; cancel: [] }>()

const form = useForm()
const input = reactive({
  type: 'WASTE' as AdjustmentType,
  quantity: '',
  unitId: props.item.unit.id,
  reason: '',
  unitCost: '',
  occurredAt: '',
})

const typeOptions = Object.entries(adjustmentLabels).map(([value, meta]) => ({ value, label: meta.label }))
const typeMeta = computed(() => adjustmentLabels[input.type])
const isInbound = computed(() => props.mode === 'opening' || typeMeta.value.inbound)

// Só sugere unidades da mesma dimensão; o backend recusa as demais de qualquer forma.
const unitOptions = computed(() =>
  props.units.filter((u) => u.dimension === props.item.unit.dimension).map((u) => ({ value: u.id, label: `${u.name} (${u.code})` })),
)

const available = computed(() => `${formatDecimal(props.item.totalQuantity)} ${props.item.unit.code}`)

async function submit(): Promise<void> {
  const errors: Record<string, string[]> = {}
  const quantity = parseDecimalInput(input.quantity)
  if (quantity === null || /^0+(\.0+)?$/.test(quantity)) errors.quantity = ['Informe uma quantidade maior que zero (ex.: 1,5).']
  const unitCost = isInbound.value && input.unitCost ? parseDecimalInput(input.unitCost) : null
  if (isInbound.value && input.unitCost && unitCost === null) errors.unitCost = ['Informe um custo válido (ex.: 0,0045).']
  const occurredAt = input.occurredAt ? localInputToIso(input.occurredAt) : null
  if (input.occurredAt && occurredAt === null) errors.occurredAt = ['Data inválida.']
  if (Object.keys(errors).length > 0) {
    form.fieldErrors.value = errors
    form.formError.value = 'Revise os campos destacados.'
    return
  }

  const payload = {
    itemType: props.item.itemType,
    itemId: props.item.itemId,
    quantity: quantity!,
    unitId: input.unitId === props.item.unit.id ? null : input.unitId,
    reason: input.reason.trim() || null,
    unitCost,
    occurredAt,
  }

  const movement = await form.submit(() =>
    props.mode === 'opening' ? inventoryApi.openingBalance(payload) : inventoryApi.adjustment(input.type, payload),
  )
  if (movement) emit('saved', movement)
}
</script>

<template>
  <form class="movement-form" novalidate @submit.prevent="submit">
    <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>

    <p class="context">
      <strong>{{ item.name }}</strong> · saldo atual <strong>{{ available }}</strong>
    </p>

    <div class="form-grid">
      <template v-if="mode === 'adjustment'">
        <FSelectField v-model="input.type" class="full" label="O que aconteceu?" :options="typeOptions" :hint="typeMeta.hint" :error="form.fieldError('type')" />
      </template>
      <p v-else class="full note">
        Informe a quantidade que existe hoje no estoque. O saldo inicial só pode ser lançado uma vez; depois, use ajustes.
      </p>

      <FTextField
        v-model="input.quantity"
        :label="isInbound ? 'Quantidade que entra' : 'Quantidade que sai'"
        inputmode="decimal"
        placeholder="0"
        required
        :error="form.fieldError('quantity')"
      />
      <FSelectField
        v-model="input.unitId"
        label="Unidade"
        :options="unitOptions"
        :hint="input.unitId !== item.unit.id ? `Será convertido para ${item.unit.code}.` : undefined"
        :error="form.fieldError('unitId')"
      />

      <FTextField
        v-model="input.reason"
        class="full"
        :label="mode === 'opening' ? 'Observação' : 'Motivo'"
        :required="mode === 'adjustment'"
        :maxlength="255"
        :placeholder="mode === 'adjustment' ? 'Ex.: pacote rasgado, contagem de sexta-feira' : 'Opcional'"
        :error="form.fieldError('reason')"
      />

      <FTextField
        v-if="isInbound"
        v-model="input.unitCost"
        :label="`Custo por ${item.unit.code} (R$)`"
        inputmode="decimal"
        :hint="item.itemType === 'INGREDIENT' ? 'Opcional. Se informado, recalcula o custo médio do ingrediente.' : 'Opcional. Fica registrado no lançamento.'"
        :error="form.fieldError('unitCost')"
      />
      <FTextField
        v-model="input.occurredAt"
        label="Data e hora"
        type="datetime-local"
        hint="Deixe em branco para agora."
        :error="form.fieldError('occurredAt')"
      />
    </div>

    <div class="actions">
      <FButton variant="secondary" @click="emit('cancel')">Cancelar</FButton>
      <FButton type="submit" :loading="form.submitting.value">{{ mode === 'opening' ? 'Lançar saldo inicial' : 'Registrar' }}</FButton>
    </div>
  </form>
</template>

<style scoped>
.context {
  margin: 0 0 1rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-sm);
  background: var(--c-surface-2);
}

.note {
  margin: 0;
  color: var(--c-text-muted);
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1.5rem;
}
</style>
