<script setup lang="ts">
import { dismiss, toasts } from './toast'
</script>

<template>
  <div class="f-toasts" aria-live="polite">
    <div v-for="toast in toasts" :key="toast.id" class="toast" :class="`is-${toast.kind}`" :role="toast.kind === 'error' ? 'alert' : 'status'">
      <span>{{ toast.message }}</span>
      <button type="button" aria-label="Fechar aviso" @click="dismiss(toast.id)">✕</button>
    </div>
  </div>
</template>

<style scoped>
.f-toasts {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  z-index: 100;
  max-width: min(420px, calc(100vw - 2rem));
}

.toast {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0.5rem 0.75rem 1rem;
  border-radius: var(--radius-sm);
  background: var(--c-text);
  color: #fff;
  box-shadow: var(--shadow);
}

.is-success {
  background: var(--c-success);
}

.is-error {
  background: var(--c-danger);
}

button {
  min-width: 36px;
  min-height: 36px;
  border: none;
  background: transparent;
  color: inherit;
  cursor: pointer;
}
</style>
