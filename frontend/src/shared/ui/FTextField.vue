<script setup lang="ts">
import FField from './FField.vue'

withDefaults(
  defineProps<{
    label: string
    error?: string
    hint?: string
    required?: boolean
    type?: string
    inputmode?: 'text' | 'decimal' | 'numeric' | 'email' | 'search' | 'tel'
    autocomplete?: string
    placeholder?: string
    disabled?: boolean
    multiline?: boolean
    maxlength?: number
    autofocus?: boolean
  }>(),
  { type: 'text', error: undefined, hint: undefined, inputmode: undefined, autocomplete: undefined, placeholder: undefined, maxlength: undefined },
)

const model = defineModel<string>({ default: '' })
</script>

<template>
  <FField :label="label" :error="error" :hint="hint" :required="required">
    <template #default="{ id, describedBy, invalid }">
      <textarea
        v-if="multiline"
        :id="id"
        v-model="model"
        class="f-input"
        :aria-describedby="describedBy"
        :aria-invalid="invalid"
        :placeholder="placeholder"
        :disabled="disabled"
        :maxlength="maxlength"
      ></textarea>
      <input
        v-else
        :id="id"
        v-model="model"
        class="f-input"
        :type="type"
        :inputmode="inputmode"
        :autocomplete="autocomplete"
        :aria-describedby="describedBy"
        :aria-invalid="invalid"
        :required="required"
        :placeholder="placeholder"
        :disabled="disabled"
        :maxlength="maxlength"
        :autofocus="autofocus"
      />
    </template>
  </FField>
</template>
