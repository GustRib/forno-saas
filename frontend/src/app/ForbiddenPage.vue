<script setup lang="ts">
import FState from '@/shared/ui/FState.vue'
import { useSessionStore } from './stores/session'

const session = useSessionStore()
</script>

<template>
  <FState
    v-if="session.tenant"
    kind="empty"
    title="Você não tem acesso a esta área"
    message="Peça ao proprietário do estabelecimento para ajustar seu papel na equipe."
  >
    <RouterLink to="/">Voltar ao início</RouterLink>
  </FState>
  <FState
    v-else
    kind="empty"
    title="Nenhum estabelecimento ativo"
    message="Sua conta não está vinculada a nenhuma padaria ativa. Peça ao proprietário para incluir você na equipe."
  />
</template>
