<script setup lang="ts">
/**
 * Rótulo + campo + dica/erro. O slot recebe `id` e `describedBy` para
 * ligar o input ao rótulo e à mensagem (acessibilidade).
 */
import { computed, useId } from 'vue'

const props = defineProps<{
  label: string
  error?: string
  hint?: string
  required?: boolean
}>()

const id = useId()
const messageId = `${id}-msg`
const describedBy = computed(() => (props.error || props.hint ? messageId : undefined))
</script>

<template>
  <div class="f-field">
    <label :for="id" class="label">
      {{ label }}<span v-if="required" class="req" aria-hidden="true"> *</span>
    </label>
    <slot :id="id" :described-by="describedBy" :invalid="!!error" />
    <p v-if="error" :id="messageId" class="msg is-error" role="alert">{{ error }}</p>
    <p v-else-if="hint" :id="messageId" class="msg">{{ hint }}</p>
  </div>
</template>

<style scoped>
.f-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.label {
  font-weight: 600;
  font-size: 0.92rem;
}

.req {
  color: var(--c-danger);
}

.msg {
  margin: 0;
  font-size: 0.85rem;
  color: var(--c-text-muted);
}

.msg.is-error {
  color: var(--c-danger);
}
</style>
