<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useSessionStore } from '@/app/stores/session'
import { membersApi, roleLabels, type Member, type Role } from './api'
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
const canManage = computed(() => session.can('members.manage'))
const list = useAsync(() => membersApi.list())
const form = useForm()

const roleOptions = computed(() =>
  Object.entries(roleLabels)
    .filter(([value]) => value !== 'OWNER' || session.tenant?.role === 'OWNER')
    .map(([value, label]) => ({ value, label })),
)

const panelOpen = ref(false)
const editing = ref<Member | null>(null)
const input = reactive({ name: '', email: '', role: 'CASHIER' as Role, password: '', active: true })

function isSelf(member: Member): boolean {
  return member.userId === session.user?.id
}

function open(member: Member | null): void {
  if (!canManage.value || (member && isSelf(member))) return
  editing.value = member
  Object.assign(input, member
    ? { name: member.name ?? '', email: member.email ?? '', role: member.role, password: '', active: member.status === 'ACTIVE' }
    : { name: '', email: '', role: 'CASHIER', password: '', active: true })
  form.reset()
  panelOpen.value = true
}

async function save(): Promise<void> {
  const saved = await form.submit(() =>
    editing.value
      ? membersApi.update(editing.value.id, { role: input.role, status: input.active ? 'ACTIVE' : 'DISABLED' })
      : membersApi.create({ name: input.name, email: input.email, role: input.role, password: input.password }),
  )
  if (saved) {
    panelOpen.value = false
    notify(editing.value ? 'Acesso atualizado.' : 'Pessoa adicionada à equipe.')
    await list.run()
  }
}

onMounted(list.run)
</script>

<template>
  <PageHeader title="Equipe" subtitle="Quem acessa este estabelecimento e o que cada pessoa pode fazer.">
    <template #actions>
      <FButton v-if="canManage" @click="open(null)">+ Adicionar pessoa</FButton>
    </template>
  </PageHeader>

  <FState v-if="list.loading.value && !list.data.value" kind="loading" />
  <FState v-else-if="list.error.value" kind="error" :message="list.error.value" @retry="list.run" />

  <table v-else-if="list.data.value" class="f-table" :class="{ 'is-refreshing': list.loading.value }" :aria-busy="list.loading.value">
    <thead>
      <tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Situação</th></tr>
    </thead>
    <tbody>
      <tr
        v-for="m in list.data.value"
        :key="m.id"
        :class="{ 'is-clickable': canManage && !isSelf(m) }"
        :tabindex="canManage && !isSelf(m) ? 0 : undefined"
        @click="open(m)"
        @keydown.enter="open(m)"
      >
        <td><strong>{{ m.name }}</strong> <span v-if="isSelf(m)" class="muted">(você)</span></td>
        <td data-label="E-mail">{{ m.email }}</td>
        <td data-label="Papel">{{ m.roleLabel }}</td>
        <td><span class="badge" :class="m.status === 'ACTIVE' ? 'is-success' : ''">{{ m.status === 'ACTIVE' ? 'Ativo' : 'Desativado' }}</span></td>
      </tr>
    </tbody>
  </table>

  <FPanel :open="panelOpen" :title="editing ? `Acesso de ${editing.name}` : 'Adicionar pessoa'" @close="panelOpen = false">
    <form id="member-form" novalidate @submit.prevent="save">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="form-grid">
        <template v-if="!editing">
          <FTextField v-model="input.name" class="full" label="Nome" required :error="form.fieldError('name')" />
          <FTextField v-model="input.email" class="full" label="E-mail" type="email" inputmode="email" required :error="form.fieldError('email')" />
          <FTextField
            v-model="input.password"
            class="full"
            label="Senha inicial"
            type="password"
            autocomplete="new-password"
            hint="Combine a senha com a pessoa. Se ela já tiver conta no Forno, a senha atual dela é mantida."
            :error="form.fieldError('password')"
          />
        </template>
        <FSelectField v-model="input.role" class="full" label="Papel" :options="roleOptions" :error="form.fieldError('role')" />
        <FSwitch v-if="editing" v-model="input.active" class="full" label="Acesso ativo" hint="Desativar impede o acesso a este estabelecimento." />
      </div>
    </form>
    <template #footer>
      <FButton variant="secondary" @click="panelOpen = false">Cancelar</FButton>
      <FButton type="submit" form="member-form" :loading="form.submitting.value">Salvar</FButton>
    </template>
  </FPanel>
</template>
