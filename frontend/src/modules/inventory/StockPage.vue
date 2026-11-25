<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { catalogApi, type Unit } from '@/modules/catalog/api'
import { inventoryApi, type StockBalanceRow, type StockItemType } from './api'
import { useAsync } from '@/shared/ui/useAsync'
import { formatDecimal } from '@/shared/format/decimal'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FButton from '@/shared/ui/FButton.vue'
import FState from '@/shared/ui/FState.vue'
import StockItemPanel from './StockItemPanel.vue'

const PAGE_SIZE = 25
const filters = reactive({ itemType: 'INGREDIENT' as StockItemType, q: '', includeInactive: false, page: 1 })
const list = useAsync(() =>
  inventoryApi.balances({ itemType: filters.itemType, q: filters.q || undefined, includeInactive: filters.includeInactive, page: filters.page, limit: PAGE_SIZE }),
)
const units = ref<Unit[]>([])
const selected = ref<StockBalanceRow | null>(null)

let searchTimer: number | undefined
watch(() => filters.q, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    filters.page = 1
    void list.run()
  }, 300)
})
watch(() => [filters.itemType, filters.includeInactive], () => {
  filters.page = 1
  void list.run()
})

const totalPages = computed(() => Math.max(1, Math.ceil((list.data.value?.meta.total ?? 0) / PAGE_SIZE)))
function goTo(page: number): void {
  filters.page = page
  void list.run()
}

const tabs: { value: StockItemType; label: string }[] = [
  { value: 'INGREDIENT', label: 'Ingredientes' },
  { value: 'PRODUCT', label: 'Produtos' },
]

onMounted(async () => {
  await Promise.all([list.run(), catalogApi.units.list().then((u) => (units.value = u))])
})
</script>

<template>
  <PageHeader
    title="Estoque"
    subtitle="Saldo de cada item e o histórico completo de entradas e saídas. Lançamentos não são apagados: correções viram estornos."
  />

  <div class="tabs" role="tablist" aria-label="Tipo de item">
    <button
      v-for="tab in tabs"
      :key="tab.value"
      type="button"
      role="tab"
      class="tab"
      :class="{ active: filters.itemType === tab.value }"
      :aria-selected="filters.itemType === tab.value"
      @click="filters.itemType = tab.value"
    >
      {{ tab.label }}
    </button>
  </div>

  <div class="toolbar">
    <div class="grow">
      <label class="sr-only" for="stock-search">Buscar</label>
      <input id="stock-search" v-model="filters.q" class="f-input" type="search" inputmode="search" placeholder="Buscar por nome ou SKU" />
    </div>
    <label class="inactive">
      <input v-model="filters.includeInactive" type="checkbox" />
      Incluir inativos
    </label>
  </div>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />
  <FState
    v-else-if="list.data.value?.meta.total === 0"
    kind="empty"
    :title="filters.q ? 'Nenhum item encontrado' : 'Nenhum item com controle de estoque'"
    :message="filters.itemType === 'INGREDIENT' ? 'Cadastre ingredientes para controlar o estoque.' : 'Só aparecem produtos com “Controlar estoque” ligado.'"
  >
    <RouterLink v-if="!filters.q" :to="filters.itemType === 'INGREDIENT' ? '/ingredientes' : '/produtos'">Ir para o cadastro</RouterLink>
  </FState>

  <template v-else-if="list.data.value">
    <table class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
      <thead>
        <tr><th>Item</th><th>Unidade</th><th class="num">Saldo</th><th>Situação</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in list.data.value.data" :key="row.itemId" class="is-clickable" tabindex="0" @click="selected = row" @keydown.enter="selected = row">
          <td>
            <strong>{{ row.name }}</strong>
            <div v-if="row.sku" class="muted">{{ row.sku }}</div>
          </td>
          <td data-label="Unidade">{{ row.unit.name }} ({{ row.unit.code }})</td>
          <td data-label="Saldo" class="num">
            <strong :class="{ zero: row.quantity === '0' }">{{ formatDecimal(row.quantity) }} {{ row.unit.code }}</strong>
          </td>
          <td><span class="badge" :class="row.active ? 'is-success' : ''">{{ row.active ? 'Ativo' : 'Inativo' }}</span></td>
        </tr>
      </tbody>
    </table>
    <nav v-if="totalPages > 1" class="pagination" aria-label="Paginação">
      <FButton variant="secondary" :disabled="filters.page <= 1" @click="goTo(filters.page - 1)">Anterior</FButton>
      <span>Página {{ filters.page }} de {{ totalPages }}</span>
      <FButton variant="secondary" :disabled="filters.page >= totalPages" @click="goTo(filters.page + 1)">Próxima</FButton>
    </nav>
  </template>

  <StockItemPanel
    :item-type="selected?.itemType ?? null"
    :item-id="selected?.itemId ?? null"
    :units="units"
    @close="selected = null"
    @changed="list.run"
  />
</template>

<style scoped>
.tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.tab {
  min-height: var(--touch);
  padding: 0 1.25rem;
  border: 1px solid var(--c-border-strong);
  border-radius: 999px;
  background: var(--c-surface);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.tab.active {
  background: var(--c-primary);
  border-color: var(--c-primary);
  color: #fff;
}

.inactive {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-height: var(--touch);
  color: var(--c-text-muted);
}

.inactive input {
  width: 20px;
  height: 20px;
}

.zero {
  color: var(--c-text-muted);
}
</style>
