<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', type: 'button', loading: false, disabled: false, block: false },
)
</script>

<template>
  <button
    :type="type"
    class="f-btn"
    :class="[`is-${variant}`, { 'is-block': block, 'is-loading': loading }]"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
  >
    <span v-if="loading" class="spinner" aria-hidden="true"></span>
    <slot />
  </button>
</template>

<style scoped>
.f-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: var(--touch);
  min-width: var(--touch);
  padding: 0.5rem 1.1rem;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.15s, border-color 0.15s;
  white-space: nowrap;
}

.f-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.is-block {
  width: 100%;
}

.is-primary {
  background: var(--c-primary);
  color: #fff;
}

.is-primary:hover:not(:disabled) {
  background: var(--c-primary-hover);
}

.is-secondary {
  background: var(--c-surface);
  border-color: var(--c-border-strong);
  color: var(--c-text);
}

.is-secondary:hover:not(:disabled) {
  background: var(--c-surface-2);
}

.is-ghost {
  background: transparent;
  color: var(--c-text);
}

.is-ghost:hover:not(:disabled) {
  background: var(--c-surface-2);
}

.is-danger {
  background: var(--c-danger);
  color: #fff;
}

.spinner {
  width: 1em;
  height: 1em;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
