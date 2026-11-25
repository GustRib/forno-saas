<script setup lang="ts">
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/app/stores/session'
import { useForm } from '@/shared/ui/useForm'
import AuthShell from './AuthShell.vue'
import FButton from '@/shared/ui/FButton.vue'
import FTextField from '@/shared/ui/FTextField.vue'

const session = useSessionStore()
const router = useRouter()
const form = useForm()

const input = reactive({ bakeryName: '', name: '', email: '', password: '' })

async function submit(): Promise<void> {
  const ok = await form.submit(async () => {
    await session.register({ ...input })
    return true
  })
  if (ok) await router.replace('/')
}
</script>

<template>
  <AuthShell title="Cadastre sua padaria" subtitle="Você será o proprietário e poderá convidar sua equipe depois.">
    <form novalidate @submit.prevent="submit">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="fields">
        <FTextField v-model="input.bakeryName" label="Nome da padaria" required :error="form.fieldError('bakeryName')" autofocus />
        <FTextField v-model="input.name" label="Seu nome" autocomplete="name" required :error="form.fieldError('name')" />
        <FTextField v-model="input.email" label="E-mail" type="email" inputmode="email" autocomplete="email" required :error="form.fieldError('email')" />
        <FTextField
          v-model="input.password"
          label="Senha"
          type="password"
          autocomplete="new-password"
          hint="Use ao menos 8 caracteres."
          required
          :error="form.fieldError('password')"
        />
        <FButton type="submit" block :loading="form.submitting.value">Criar conta</FButton>
      </div>
    </form>
    <p class="alt">Já tem conta? <RouterLink to="/login">Entrar</RouterLink></p>
  </AuthShell>
</template>

<style scoped>
.fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.alt {
  margin: 1.25rem 0 0;
  text-align: center;
  color: var(--c-text-muted);
}
</style>
