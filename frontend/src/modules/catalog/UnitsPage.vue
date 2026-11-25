<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi, dimensionLabels, type Dimension, type Unit } from './api'
import { useAsync } from '@/shared/ui/useAsync'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import { formatDecimal, parseDecimalInput, toInputDecimal } from '@/shared/format/decimal'
import { ApiError } from '@/shared/api/http'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FButton from '@/shared/ui/FButton.vue'
import FPanel from '@/shared/ui/FPanel.vue'
import FState from '@/shared/ui/FState.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import FSelectField from '@/shared/ui/FSelectField.vue'

const session = useSessionStore()
const canWrite = computed(() => session.can('catalog.write'))
const list = useAsync(() => catalogApi.units.list())
const form = useForm()

const baseUnits: Record<Dimension, string> = { MASS: 'g', VOLUME: 'ml', COUNT: 'un', LENGTH: 'unidade-base' }
const dimensionOptions = Object.entries(dimensionLabels).map(([value, label]) => ({ value, label }))

const panelOpen = ref(false)
const editing = ref<Unit | null>(null)
const input = reactive({ code: '', name: '', dimension: 'MASS' as Dimension, baseFactor: '' })

// Conversor rápido (FNO-024)
const conv = reactive({ quantity: '1', from: '', to: '', result: '', error: '' })

function open(unit: Unit | null): void {
  if (!canWrite.value) return
  editing.value = unit
  Object.assign(input, unit
    ? { code: unit.code, name: unit.name, dimension: unit.dimension, baseFactor: toInputDecimal(unit.baseFactor) }
    : { code: '', name: '', dimension: 'MASS', baseFactor: '' })
  form.reset()
  panelOpen.value = true
}

async function save(): Promise<void> {
  const baseFactor = parseDecimalInput(input.baseFactor, 9)
  if (baseFactor === null) {
    form.fieldErrors.value = { baseFactor: ['Informe um número (ex.: 1000 ou 0,5).'] }
    return
  }
  const payload = { code: input.code, name: input.name, dimension: input.dimension, baseFactor }
  const saved = await form.submit(() => (editing.value ? catalogApi.units.update(editing.value.id, payload) : catalogApi.units.create(payload)))
  if (saved) {
    panelOpen.value = false
    notify(editing.value ? 'Unidade atualizada.' : 'Unidade criada.')
    await list.run()
  }
}

async function remove(): Promise<void> {
  if (!editing.value || !window.confirm(`Excluir a unidade ${editing.value.code}? Esta ação não pode ser desfeita.`)) return
  const ok = await form.submit(async () => {
    await catalogApi.units.remove(editing.value!.id)
    return true
  })
  if (ok) {
    panelOpen.value = false
    notify('Unidade excluída.')
    await list.run()
  }
}

async function convert(): Promise<void> {
  conv.result = ''
  conv.error = ''
  const quantity = parseDecimalInput(conv.quantity)
  if (quantity === null || !conv.from || !conv.to) {
    conv.error = 'Informe a quantidade e as duas unidades.'
    return
  }
  try {
    const r = await catalogApi.units.convert(quantity, conv.from, conv.to)
    conv.result = `${formatDecimal(quantity)} ${r.from} = ${formatDecimal(r.result.replace(/\.?0+$/, ''))} ${r.to}`
  } catch (e) {
    conv.error = e instanceof ApiError ? e.message : 'Não foi possível converter.'
  }
}

const unitOptions = computed(() => (list.data.value ?? []).map((u) => ({ value: u.id, label: `${u.name} (${u.code})` })))

onMounted(list.run)
</script>

<template>
  <PageHeader title="Unidades de medida" subtitle="Cada unidade converte para a unidade-base da sua dimensão. Massa e volume nunca são convertidos entre si.">
    <template #actions>
      <FButton v-if="canWrite" @click="open(null)">+ Nova unidade</FButton>
    </template>
  </PageHeader>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />

  <template v-else>
    <table class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
      <thead>
        <tr><th>Código</th><th>Nome</th><th>Dimensão</th><th class="num">Equivale a</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="u in list.data.value" :key="u.id" :class="{ 'is-clickable': canWrite }" :tabindex="canWrite ? 0 : undefined" @click="open(u)" @keydown.enter="open(u)">
          <td><strong>{{ u.code }}</strong></td>
          <td>{{ u.name }}</td>
          <td data-label="Dimensão">{{ u.dimensionLabel }}</td>
          <td data-label="Equivale a" class="num">{{ formatDecimal(u.baseFactor) }} {{ baseUnits[u.dimension] }}</td>
          <td><span v-if="u.isBase" class="badge is-primary">Unidade-base</span></td>
        </tr>
      </tbody>
    </table>

    <section class="converter" aria-labelledby="conv-title">
      <h2 id="conv-title">Conversor</h2>
      <form class="conv-row" novalidate @submit.prevent="convert">
        <FTextField v-model="conv.quantity" label="Quantidade" inputmode="decimal" />
        <FSelectField v-model="conv.from" label="De" :options="unitOptions" placeholder="Selecione" />
        <FSelectField v-model="conv.to" label="Para" :options="unitOptions" placeholder="Selecione" />
        <FButton type="submit" variant="secondary">Converter</FButton>
      </form>
      <p v-if="conv.result" class="conv-result" role="status">{{ conv.result }}</p>
      <p v-if="conv.error" class="form-error" role="alert">{{ conv.error }}</p>
    </section>
  </template>

  <FPanel :open="panelOpen" :title="editing ? `Editar ${editing.code}` : 'Nova unidade'" @close="panelOpen = false">
    <form id="unit-form" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <p v-if="editing?.isBase" class="note">Unidade-base: só o nome pode ser alterado.</p>
      <div class="form-grid">
        <FTextField v-model="input.code" label="Código" required hint="Ex.: SC25, CX, FATIA" :disabled="editing?.isBase" :error="form.fieldError('code')" :maxlength="12" />
        <FTextField v-model="input.name" label="Nome" required :error="form.fieldError('name')" :maxlength="40" />
        <FSelectField v-model="input.dimension" label="Dimensão" :options="dimensionOptions" :disabled="editing?.isBase" :error="form.fieldError('dimension')" />
        <FTextField
          v-model="input.baseFactor"
          label="Quantas unidades-base"
          inputmode="decimal"
          required
          :disabled="editing?.isBase"
          :hint="`1 ${input.code || 'unidade'} = ? ${baseUnits[input.dimension]}`"
          :error="form.fieldError('baseFactor')"
        />
      </div>
    </form>
    <template #footer>
      <FButton v-if="editing && !editing.isBase" variant="ghost" class="danger-link" @click="remove">Excluir</FButton>
      <FButton variant="secondary" @click="panelOpen = false">Cancelar</FButton>
      <FButton type="submit" form="unit-form" :loading="form.submitting.value">Salvar</FButton>
    </template>
  </FPanel>
</template>

<style scoped>
.converter {
  margin-top: 2rem;
  padding: 1.25rem;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
}

.converter h2 {
  font-size: 1.1rem;
  margin-bottom: 1rem;
}

.conv-row {
  display: grid;
  grid-template-columns: 1fr 1.5fr 1.5fr auto;
  gap: 1rem;
  align-items: end;
}

@media (max-width: 760px) {
  .conv-row {
    grid-template-columns: 1fr;
  }
}

.conv-result {
  margin: 1rem 0 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.note {
  margin: 0 0 1rem;
  color: var(--c-text-muted);
}

.danger-link {
  color: var(--c-danger);
  margin-right: auto;
}
</style>
