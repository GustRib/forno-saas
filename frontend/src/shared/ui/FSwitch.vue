<script setup lang="ts">
defineProps<{ label: string; hint?: string; disabled?: boolean }>()
const model = defineModel<boolean>({ default: false })
</script>

<template>
  <label class="f-switch" :class="{ 'is-disabled': disabled }">
    <input v-model="model" type="checkbox" role="switch" :disabled="disabled" />
    <span class="track" aria-hidden="true"><span class="thumb"></span></span>
    <span class="text">
      <span class="label">{{ label }}</span>
      <span v-if="hint" class="hint">{{ hint }}</span>
    </span>
  </label>
</template>

<style scoped>
.f-switch {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: var(--touch);
  cursor: pointer;
}

.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

input {
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;
}

.track {
  flex: none;
  width: 46px;
  height: 26px;
  border-radius: 999px;
  background: var(--c-border-strong);
  position: relative;
  transition: background-color 0.15s;
}

.thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 2px rgb(0 0 0 / 25%);
  transition: transform 0.15s;
}

input:checked + .track {
  background: var(--c-primary);
}

input:checked + .track .thumb {
  transform: translateX(20px);
}

input:focus-visible + .track {
  outline: 3px solid var(--c-focus);
  outline-offset: 2px;
}

.text {
  display: flex;
  flex-direction: column;
}

.label {
  font-weight: 600;
  font-size: 0.92rem;
}

.hint {
  font-size: 0.85rem;
  color: var(--c-text-muted);
}
</style>
