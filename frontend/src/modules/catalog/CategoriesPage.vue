<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { catalogApi, categoryKindLabels, type Category, type CategoryKind } from './api'
import { useAsync } from '@/shared/ui/useAsync'
import { useForm } from '@/shared/ui/useForm'
import { notify } from '@/shared/ui/toast'
import PageHeader from '@/shared/ui/PageHeader.vue'
import FButton from '@/shared/ui/FButton.vue'
import FPanel from '@/shared/ui/FPanel.vue'
import FState from '@/shared/ui/FState.vue'
import FTextField from '@/shared/ui/FTextField.vue'
import FSelectField from '@/shared/ui/FSelectField.vue'
import FSwitch from '@/shared/ui/FSwitch.vue'

const session = useSessionStore()
const canWrite = computed(() => session.can('catalog.write'))
const list = useAsync(() => catalogApi.categories.list())
const form = useForm()

const kindOptions = Object.entries(categoryKindLabels).map(([value, label]) => ({ value, label }))
const panelOpen = ref(false)
const editing = ref<Category | null>(null)
const input = reactive({ name: '', kind: 'SELLABLE' as CategoryKind, sortOrder: '0', active: true })

function open(category: Category | null): void {
  if (!canWrite.value) return
  editing.value = category
  Object.assign(input, category
    ? { name: category.name, kind: category.kind, sortOrder: String(category.sortOrder), active: category.active }
    : { name: '', kind: 'SELLABLE', sortOrder: '0', active: true })
  form.reset()
  panelOpen.value = true
}

async function save(): Promise<void> {
  const sortOrder = Number.parseInt(input.sortOrder || '0', 10)
  const payload = { name: input.name, kind: input.kind, sortOrder: Number.isNaN(sortOrder) ? 0 : sortOrder }
  const saved = await form.submit(() =>
    editing.value ? catalogApi.categories.update(editing.value.id, { ...payload, active: input.active }) : catalogApi.categories.create(payload),
  )
  if (saved) {
    panelOpen.value = false
    notify(editing.value ? 'Categoria atualizada.' : 'Categoria criada.')
    await list.run()
  }
}

onMounted(list.run)
</script>

<template>
  <PageHeader title="Categorias" subtitle="Organize produtos de venda e ingredientes. Categorias inativas somem das listas de seleção.">
    <template #actions>
      <FButton v-if="canWrite" @click="open(null)">+ Nova categoria</FButton>
    </template>
  </PageHeader>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />
  <FState v-else-if="list.data.value?.length === 0" kind="empty" title="Nenhuma categoria cadastrada" message="Comece com algo como “Pães”, “Doces” ou “Farinhas”.">
    <FButton v-if="canWrite" @click="open(null)">Criar categoria</FButton>
  </FState>

  <table v-else class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
    <thead>
      <tr><th>Nome</th><th>Tipo</th><th>Ordem</th><th>Situação</th></tr>
    </thead>
    <tbody>
      <tr v-for="c in list.data.value" :key="c.id" :class="{ 'is-clickable': canWrite }" :tabindex="canWrite ? 0 : undefined" @click="open(c)" @keydown.enter="open(c)">
        <td>{{ c.name }}</td>
        <td data-label="Tipo">{{ categoryKindLabels[c.kind] }}</td>
        <td data-label="Ordem" class="num">{{ c.sortOrder }}</td>
        <td><span class="badge" :class="c.active ? 'is-success' : ''">{{ c.active ? 'Ativa' : 'Inativa' }}</span></td>
      </tr>
    </tbody>
  </table>

  <FPanel :open="panelOpen" :title="editing ? 'Editar categoria' : 'Nova categoria'" @close="panelOpen = false">
    <form id="category-form" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="form-grid">
        <FTextField v-model="input.name" class="full" label="Nome" required :error="form.fieldError('name')" :maxlength="100" />
        <FSelectField v-model="input.kind" label="Usada em" :options="kindOptions" :error="form.fieldError('kind')" />
        <FTextField v-model="input.sortOrder" label="Ordem de exibição" inputmode="numeric" :error="form.fieldError('sortOrder')" />
        <FSwitch v-if="editing" v-model="input.active" class="full" label="Categoria ativa" />
      </div>
    </form>
    <template #footer>
      <FButton variant="secondary" @click="panelOpen = false">Cancelar</FButton>
      <FButton type="submit" form="category-form" :loading="form.submitting.value">Salvar</FButton>
    </template>
  </FPanel>
</template>
