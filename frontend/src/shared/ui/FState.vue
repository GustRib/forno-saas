<script setup lang="ts">
/** Estados explícitos de carregamento, vazio e erro (ARCHITECTURE §11). */
defineProps<{
  kind: 'loading' | 'empty' | 'error'
  title?: string
  message?: string
}>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="f-state" :class="`is-${kind}`" :role="kind === 'error' ? 'alert' : 'status'">
    <span v-if="kind === 'loading'" class="spinner" aria-hidden="true"></span>
    <span v-else class="icon" aria-hidden="true">{{ kind === 'empty' ? '🥖' : '⚠️' }}</span>
    <p class="title">{{ title ?? (kind === 'loading' ? 'Carregando…' : kind === 'empty' ? 'Nada por aqui ainda' : 'Não foi possível carregar') }}</p>
    <p v-if="message" class="message">{{ message }}</p>
    <div class="actions">
      <button v-if="kind === 'error'" type="button" class="retry" @click="$emit('retry')">Tentar novamente</button>
      <slot />
    </div>
  </div>
</template>

<style scoped>
.f-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.5rem;
  padding: 3rem 1.5rem;
  background: var(--c-surface);
  border: 1px dashed var(--c-border-strong);
  border-radius: var(--radius);
}

.is-error {
  border-color: var(--c-danger);
  background: var(--c-danger-soft);
}

.icon {
  font-size: 2rem;
}

.title {
  margin: 0;
  font-weight: 600;
}

.message {
  margin: 0;
  color: var(--c-text-muted);
  max-width: 46ch;
}

.actions {
  margin-top: 0.5rem;
  display: flex;
  gap: 0.75rem;
}

.retry {
  min-height: var(--touch);
  padding: 0 1rem;
  border: 1px solid var(--c-danger);
  border-radius: var(--radius-sm);
  background: var(--c-surface);
  color: var(--c-danger);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--c-border-strong);
  border-top-color: var(--c-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
