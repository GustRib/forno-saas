<script setup lang="ts">
/**
 * Início: enquanto vendas, produção e estoque não existem, mostra o roteiro
 * de implantação (PRODUCT §7.1) com contagens reais — sem métricas fictícias.
 */
import { computed, onMounted } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi } from '@/modules/catalog/api'
import { membersApi } from '@/modules/members/api'
import { inventoryApi } from '@/modules/inventory/api'
import { useAsync } from '@/shared/ui/useAsync'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FState from '@/shared/ui/FState.vue'

const session = useSessionStore()

const counts = useAsync(async () => {
  const canReadCatalog = session.can('catalog.read')
  const [categories, units, products, ingredients, members] = await Promise.all([
    canReadCatalog ? catalogApi.categories.list() : Promise.resolve(null),
    canReadCatalog ? catalogApi.units.list() : Promise.resolve(null),
    canReadCatalog ? catalogApi.products.list({ limit: 1 }) : Promise.resolve(null),
    canReadCatalog ? catalogApi.ingredients.list({ limit: 1 }) : Promise.resolve(null),
    session.can('members.read') ? membersApi.list() : Promise.resolve(null),
  ])
  // Ingredientes com saldo lançado (até 100: suficiente para o roteiro de implantação).
  const stocked = session.can('inventory.read')
    ? (await inventoryApi.balances({ itemType: 'INGREDIENT', limit: 100 })).data.filter((r) => r.quantity !== '0').length
    : null
  return {
    categories: categories?.length ?? null,
    units: units?.length ?? null,
    products: products?.meta.total ?? null,
    ingredients: ingredients?.meta.total ?? null,
    members: members?.length ?? null,
    stocked,
  }
})

interface Step {
  title: string
  description: string
  to: string
  count: number | null
  done: boolean
}

const steps = computed<Step[]>(() => {
  const c = counts.data.value
  if (!c) return []
  const all: Step[] = [
    { title: 'Revise as unidades de medida', description: 'Já criamos g, kg, ml, L, unidade e dúzia. Inclua sacos, caixas ou fatias se precisar.', to: '/unidades', count: c.units, done: (c.units ?? 0) > 0 },
    { title: 'Crie as categorias', description: 'Separe produtos (pães, doces, salgados) e ingredientes (farinhas, laticínios).', to: '/categorias', count: c.categories, done: (c.categories ?? 0) > 0 },
    { title: 'Cadastre os ingredientes', description: 'Com unidade de estoque e custo: a base para calcular o custo das receitas.', to: '/ingredientes', count: c.ingredients, done: (c.ingredients ?? 0) > 0 },
    { title: 'Cadastre os produtos', description: 'Tudo o que você vende, com preço e unidade (kg ou unidade).', to: '/produtos', count: c.products, done: (c.products ?? 0) > 0 },
    { title: 'Informe o estoque inicial', description: 'Conte o que há hoje de cada ingrediente e lance o saldo inicial.', to: '/estoque', count: c.stocked, done: (c.stocked ?? 0) > 0 },
    { title: 'Monte a equipe', description: 'Adicione gerente, caixa e produção, cada um com o acesso que precisa.', to: '/equipe', count: c.members, done: (c.members ?? 0) > 1 },
  ]
  return all.filter((s) => s.count !== null)
})

const progress = computed(() => {
  const total = steps.value.length
  return total === 0 ? 0 : Math.round((steps.value.filter((s) => s.done).length / total) * 100)
})

onMounted(counts.run)
</script>

<template>
  <PageHeader :title="`Olá, ${session.user?.name?.split(' ')[0] ?? ''}`" :subtitle="`Vamos preparar a ${session.tenant?.name ?? 'sua padaria'} para o dia a dia.`" />

  <FState v-if="!session.tenant" kind="empty" title="Nenhum estabelecimento ativo" message="Peça ao proprietário para incluir você na equipe." />
  <FState v-else-if="counts.loading.value && !counts.data.value" kind="loading" />
  <FState v-else-if="counts.error.value" kind="error" :message="counts.error.value" @retry="counts.run" />

  <template v-else-if="counts.data.value">
    <section class="card" aria-labelledby="setup-title">
      <div class="card-head">
        <h2 id="setup-title">Preparar a padaria</h2>
        <span class="progress-label">{{ progress }}% concluído</span>
      </div>
      <div class="progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
        <div class="bar" :style="{ width: `${progress}%` }"></div>
      </div>

      <ol class="steps">
        <li v-for="step in steps" :key="step.to" :class="{ done: step.done }">
          <span class="check" aria-hidden="true">{{ step.done ? '✓' : '' }}</span>
          <div class="step-text">
            <RouterLink :to="step.to" class="step-title">{{ step.title }}</RouterLink>
            <p>{{ step.description }}</p>
          </div>
          <span class="count">{{ step.count }}</span>
        </li>
      </ol>
    </section>

    <section class="card next" aria-labelledby="next-title">
      <h2 id="next-title">Próximas etapas do Forno</h2>
      <p>
        O estoque já registra saldo inicial, perdas e ajustes com histórico, e as fichas técnicas calculam o custo estimado.
        Em seguida chegam a <strong>produção por lote</strong> e o <strong>caixa com vendas no balcão</strong>. Este painel passará a mostrar vendas do dia,
        produção pendente e alertas de estoque.
      </p>
    </section>
  </template>
</template>

<style scoped>
.card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  padding: 1.5rem;
  margin-bottom: 1.5rem;
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 1rem;
}

h2 {
  font-size: 1.15rem;
}

.progress-label {
  color: var(--c-text-muted);
  font-variant-numeric: tabular-nums;
}

.progress {
  height: 8px;
  border-radius: 999px;
  background: var(--c-surface-2);
  margin: 0.75rem 0 1.25rem;
  overflow: hidden;
}

.bar {
  height: 100%;
  background: var(--c-primary);
  transition: width 0.3s;
}

.steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.steps li {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-sm);
}

.steps li.done {
  background: var(--c-success-soft);
  border-color: #c8e8d2;
}

.check {
  flex: none;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid var(--c-border-strong);
  display: grid;
  place-items: center;
  font-weight: 700;
  color: #fff;
}

.done .check {
  background: var(--c-success);
  border-color: var(--c-success);
}

.step-text {
  flex: 1;
  min-width: 0;
}

.step-title {
  font-weight: 600;
  text-decoration: none;
  display: inline-block;
  min-height: 28px;
}

.step-title:hover {
  text-decoration: underline;
}

.step-text p {
  margin: 0.15rem 0 0;
  color: var(--c-text-muted);
  font-size: 0.92rem;
}

.count {
  font-size: 1.4rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--c-text-muted);
}

.next p {
  margin: 0.75rem 0 0;
  color: var(--c-text-muted);
  max-width: 75ch;
}
</style>
