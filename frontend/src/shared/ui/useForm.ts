import { ref } from 'vue'
import { ApiError, type FieldErrors } from '@/shared/api/http'

/**
 * Estado de envio de formulário: carregando, erros por campo e erro geral.
 * Os erros vêm da validação do backend (fonte da verdade).
 */
export function useForm() {
  const submitting = ref(false)
  const fieldErrors = ref<FieldErrors>({})
  const formError = ref('')

  function fieldError(name: string): string | undefined {
    return fieldErrors.value[name]?.[0]
  }

  function reset(): void {
    fieldErrors.value = {}
    formError.value = ''
  }

  async function submit<T>(action: () => Promise<T>): Promise<T | undefined> {
    submitting.value = true
    reset()
    try {
      return await action()
    } catch (e) {
      if (e instanceof ApiError) {
        fieldErrors.value = e.fields
        formError.value = Object.keys(e.fields).length > 0 && e.code === 'VALIDATION_FAILED' ? 'Revise os campos destacados.' : e.message
      } else {
        formError.value = 'Ocorreu um erro inesperado. Tente novamente.'
        console.error(e)
      }
      return undefined
    } finally {
      submitting.value = false
    }
  }

  return { submitting, fieldErrors, formError, fieldError, reset, submit }
}
