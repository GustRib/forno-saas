<script setup lang="ts">
/**
 * Ficha técnica de um produto: edição do rascunho, publicação (ativação),
 * versões anteriores somente leitura, custo estimado e histórico de custo.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi, type Ingredient, type Product, type Unit } from '@/modules/catalog/api'
import { recipesApi, type CostSnapshot, type RecipeDetail } from './api'
import { emptyRecipeForm, formFromDetail, toRecipePayload, type RecipeFormState } from './recipeForm'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import { ApiError } from '@/shared/api/http'
import { formatDecimal, formatMoney } from '@/shared/format/decimal'
import { formatDateTime } from '@/shared/format/datetime'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FButton from '@/shared/ui/FButton.vue'
import FState from '@/shared/ui/FState.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import FSelectField from '@/shared/ui/FSelectField.vue'
import RecipeCostSummary from './RecipeCostSummary.vue'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const canWrite = computed(() => session.can('recipe.write'))
const timeZone = computed(() => session.tenant?.timezone ?? 'America/Sao_Paulo')

const loading = ref(true)
const loadError = ref('')
const detail = ref<RecipeDetail | null>(null)
const product = ref<Product | null>(null)
const units = ref<Unit[]>([])
const ingredients = ref<Ingredient[]>([])
const snapshots = ref<CostSnapshot[]>([])
const state = reactive<RecipeFormState>(emptyRecipeForm())
const form = useForm()
const busy = ref(false)

const isNew = computed(() => route.name === 'recipe-new')
const editable = computed(() => canWrite.value && (isNew.value || detail.value?.status === 'DRAFT'))
const unitById = computed(() => new Map(units.value.map((u) => [u.id, u])))
const ingredientById = computed(() => new Map(ingredients.value.map((i) => [i.id, i])))
const saleUnit = computed(() => (product.value ? unitById.value.get(product.value.saleUnitId) : undefined))
const hasDraft = computed(() => detail.value?.versions.some((v) => v.status === 'DRAFT') ?? false)

const yieldUnitOptions = computed(() =>
  units.value.filter((u) => !saleUnit.value || u.dimension === saleUnit.value.dimension).map((u) => ({ value: u.id, label: `${u.name} (${u.code})` })),
)
const ingredientOptions = computed(() => ingredients.value.map((i) => ({ value: i.id, label: i.active ? i.name : `${i.name} (inativo)` })))

/** Só unidades da mesma dimensão da unidade de estoque do ingrediente (o backend valida de novo). */
function unitOptionsFor(ingredientId: string) {
  const stockUnit = unitById.value.get(ingredientById.value.get(ingredientId)?.stockUnitId ?? '')
  return units.value.filter((u) => !stockUnit || u.dimension === stockUnit.dimension).map((u) => ({ value: u.id, label: u.code }))
}

function onIngredientChange(index: number): void {
  const line = state.items[index]
  if (!line) return
  const stockUnitId = ingredientById.value.get(line.ingredientId)?.stockUnitId ?? ''
  if (!unitOptionsFor(line.ingredientId).some((o) => o.value === line.unitId)) line.unitId = stockUnitId
}

function addItem(): void {
  state.items.push({ ingredientId: '', quantity: '', unitId: '', notes: '' })
}

function addCost(): void {
  state.additionalCosts.push({ description: '', amount: '' })
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const [u, i] = await Promise.all([catalogApi.units.list(), catalogApi.ingredients.list({ limit: 100 })])
    units.value = u
    ingredients.value = i.data
    if (isNew.value) {
      detail.value = null
      snapshots.value = []
      product.value = await catalogApi.products.get(String(route.params.productId))
      Object.assign(state, emptyRecipeForm(product.value.saleUnitId), { name: product.value.name })
    } else {
      const d = await recipesApi.get(String(route.params.id))
      detail.value = d
      Object.assign(state, formFromDetail(d))
      const [p, s] = await Promise.all([catalogApi.products.get(d.productId), recipesApi.snapshots(d.id)])
      product.value = p
      snapshots.value = s
    }
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Não foi possível carregar a ficha técnica.'
  } finally {
    loading.value = false
  }
}

watch(() => route.fullPath, load, { immediate: true })

async function save(): Promise<RecipeDetail | undefined> {
  const { payload, errors } = toRecipePayload(state, isNew.value ? product.value?.id : undefined)
  if (Object.keys(errors).length > 0) {
    form.fieldErrors.value = errors
    form.formError.value = 'Revise os campos destacados.'
    return undefined
  }
  const saved = await form.submit(() => (isNew.value ? recipesApi.create(payload) : recipesApi.update(detail.value!.id, payload)))
  if (saved) {
    if (isNew.value) {
      notify('Rascunho criado.')
      await router.replace(`/receitas/${saved.id}`)
    } else {
      detail.value = saved
      Object.assign(state, formFromDetail(saved))
      notify('Rascunho salvo.')
    }
  }
  return saved
}

async function saveAndActivate(): Promise<void> {
  const saved = await save()
  if (!saved) return
  const activated = await form.submit(() => recipesApi.activate(saved.id))
  if (activated) {
    notify(`Versão ${activated.version} ativada. O custo foi registrado no histórico.`)
    await load()
  }
}

async function run(action: () => Promise<unknown>, success: string): Promise<void> {
  busy.value = true
  try {
    await action()
    notify(success)
  } catch (e) {
    notify(e instanceof ApiError ? e.message : 'Não foi possível concluir.', 'error')
  } finally {
    busy.value = false
  }
}

async function newVersion(): Promise<void> {
  await run(async () => {
    const draft = await recipesApi.newVersion(detail.value!.id)
    await router.push(`/receitas/${draft.id}`)
  }, 'Nova versão criada como rascunho.')
}

async function discardDraft(): Promise<void> {
  if (!detail.value || !window.confirm('Descartar este rascunho? As versões publicadas não são afetadas.')) return
  await run(async () => {
    await recipesApi.remove(detail.value!.id)
    await router.replace('/receitas')
  }, 'Rascunho descartado.')
}

async function recordSnapshot(): Promise<void> {
  await run(async () => {
    await recipesApi.recordSnapshot(detail.value!.id)
    snapshots.value = await recipesApi.snapshots(detail.value!.id)
  }, 'Custo atual registrado no histórico.')
}

function fieldError(name: string): string | undefined {
  return form.fieldError(name)
}
</script>

<template>
  <FState v-if="loading" kind="loading" />
  <FState v-else-if="loadError" kind="error" :message="loadError" @retry="load" />

  <template v-else>
    <PageHeader
      :title="isNew ? `Nova ficha técnica · ${product?.name ?? ''}` : `${detail?.name} · v${detail?.version}`"
      :subtitle="product ? `Produto: ${product.name} · vendido por ${saleUnit?.code ?? ''}` : undefined"
    >
      <template #actions>
        <RouterLink to="/receitas" class="back">← Fichas técnicas</RouterLink>
      </template>
    </PageHeader>

    <div v-if="detail" class="status-bar">
      <span class="badge" :class="{ 'is-success': detail.status === 'ACTIVE', 'is-warning': detail.status === 'DRAFT' }">{{ detail.statusLabel }}</span>
      <span v-if="detail.effectiveFrom" class="muted">Vigente desde {{ detail.effectiveFrom.split('-').reverse().join('/') }}</span>
      <nav class="versions" aria-label="Versões">
        <RouterLink v-for="v in detail.versions" :key="v.id" :to="`/receitas/${v.id}`" class="version" :class="{ current: v.id === detail.id }">
          v{{ v.version }} · {{ v.statusLabel }}
        </RouterLink>
      </nav>
      <div class="bar-actions">
        <FButton v-if="canWrite && detail.status !== 'DRAFT' && !hasDraft" variant="secondary" :loading="busy" @click="newVersion">Criar nova versão</FButton>
      </div>
    </div>
    <p v-if="detail && detail.status !== 'DRAFT'" class="readonly-note">
      Versões publicadas não mudam. Para alterar a receita, crie uma nova versão; esta continua no histórico.
    </p>

    <!-- Edição do rascunho -->
    <form v-if="editable" class="editor" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>

      <section class="card">
        <div class="form-grid">
          <FTextField v-model="state.name" class="full" label="Nome da ficha técnica" required :maxlength="160" :error="fieldError('name')" />
          <FTextField v-model="state.expectedYieldQty" label="Rendimento por lote" inputmode="decimal" required :error="fieldError('expectedYieldQty')" />
          <FSelectField v-model="state.yieldUnitId" label="Unidade do rendimento" :options="yieldUnitOptions" placeholder="Selecione" :error="fieldError('yieldUnitId')" />
          <FTextField v-model="state.instructions" class="full" label="Modo de preparo" multiline :maxlength="10000" hint="Opcional." :error="fieldError('instructions')" />
        </div>
      </section>

      <section class="card">
        <h2>Ingredientes por lote</h2>
        <p v-if="state.items.length === 0" class="muted">Nenhum ingrediente ainda.</p>
        <div v-for="(item, index) in state.items" :key="index" class="line">
          <FSelectField
            v-model="item.ingredientId"
            class="grow"
            label="Ingrediente"
            :options="ingredientOptions"
            placeholder="Selecione"
            :error="fieldError(`items[${index}][ingredientId]`)"
            @update:model-value="onIngredientChange(index)"
          />
          <FTextField v-model="item.quantity" class="qty" label="Quantidade" inputmode="decimal" :error="fieldError(`items[${index}][quantity]`)" />
          <FSelectField v-model="item.unitId" class="unit" label="Unidade" :options="unitOptionsFor(item.ingredientId)" placeholder="—" :error="fieldError(`items[${index}][unitId]`)" />
          <FTextField v-model="item.notes" class="notes" label="Observação" :maxlength="255" :error="fieldError(`items[${index}][notes]`)" />
          <button type="button" class="remove" :aria-label="`Remover ingrediente ${index + 1}`" @click="state.items.splice(index, 1)">✕</button>
        </div>
        <FButton variant="secondary" @click="addItem">+ Ingrediente</FButton>
      </section>

      <section class="card">
        <h2>Custos adicionais por lote</h2>
        <p class="muted">Embalagem, energia, mão de obra… Ficam separados do custo dos ingredientes.</p>
        <div v-for="(cost, index) in state.additionalCosts" :key="index" class="line">
          <FTextField v-model="cost.description" class="grow" label="Descrição" :maxlength="120" :error="fieldError(`additionalCosts[${index}][description]`)" />
          <FTextField v-model="cost.amount" class="qty" label="Valor (R$)" inputmode="decimal" :error="fieldError(`additionalCosts[${index}][amount]`)" />
          <button type="button" class="remove" :aria-label="`Remover custo ${index + 1}`" @click="state.additionalCosts.splice(index, 1)">✕</button>
        </div>
        <FButton variant="secondary" @click="addCost">+ Custo adicional</FButton>
      </section>

      <div class="editor-actions">
        <FButton v-if="detail" variant="ghost" class="danger-link" :loading="busy" @click="discardDraft">Descartar rascunho</FButton>
        <FButton type="submit" variant="secondary" :loading="form.submitting.value">Salvar rascunho</FButton>
        <FButton :loading="form.submitting.value" @click="saveAndActivate">Salvar e ativar</FButton>
      </div>
    </form>

    <!-- Versão publicada (ou sem permissão de edição): somente leitura -->
    <section v-else-if="detail" class="card">
      <h2>Ingredientes por lote · rende {{ formatDecimal(detail.expectedYieldQty) }} {{ detail.yieldUnit?.code }}</h2>
      <table class="f-table">
        <thead><tr><th>Ingrediente</th><th class="num">Quantidade</th><th>Observação</th></tr></thead>
        <tbody>
          <tr v-for="item in detail.items" :key="item.id">
            <td>{{ item.ingredientName }}</td>
            <td data-label="Quantidade" class="num">{{ formatDecimal(item.quantity) }} {{ item.unit?.code }}</td>
            <td data-label="Observação" class="muted">{{ item.notes ?? '' }}</td>
          </tr>
        </tbody>
      </table>
      <template v-if="detail.additionalCosts.length">
        <h3>Custos adicionais</h3>
        <ul class="plain">
          <li v-for="c in detail.additionalCosts" :key="c.id">{{ c.description }}: {{ formatMoney(c.amount) }}</li>
        </ul>
      </template>
      <template v-if="detail.instructions">
        <h3>Modo de preparo</h3>
        <p class="instructions">{{ detail.instructions }}</p>
      </template>
    </section>

    <section v-if="detail" class="card">
      <RecipeCostSummary
        :cost="detail.cost"
        :yield-qty="detail.expectedYieldQty"
        :yield-unit-code="detail.yieldUnit?.code ?? ''"
        :sale-unit-code="detail.product?.saleUnit.code ?? null"
        :time-zone="timeZone"
      />
      <p v-if="editable" class="muted">O custo é recalculado ao salvar o rascunho.</p>
    </section>

    <section v-if="detail && detail.status !== 'DRAFT'" class="card">
      <div class="history-head">
        <h2>Histórico de custo</h2>
        <FButton v-if="canWrite && detail.status === 'ACTIVE'" variant="secondary" :loading="busy" @click="recordSnapshot">Registrar custo atual</FButton>
      </div>
      <p v-if="snapshots.length === 0" class="muted">Nenhum registro.</p>
      <table v-else class="f-table">
        <thead><tr><th>Data</th><th>Motivo</th><th class="num">Total do lote</th><th class="num">Por {{ detail.yieldUnit?.code }}</th></tr></thead>
        <tbody>
          <tr v-for="s in snapshots" :key="s.id">
            <td>{{ formatDateTime(s.createdAt, timeZone) }}</td>
            <td data-label="Motivo">{{ s.reason === 'ACTIVATION' ? 'Ativação da versão' : 'Registro manual' }} <span class="muted">({{ s.costPolicy }})</span></td>
            <td data-label="Total do lote" class="num">{{ formatMoney(s.totalCost) }}</td>
            <td :data-label="`Por ${detail.yieldUnit?.code}`" class="num">R$ {{ formatDecimal(s.costPerYieldUnit) }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </template>
</template>

<style scoped>
.back {
  align-self: center;
}

.status-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.versions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.version {
  display: inline-flex;
  align-items: center;
  min-height: 36px;
  padding: 0 0.75rem;
  border: 1px solid var(--c-border-strong);
  border-radius: 999px;
  text-decoration: none;
  font-size: 0.88rem;
  color: var(--c-text);
}

.version.current {
  background: var(--c-primary);
  border-color: var(--c-primary);
  color: #fff;
}

.bar-actions {
  margin-left: auto;
}

.readonly-note {
  margin: 0 0 1rem;
  color: var(--c-text-muted);
}

.card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  padding: 1.25rem;
  margin-bottom: 1rem;
}

.card h2 {
  font-size: 1.1rem;
  margin-bottom: 0.75rem;
}

.card h3 {
  font-size: 1rem;
  margin: 1rem 0 0.5rem;
}

.line {
  display: grid;
  grid-template-columns: minmax(180px, 2fr) minmax(90px, 1fr) minmax(80px, 0.8fr) minmax(140px, 1.5fr) auto;
  gap: 0.75rem;
  align-items: start;
  margin-bottom: 0.75rem;
}

.card .line:has(> :nth-child(3):last-child) {
  grid-template-columns: minmax(180px, 3fr) minmax(110px, 1fr) auto;
}

@media (max-width: 760px) {
  .line,
  .card .line:has(> :nth-child(3):last-child) {
    grid-template-columns: 1fr 1fr;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--c-border);
  }

  .line .grow {
    grid-column: 1 / -1;
  }
}

.remove {
  margin-top: 1.7rem;
  width: var(--touch);
  height: var(--touch);
  border: 1px solid var(--c-border-strong);
  border-radius: var(--radius-sm);
  background: var(--c-surface);
  cursor: pointer;
}

.editor-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.danger-link {
  color: var(--c-danger);
  margin-right: auto;
}

.history-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.history-head h2 {
  margin: 0;
}

.plain {
  margin: 0;
  padding-left: 1.2rem;
}

.instructions {
  white-space: pre-wrap;
  margin: 0;
}
</style>
