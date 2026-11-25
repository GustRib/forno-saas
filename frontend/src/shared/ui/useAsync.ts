import { ref, shallowRef } from 'vue'
import { ApiError } from '@/shared/api/http'

/**
 * Carregamento com estados explícitos: loading / error / data.
 */
export function useAsync<T>(loader: () => Promise<T>) {
  const data = shallowRef<T | null>(null)
  const loading = ref(false)
  const error = ref('')

  async function run(): Promise<void> {
    loading.value = true
    error.value = ''
    try {
      data.value = await loader()
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Ocorreu um erro inesperado.'
      if (!(e instanceof ApiError)) console.error(e)
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, run }
}
