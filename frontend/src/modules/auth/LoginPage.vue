<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/app/stores/session'
import { useForm } from '@/shared/ui/useForm'
import AuthShell from './AuthShell.vue'
import FButton from '@/shared/ui/FButton.vue'
import FTextField from '@/shared/ui/FTextField.vue'

const session = useSessionStore()
const router = useRouter()
const route = useRoute()
const form = useForm()

const email = ref('')
const password = ref('')

async function submit(): Promise<void> {
  const ok = await form.submit(async () => {
    await session.login(email.value, password.value)
    return true
  })
  if (ok) {
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    await router.replace(redirect)
  }
}
</script>

<template>
  <AuthShell title="Entrar" subtitle="Acesse a gestão da sua padaria.">
    <form novalidate @submit.prevent="submit">
      <p v-if="form.formError.value" class="form-error" role="alert">{{ form.formError.value }}</p>
      <div class="fields">
        <FTextField v-model="email" label="E-mail" type="email" inputmode="email" autocomplete="username" required autofocus />
        <FTextField v-model="password" label="Senha" type="password" autocomplete="current-password" required />
        <FButton type="submit" block :loading="form.submitting.value">Entrar</FButton>
      </div>
    </form>
    <p class="alt">Ainda não usa o Forno? <RouterLink to="/cadastro">Cadastre sua padaria</RouterLink></p>
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
