import { computed, ref } from 'vue'
import { catalogApi, type Category, type CategoryKind, type Unit } from './api'

/**
 * Unidades e categorias usadas nos selects de produtos/ingredientes.
 */
export function useCatalogRefs(kind: Exclude<CategoryKind, 'BOTH'>) {
  const units = ref<Unit[]>([])
  const categories = ref<Category[]>([])

  async function load(): Promise<void> {
    const [u, c] = await Promise.all([catalogApi.units.list(), catalogApi.categories.list()])
    units.value = u
    categories.value = c.filter((cat) => cat.kind === kind || cat.kind === 'BOTH')
  }

  const unitOptions = computed(() => units.value.map((u) => ({ value: u.id, label: `${u.name} (${u.code})` })))
  const categoryOptions = computed(() =>
    categories.value.filter((c) => c.active).map((c) => ({ value: c.id, label: c.name })),
  )

  const unitById = computed(() => new Map(units.value.map((u) => [u.id, u])))
  const categoryById = computed(() => new Map(categories.value.map((c) => [c.id, c])))

  return { units, categories, unitOptions, categoryOptions, unitById, categoryById, load }
}
