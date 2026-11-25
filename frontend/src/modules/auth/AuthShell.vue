<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="auth">
    <section class="card">
      <div class="brand">
        <img src="/favicon.svg" alt="" width="40" height="40" />
        <span>Forno</span>
      </div>
      <h1>{{ title }}</h1>
      <p v-if="subtitle" class="subtitle">{{ subtitle }}</p>
      <slot />
    </section>
    <p class="foot">Gestão simples para padarias: da receita à venda, do estoque ao caixa.</p>
  </div>
</template>

<style scoped>
.auth {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem 1rem;
  background: radial-gradient(circle at top, #fdeee4 0%, var(--c-bg) 60%);
}

.card {
  width: min(440px, 100%);
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 14px;
  box-shadow: var(--shadow);
  padding: 2rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--c-primary);
  margin-bottom: 1.25rem;
}

h1 {
  font-size: 1.45rem;
}

.subtitle {
  color: var(--c-text-muted);
  margin: 0.4rem 0 1.25rem;
}

.foot {
  margin-top: 1.25rem;
  color: var(--c-text-muted);
  font-size: 0.9rem;
  text-align: center;
}
</style>
