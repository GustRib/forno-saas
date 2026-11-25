<script setup lang="ts">
import FField from './FField.vue'

defineProps<{
  label: string
  options: { value: string; label: string }[]
  error?: string
  hint?: string
  required?: boolean
  placeholder?: string
  disabled?: boolean
}>()

const model = defineModel<string>({ default: '' })
</script>

<template>
  <FField :label="label" :error="error" :hint="hint" :required="required">
    <template #default="{ id, describedBy, invalid }">
      <select :id="id" v-model="model" class="f-input" :aria-describedby="describedBy" :aria-invalid="invalid" :disabled="disabled">
        <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
    </template>
  </FField>
</template>
