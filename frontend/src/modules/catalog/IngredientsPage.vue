<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi, type Ingredient } from './api'
import { useCatalogRefs } from './useCatalogRefs'
import { useAsync } from '@/shared/ui/useAsync'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import { formatDecimal, parseDecimalInput, toInputDecimal } from '@/shared/format/decimal'
import { formatDateTime } from '@/shared/format/datetime'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FButton from '@/shared/ui/FButton.vue'
import FPanel from '@/shared/ui/FPanel.vue'
import FState from '@/shared/ui/FState.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import FSelectField from '@/shared/ui/FSelectField.vue'
import FSwitch from '@/shared/ui/FSwitch.vue'

const PAGE_SIZE = 25
const session = useSessionStore()
const canWrite = computed(() => session.can('catalog.write'))
const refs = useCatalogRefs('INGREDIENT')
const form = useForm()

const filters = reactive({ q: '', categoryId: '', status: 'active', page: 1 })
const list = useAsync(() =>
  catalogApi.ingredients.list({
    q: filters.q || undefined,
    categoryId: filters.categoryId || undefined,
    active: filters.status === 'all' ? undefined : filters.status === 'active',
    page: filters.page,
    limit: PAGE_SIZE,
  }),
)

let searchTimer: number | undefined
watch(() => filters.q, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    filters.page = 1
    void list.run()
  }, 300)
})
watch(() => [filters.categoryId, filters.status], () => {
  filters.page = 1
  void list.run()
})

const totalPages = computed(() => Math.max(1, Math.ceil((list.data.value?.meta.total ?? 0) / PAGE_SIZE)))
function goTo(page: number): void {
  filters.page = page
  void list.run()
}

const panelOpen = ref(false)
const editing = ref<Ingredient | null>(null)
const empty = () => ({
  name: '', categoryId: '', sku: '', stockUnitId: '', purchaseUnitId: '', purchaseToStockFactor: '',
  averageUnitCost: '', minimumStock: '', active: true,
})
const input = reactive(empty())

const stockUnit = computed(() => refs.unitById.value.get(input.stockUnitId))
const purchaseUnit = computed(() => refs.unitById.value.get(input.purchaseUnitId))
const needsFactor = computed(() => !!purchaseUnit.value && !!stockUnit.value && purchaseUnit.value.dimension !== stockUnit.value.dimension)

const costHistory = useAsync(() => catalogApi.ingredients.costHistory(editing.value!.id))

function open(ingredient: Ingredient | null): void {
  if (!canWrite.value) return
  editing.value = ingredient
  if (ingredient) void costHistory.run()
  Object.assign(input, ingredient
    ? {
        name: ingredient.name, categoryId: ingredient.categoryId ?? '', sku: ingredient.sku ?? '', stockUnitId: ingredient.stockUnitId,
        purchaseUnitId: ingredient.purchaseUnitId ?? '', purchaseToStockFactor: toInputDecimal(ingredient.purchaseToStockFactor),
        averageUnitCost: toInputDecimal(ingredient.averageUnitCost), minimumStock: toInputDecimal(ingredient.minimumStock), active: ingredient.active,
      }
    : { ...empty(), stockUnitId: refs.units.value.find((u) => u.code === 'G')?.id ?? '' })
  form.reset()
  panelOpen.value = true
}

async function save(): Promise<void> {
  const errors: Record<string, string[]> = {}
  const cost = parseDecimalInput(input.averageUnitCost || '0')
  const minimum = parseDecimalInput(input.minimumStock || '0')
  const factor = input.purchaseToStockFactor ? parseDecimalInput(input.purchaseToStockFactor) : null
  if (cost === null) errors.averageUnitCost = ['Informe um custo válido (ex.: 0,0045).']
  if (minimum === null) errors.minimumStock = ['Informe uma quantidade válida.']
  if (input.purchaseToStockFactor && factor === null) errors.purchaseToStockFactor = ['Informe um número válido.']
  if (Object.keys(errors).length > 0) {
    form.fieldErrors.value = errors
    return
  }

  // Se a unidade de compra for da mesma dimensão, o backend calcula o fator.
  const sendFactor = input.purchaseUnitId && needsFactor.value ? factor : null
  const payload = {
    name: input.name,
    categoryId: input.categoryId || null,
    sku: input.sku || null,
    stockUnitId: input.stockUnitId,
    purchaseUnitId: input.purchaseUnitId || null,
    purchaseToStockFactor: sendFactor,
    averageUnitCost: cost!,
    minimumStock: minimum!,
    active: input.active,
  }
  const saved = await form.submit(() => (editing.value ? catalogApi.ingredients.update(editing.value.id, payload) : catalogApi.ingredients.create(payload)))
  if (saved) {
    panelOpen.value = false
    notify(editing.value ? 'Ingrediente atualizado.' : 'Ingrediente cadastrado.')
    await list.run()
  }
}

onMounted(async () => {
  await Promise.all([refs.load(), list.run()])
})
</script>

<template>
  <PageHeader title="Ingredientes" subtitle="Insumos usados nas receitas. O estoque e o custo são controlados na unidade de estoque.">
    <template #actions>
      <FButton v-if="canWrite" @click="open(null)">+ Novo ingrediente</FButton>
    </template>
  </PageHeader>

  <div class="toolbar">
    <div class="grow">
      <label class="sr-only" for="ingredient-search">Buscar</label>
      <input id="ingredient-search" v-model="filters.q" class="f-input" type="search" inputmode="search" placeholder="Buscar por nome ou SKU" />
    </div>
    <label class="sr-only" for="ingredient-category">Categoria</label>
    <select id="ingredient-category" v-model="filters.categoryId" class="f-input filter">
      <option value="">Todas as categorias</option>
      <option v-for="c in refs.categoryOptions.value" :key="c.value" :value="c.value">{{ c.label }}</option>
    </select>
    <label class="sr-only" for="ingredient-status">Situação</label>
    <select id="ingredient-status" v-model="filters.status" class="f-input filter">
      <option value="active">Ativos</option>
      <option value="inactive">Inativos</option>
      <option value="all">Todos</option>
    </select>
  </div>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />
  <FState
    v-else-if="list.data.value?.meta.total === 0"
    kind="empty"
    :title="filters.q ? 'Nenhum ingrediente encontrado' : 'Nenhum ingrediente cadastrado'"
    :message="filters.q ? 'Tente outro termo de busca.' : 'Cadastre farinha, fermento, sal, açúcar e os demais insumos.'"
  >
    <FButton v-if="canWrite && !filters.q" @click="open(null)">Cadastrar ingrediente</FButton>
  </FState>

  <template v-else-if="list.data.value">
    <table class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
      <thead>
        <tr><th>Ingrediente</th><th>Categoria</th><th>Estoque em</th><th class="num">Custo / unidade</th><th class="num">Estoque mínimo</th><th>Situação</th></tr>
      </thead>
      <tbody>
        <tr v-for="i in list.data.value.data" :key="i.id" :class="{ 'is-clickable': canWrite }" :tabindex="canWrite ? 0 : undefined" @click="open(i)" @keydown.enter="open(i)">
          <td>
            <strong>{{ i.name }}</strong>
            <div v-if="i.sku" class="muted">{{ i.sku }}</div>
          </td>
          <td data-label="Categoria">{{ i.categoryId ? refs.categoryById.value.get(i.categoryId)?.name ?? '—' : '—' }}</td>
          <td data-label="Estoque em">{{ refs.unitById.value.get(i.stockUnitId)?.code ?? '—' }}</td>
          <td data-label="Custo / unidade" class="num">R$ {{ formatDecimal(i.averageUnitCost) }}</td>
          <td data-label="Estoque mínimo" class="num">{{ formatDecimal(i.minimumStock) }} {{ refs.unitById.value.get(i.stockUnitId)?.code }}</td>
          <td><span class="badge" :class="i.active ? 'is-success' : ''">{{ i.active ? 'Ativo' : 'Inativo' }}</span></td>
        </tr>
      </tbody>
    </table>
    <nav v-if="totalPages > 1" class="pagination" aria-label="Paginação">
      <FButton variant="secondary" :disabled="filters.page <= 1" @click="goTo(filters.page - 1)">Anterior</FButton>
      <span>Página {{ filters.page }} de {{ totalPages }}</span>
      <FButton variant="secondary" :disabled="filters.page >= totalPages" @click="goTo(filters.page + 1)">Próxima</FButton>
    </nav>
  </template>

  <FPanel :open="panelOpen" :title="editing ? 'Editar ingrediente' : 'Novo ingrediente'" @close="panelOpen = false">
    <form id="ingredient-form" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="form-grid">
        <FTextField v-model="input.name" class="full" label="Nome" required :error="form.fieldError('name')" :maxlength="160" />
        <FSelectField v-model="input.categoryId" label="Categoria" :options="refs.categoryOptions.value" placeholder="Sem categoria" :error="form.fieldError('categoryId')" />
        <FTextField v-model="input.sku" label="Código interno (SKU)" :error="form.fieldError('sku')" :maxlength="64" />
        <FSelectField
          v-model="input.stockUnitId"
          label="Unidade de estoque"
          :options="refs.unitOptions.value"
          placeholder="Selecione"
          required
          hint="Receitas e estoque usarão esta unidade."
          :error="form.fieldError('stockUnitId')"
        />
        <FTextField
          v-model="input.averageUnitCost"
          :label="`Custo por ${stockUnit?.code ?? 'unidade'} (R$)`"
          inputmode="decimal"
          placeholder="0,0045"
          :hint="editing ? 'Alterar aqui substitui o custo médio e fica no histórico. Entradas de estoque com custo recalculam a média.' : 'Até 6 casas decimais.'"
          :error="form.fieldError('averageUnitCost')"
        />
        <FSelectField
          v-model="input.purchaseUnitId"
          label="Unidade de compra"
          :options="refs.unitOptions.value"
          placeholder="Igual à de estoque"
          :error="form.fieldError('purchaseUnitId')"
        />
        <FTextField
          v-if="needsFactor"
          v-model="input.purchaseToStockFactor"
          :label="`Quantos ${stockUnit?.code} em 1 ${purchaseUnit?.code}`"
          inputmode="decimal"
          required
          hint="Ex.: 1 dúzia de ovos ≈ 600 g"
          :error="form.fieldError('purchaseToStockFactor')"
        />
        <p v-else-if="purchaseUnit && stockUnit" class="auto-factor">Conversão automática: 1 {{ purchaseUnit.code }} → {{ stockUnit.code }}.</p>
        <FTextField
          v-model="input.minimumStock"
          :label="`Estoque mínimo (${stockUnit?.code ?? 'unid.'})`"
          inputmode="decimal"
          hint="Usado nos alertas de reposição."
          :error="form.fieldError('minimumStock')"
        />
        <FSwitch v-if="editing" v-model="input.active" class="full" label="Ingrediente ativo" />
      </div>
    </form>

    <section v-if="editing" class="cost-history" aria-labelledby="cost-history-title">
      <h3 id="cost-history-title">Histórico de custo</h3>
      <p v-if="costHistory.loading.value && !costHistory.data.value" class="muted">Carregando…</p>
      <p v-else-if="costHistory.error.value" class="form-error">{{ costHistory.error.value }}</p>
      <ol v-else-if="costHistory.data.value" class="changes">
        <li v-for="c in costHistory.data.value.data" :key="c.id">
          <strong>R$ {{ formatDecimal(c.newCost) }}/{{ stockUnit?.code }}</strong>
          <span v-if="c.previousCost !== null" class="muted"> (antes R$ {{ formatDecimal(c.previousCost) }})</span>
          <div class="muted">{{ c.sourceLabel }} · {{ formatDateTime(c.changedAt, session.tenant?.timezone) }}<template v-if="c.changedBy?.name"> · {{ c.changedBy.name }}</template></div>
        </li>
      </ol>
    </section>
    <template #footer>
      <FButton variant="secondary" @click="panelOpen = false">Cancelar</FButton>
      <FButton type="submit" form="ingredient-form" :loading="form.submitting.value">Salvar</FButton>
    </template>
  </FPanel>
</template>

<style scoped>
.filter {
  width: auto;
  min-width: 180px;
}

.cost-history {
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--c-border);
}

.cost-history h3 {
  font-size: 1rem;
  margin-bottom: 0.5rem;
}

.changes {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.changes .muted {
  color: var(--c-text-muted);
  font-size: 0.88rem;
}

.auto-factor {
  align-self: end;
  margin: 0 0 0.6rem;
  color: var(--c-text-muted);
  font-size: 0.9rem;
}
</style>
