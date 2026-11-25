<script setup lang="ts">
/**
 * Fichas técnicas por produto fabricado: versão ativa, rascunho em andamento
 * ou "sem ficha técnica".
 */
import { computed, onMounted } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi } from '@/modules/catalog/api'
import { recipesApi, type RecipeSummary } from './api'
import { useAsync } from '@/shared/ui/useAsync'
import { formatDecimal } from '@/shared/format/decimal'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FState from '@/shared/ui/FState.vue'

const session = useSessionStore()
const canWrite = computed(() => session.can('recipe.write'))

const data = useAsync(async () => {
  const [products, recipes] = await Promise.all([
    catalogApi.products.list({ productionType: 'MANUFACTURED', limit: 100 }),
    recipesApi.list({ limit: 100 }),
  ])
  return { products: products.data, recipes: recipes.data }
})

const rows = computed(() =>
  (data.data.value?.products ?? []).map((product) => {
    const recipes = (data.data.value?.recipes ?? []).filter((r) => r.productId === product.id)
    const active = recipes.find((r) => r.status === 'ACTIVE') ?? null
    const draft = recipes.find((r) => r.status === 'DRAFT') ?? null
    return { product, active, draft, versions: recipes.length }
  }),
)

function versionText(r: RecipeSummary): string {
  return `v${r.version} · rende ${formatDecimal(r.expectedYieldQty)} ${r.yieldUnit?.code ?? ''}`
}

onMounted(data.run)
</script>

<template>
  <PageHeader
    title="Fichas técnicas"
    subtitle="Receita de cada produto fabricado aqui, com ingredientes, rendimento e custo estimado. Cada alteração publicada vira uma nova versão."
  />

  <FState v-if="data.loading.value && !data.data.value" kind="loading" />
  <FState v-else-if="data.error.value" kind="error" :message="data.error.value" @retry="data.run" />
  <FState
    v-else-if="rows.length === 0"
    kind="empty"
    title="Nenhum produto fabricado"
    message="Fichas técnicas são criadas para produtos do tipo “Fabricado aqui”."
  >
    <RouterLink to="/produtos">Ir para produtos</RouterLink>
  </FState>

  <table v-else class="f-table" :class="{ 'is-refreshing': data.loading.value }">
    <thead>
      <tr><th>Produto</th><th>Versão ativa</th><th>Em edição</th><th></th></tr>
    </thead>
    <tbody>
      <tr v-for="row in rows" :key="row.product.id">
        <td><strong>{{ row.product.name }}</strong></td>
        <td data-label="Versão ativa">
          <RouterLink v-if="row.active" :to="`/receitas/${row.active.id}`">{{ versionText(row.active) }}</RouterLink>
          <span v-else class="muted">Sem ficha técnica ativa</span>
        </td>
        <td data-label="Em edição">
          <RouterLink v-if="row.draft" :to="`/receitas/${row.draft.id}`" class="badge is-warning">Rascunho v{{ row.draft.version }}</RouterLink>
          <span v-else class="muted">—</span>
        </td>
        <td>
          <RouterLink v-if="canWrite && row.versions === 0" :to="`/receitas/nova/${row.product.id}`">Criar ficha técnica</RouterLink>
        </td>
      </tr>
    </tbody>
  </table>
</template>
