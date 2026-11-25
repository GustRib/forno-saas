<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi, productionTypeLabels, type Product, type ProductionType } from './api'
import { useCatalogRefs } from './useCatalogRefs'
import { useAsync } from '@/shared/ui/useAsync'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import { formatMoney, parseDecimalInput, toInputDecimal } from '@/shared/format/decimal'
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
const refs = useCatalogRefs('SELLABLE')
const form = useForm()

const filters = reactive({ q: '', categoryId: '', status: 'active', page: 1 })
const list = useAsync(() =>
  catalogApi.products.list({
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

const typeOptions = Object.entries(productionTypeLabels).map(([value, label]) => ({ value, label }))
const statusOptions = [
  { value: 'active', label: 'Ativos' },
  { value: 'inactive', label: 'Inativos' },
  { value: 'all', label: 'Todos' },
]

const panelOpen = ref(false)
const editing = ref<Product | null>(null)
const empty = () => ({
  name: '', categoryId: '', sku: '', barcode: '', description: '', saleUnitId: '',
  productionType: 'MANUFACTURED' as ProductionType, salePrice: '', stockTracked: true, active: true,
})
const input = reactive(empty())

function open(product: Product | null): void {
  if (!canWrite.value) return
  editing.value = product
  Object.assign(input, product
    ? {
        name: product.name, categoryId: product.categoryId ?? '', sku: product.sku ?? '', barcode: product.barcode ?? '',
        description: product.description ?? '', saleUnitId: product.saleUnitId, productionType: product.productionType,
        salePrice: toInputDecimal(product.salePrice), stockTracked: product.stockTracked, active: product.active,
      }
    : { ...empty(), saleUnitId: refs.units.value.find((u) => u.code === 'UN')?.id ?? '' })
  form.reset()
  panelOpen.value = true
}

async function save(): Promise<void> {
  const salePrice = parseDecimalInput(input.salePrice, 2)
  if (salePrice === null) {
    form.fieldErrors.value = { salePrice: ['Informe o preço (ex.: 18,90).'] }
    return
  }
  const payload = {
    ...input,
    salePrice,
    categoryId: input.categoryId || null,
    sku: input.sku || null,
    barcode: input.barcode || null,
    description: input.description || null,
    stockTracked: input.productionType !== 'SERVICE' && input.stockTracked,
  }
  const saved = await form.submit(() => (editing.value ? catalogApi.products.update(editing.value.id, payload) : catalogApi.products.create(payload)))
  if (saved) {
    panelOpen.value = false
    notify(editing.value ? 'Produto atualizado.' : 'Produto cadastrado.')
    await list.run()
  }
}

onMounted(async () => {
  await Promise.all([refs.load(), list.run()])
})
</script>

<template>
  <PageHeader title="Produtos" subtitle="Itens vendidos no balcão, mesas e encomendas: fabricados aqui, revenda ou serviços.">
    <template #actions>
      <FButton v-if="canWrite" @click="open(null)">+ Novo produto</FButton>
    </template>
  </PageHeader>

  <div class="toolbar">
    <div class="grow">
      <label class="sr-only" for="product-search">Buscar</label>
      <input id="product-search" v-model="filters.q" class="f-input" type="search" inputmode="search" placeholder="Buscar por nome, SKU ou código de barras" />
    </div>
    <label class="sr-only" for="product-category">Categoria</label>
    <select id="product-category" v-model="filters.categoryId" class="f-input filter">
      <option value="">Todas as categorias</option>
      <option v-for="c in refs.categoryOptions.value" :key="c.value" :value="c.value">{{ c.label }}</option>
    </select>
    <label class="sr-only" for="product-status">Situação</label>
    <select id="product-status" v-model="filters.status" class="f-input filter">
      <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
    </select>
  </div>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />
  <FState
    v-else-if="list.data.value?.meta.total === 0"
    kind="empty"
    :title="filters.q ? 'Nenhum produto encontrado' : 'Nenhum produto cadastrado'"
    :message="filters.q ? 'Tente outro termo de busca.' : 'Cadastre os itens que você vende, como pão francês (kg) ou sonho (un).'"
  >
    <FButton v-if="canWrite && !filters.q" @click="open(null)">Cadastrar produto</FButton>
  </FState>

  <template v-else-if="list.data.value">
    <table class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
      <thead>
        <tr><th>Produto</th><th>Categoria</th><th>Tipo</th><th>Unidade</th><th class="num">Preço</th><th>Situação</th></tr>
      </thead>
      <tbody>
        <tr v-for="p in list.data.value.data" :key="p.id" :class="{ 'is-clickable': canWrite }" :tabindex="canWrite ? 0 : undefined" @click="open(p)" @keydown.enter="open(p)">
          <td>
            <strong>{{ p.name }}</strong>
            <div v-if="p.sku || p.barcode" class="muted">{{ [p.sku, p.barcode].filter(Boolean).join(' · ') }}</div>
          </td>
          <td data-label="Categoria">{{ p.categoryId ? refs.categoryById.value.get(p.categoryId)?.name ?? '—' : '—' }}</td>
          <td data-label="Tipo">{{ productionTypeLabels[p.productionType] }}</td>
          <td data-label="Unidade">{{ refs.unitById.value.get(p.saleUnitId)?.code ?? '—' }}</td>
          <td data-label="Preço" class="num">{{ formatMoney(p.salePrice) }}</td>
          <td><span class="badge" :class="p.active ? 'is-success' : ''">{{ p.active ? 'Ativo' : 'Inativo' }}</span></td>
        </tr>
      </tbody>
    </table>
    <nav v-if="totalPages > 1" class="pagination" aria-label="Paginação">
      <FButton variant="secondary" :disabled="filters.page <= 1" @click="goTo(filters.page - 1)">Anterior</FButton>
      <span>Página {{ filters.page }} de {{ totalPages }}</span>
      <FButton variant="secondary" :disabled="filters.page >= totalPages" @click="goTo(filters.page + 1)">Próxima</FButton>
    </nav>
  </template>

  <FPanel :open="panelOpen" :title="editing ? 'Editar produto' : 'Novo produto'" @close="panelOpen = false">
    <form id="product-form" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="form-grid">
        <FTextField v-model="input.name" class="full" label="Nome" required :error="form.fieldError('name')" :maxlength="160" />
        <FSelectField v-model="input.productionType" label="Tipo" :options="typeOptions" :error="form.fieldError('productionType')" />
        <FSelectField v-model="input.categoryId" label="Categoria" :options="refs.categoryOptions.value" placeholder="Sem categoria" :error="form.fieldError('categoryId')" />
        <FSelectField v-model="input.saleUnitId" label="Vendido por" :options="refs.unitOptions.value" placeholder="Selecione" required :error="form.fieldError('saleUnitId')" />
        <FTextField v-model="input.salePrice" label="Preço de venda (R$)" inputmode="decimal" placeholder="0,00" required :error="form.fieldError('salePrice')" />
        <FTextField v-model="input.sku" label="Código interno (SKU)" :error="form.fieldError('sku')" :maxlength="64" />
        <FTextField v-model="input.barcode" label="Código de barras" inputmode="numeric" :error="form.fieldError('barcode')" :maxlength="64" />
        <FTextField v-model="input.description" class="full" label="Descrição" multiline :error="form.fieldError('description')" :maxlength="2000" />
        <FSwitch
          v-model="input.stockTracked"
          class="full"
          label="Controlar estoque"
          :disabled="input.productionType === 'SERVICE'"
          :hint="input.productionType === 'SERVICE' ? 'Serviços não têm estoque.' : 'As vendas darão baixa no estoque deste produto.'"
        />
        <FSwitch v-if="editing" v-model="input.active" class="full" label="Produto ativo" hint="Inativos não aparecem para venda." />
      </div>
    </form>
    <template #footer>
      <FButton variant="secondary" @click="panelOpen = false">Cancelar</FButton>
      <FButton type="submit" form="product-form" :loading="form.submitting.value">Salvar</FButton>
    </template>
  </FPanel>
</template>

<style scoped>
.filter {
  width: auto;
  min-width: 180px;
}
</style>
