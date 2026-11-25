<script setup lang="ts">
/**
 * Custo estimado calculado pelo backend (FR-022, FR-024), com a data e a
 * origem do custo de cada ingrediente (FR-025). Só exibe; não calcula.
 */
import { costSourceLabels, type RecipeCost } from './api'
import { formatDecimal, formatMoney } from '@/shared/format/decimal'
import { formatDateTime } from '@/shared/format/datetime'

defineProps<{
  cost: RecipeCost
  yieldQty: string
  yieldUnitCode: string
  saleUnitCode: string | null
  timeZone: string
}>()

/** Custo unitário (6 casas) para exibição: até 4 casas, mínimo 2. */
function unitMoney(value: string | null): string {
  if (value === null) return '—'
  const [int = '0', frac = ''] = value.split('.')
  const trimmed = frac.replace(/0+$/, '')
  const decimals = trimmed.length <= 2 ? frac.slice(0, 2).padEnd(2, '0') : trimmed.slice(0, 4)
  return `R$ ${formatDecimal(`${int}.${decimals}`)}`
}
</script>

<template>
  <section class="cost" aria-labelledby="cost-title">
    <h2 id="cost-title">Custo estimado</h2>
    <p class="hint">Calculado com o custo atual de cada ingrediente (política de custo médio ponderado). Não é lucro nem custo contábil.</p>

    <ul v-if="cost.warnings.length" class="warnings" role="list">
      <li v-for="(w, i) in cost.warnings" :key="i" :class="{ blocking: ['NO_ITEMS', 'INGREDIENT_MISSING', 'UNIT_INCOMPATIBLE', 'YIELD_UNIT_INCOMPATIBLE'].includes(w.code) }">
        {{ w.message }}
      </li>
    </ul>

    <div class="totals">
      <div class="box">
        <span class="label">Ingredientes (lote)</span>
        <strong>{{ formatMoney(cost.ingredientsCost) }}</strong>
      </div>
      <div class="box">
        <span class="label">Custos adicionais (lote)</span>
        <strong>{{ formatMoney(cost.additionalCost) }}</strong>
      </div>
      <div class="box total">
        <span class="label">Total do lote · rende {{ formatDecimal(yieldQty) }} {{ yieldUnitCode }}</span>
        <strong>{{ formatMoney(cost.totalCost) }}</strong>
      </div>
      <div class="box highlight">
        <span class="label">Por {{ yieldUnitCode }} produzido</span>
        <strong>{{ unitMoney(cost.costPerYieldUnit) }}</strong>
        <span v-if="saleUnitCode && saleUnitCode !== yieldUnitCode" class="sub">Por {{ saleUnitCode }} vendido: {{ unitMoney(cost.costPerSaleUnit) }}</span>
      </div>
    </div>

    <table v-if="cost.lines.length" class="f-table lines">
      <thead>
        <tr><th>Ingrediente</th><th class="num">Na receita</th><th class="num">Custo unitário</th><th class="num">Custo no lote</th><th>Origem do custo</th></tr>
      </thead>
      <tbody>
        <tr v-for="line in cost.lines" :key="line.ingredientId">
          <td>{{ line.ingredientName ?? 'Ingrediente removido' }}</td>
          <td data-label="Na receita" class="num">
            {{ formatDecimal(line.quantity) }} {{ line.unitCode }}
            <div v-if="line.stockQuantity && line.stockUnitCode !== line.unitCode" class="muted">= {{ formatDecimal(line.stockQuantity) }} {{ line.stockUnitCode }}</div>
          </td>
          <td data-label="Custo unitário" class="num">{{ line.unitCost !== null ? `R$ ${formatDecimal(line.unitCost)}/${line.stockUnitCode}` : '—' }}</td>
          <td data-label="Custo no lote" class="num">{{ line.lineCost !== null ? formatMoney(line.lineCost) : '—' }}</td>
          <td data-label="Origem do custo" class="muted">
            <template v-if="line.costSource">{{ costSourceLabels[line.costSource] }}</template>
            <template v-if="line.costChangedAt"> · {{ formatDateTime(line.costChangedAt, timeZone) }}</template>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.cost h2 {
  font-size: 1.15rem;
}

.hint {
  margin: 0.25rem 0 1rem;
  color: var(--c-text-muted);
  font-size: 0.9rem;
}

.warnings {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.warnings li {
  padding: 0.6rem 0.9rem;
  border-radius: var(--radius-sm);
  background: var(--c-warning-soft);
  color: var(--c-warning);
}

.warnings li.blocking {
  background: var(--c-danger-soft);
  color: var(--c-danger);
}

.totals {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  margin-bottom: 1rem;
}

.box {
  padding: 0.9rem 1rem;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.box strong {
  font-size: 1.3rem;
  font-variant-numeric: tabular-nums;
}

.box.highlight {
  background: var(--c-primary-soft);
  border-color: #f6cdb1;
}

.label,
.sub {
  color: var(--c-text-muted);
  font-size: 0.85rem;
}

.lines .muted {
  font-size: 0.85rem;
}
</style>
