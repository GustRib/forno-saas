<script setup lang="ts">
/**
 * Painel lateral para formulários. Em telas estreitas ocupa a tela toda.
 * Fecha com Esc ou no botão; o foco vai para o painel ao abrir.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps<{ open: boolean; title: string }>()
const emit = defineEmits<{ close: [] }>()
const panel = ref<HTMLElement | null>(null)

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      document.addEventListener('keydown', onKey)
      await nextTick()
      const first = panel.value?.querySelector<HTMLElement>('input, select, textarea, button:not(.close)')
      ;(first ?? panel.value)?.focus()
    } else {
      document.removeEventListener('keydown', onKey)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <Transition name="panel">
      <div v-if="open" class="backdrop" @click.self="emit('close')">
        <section ref="panel" class="panel" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
          <header class="head">
            <h2>{{ title }}</h2>
            <button type="button" class="close" aria-label="Fechar" @click="emit('close')">✕</button>
          </header>
          <div class="body"><slot /></div>
          <footer v-if="$slots.footer" class="foot"><slot name="footer" /></footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  background: rgb(43 33 24 / 35%);
  display: flex;
  justify-content: flex-end;
  z-index: 50;
}

.panel {
  width: min(560px, 100%);
  height: 100%;
  background: var(--c-surface);
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow);
  outline: none;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--c-border);
}

.head h2 {
  font-size: 1.2rem;
}

.close {
  width: var(--touch);
  height: var(--touch);
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  font-size: 1.1rem;
  cursor: pointer;
}

.close:hover {
  background: var(--c-surface-2);
}

.body {
  flex: 1;
  overflow-y: auto;
  padding: 1.25rem;
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-top: 1px solid var(--c-border);
  background: var(--c-surface-2);
}

.panel-enter-active,
.panel-leave-active {
  transition: opacity 0.2s;
}

.panel-enter-active .panel,
.panel-leave-active .panel {
  transition: transform 0.2s;
}

.panel-enter-from,
.panel-leave-to {
  opacity: 0;
}

.panel-enter-from .panel,
.panel-leave-to .panel {
  transform: translateX(40px);
}
</style>
