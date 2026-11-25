import { http, type Page } from '@/shared/api/http'
import type { Dimension } from '@/modules/catalog/api'

export type RecipeStatus = 'DRAFT' | 'ACTIVE' | 'ARCHIVED'
export type CostSource = 'INITIAL' | 'MANUAL' | 'STOCK_MOVEMENT'

export interface RecipeUnit {
  id: string
  code: string
  name: string
  dimension: Dimension
}

export interface RecipeSummary {
  id: string
  productId: string
  productName: string | null
  version: number
  name: string
  status: RecipeStatus
  statusLabel: string
  expectedYieldQty: string
  yieldUnit: RecipeUnit | null
  effectiveFrom: string | null
  activatedAt: string | null
  itemsCount: number
}

export interface CostWarning {
  code: 'NO_ITEMS' | 'INGREDIENT_MISSING' | 'UNIT_INCOMPATIBLE' | 'ZERO_COST' | 'INACTIVE_INGREDIENT' | 'YIELD_UNIT_INCOMPATIBLE'
  message: string
  ingredientId?: string
}

export interface CostLine {
  ingredientId: string
  ingredientName: string | null
  quantity: string
  unitCode: string
  stockQuantity: string | null
  stockUnitCode: string | null
  unitCost: string | null
  lineCost: string | null
  costChangedAt: string | null
  costSource: CostSource | null
}

export interface RecipeCost {
  lines: CostLine[]
  ingredientsCost: string
  additionalCost: string
  totalCost: string
  costPerYieldUnit: string
  costPerSaleUnit: string | null
  complete: boolean
  warnings: CostWarning[]
}

export interface RecipeDetail extends RecipeSummary {
  product: { id: string; name: string; productionType: string; saleUnit: RecipeUnit } | null
  instructions: string | null
  items: { id: string; ingredientId: string; ingredientName: string | null; quantity: string; unit: RecipeUnit | null; notes: string | null }[]
  additionalCosts: { id: string; description: string; amount: string }[]
  cost: RecipeCost
  versions: { id: string; version: number; status: RecipeStatus; statusLabel: string; effectiveFrom: string | null }[]
}

export interface CostSnapshot {
  id: string
  reason: 'ACTIVATION' | 'MANUAL'
  costPolicy: string
  ingredientsCost: string
  additionalCost: string
  totalCost: string
  costPerYieldUnit: string
  costPerSaleUnit: string | null
  yieldQty: string
  createdAt: string
}

export interface RecipePayload {
  productId?: string
  name: string
  expectedYieldQty: string
  yieldUnitId: string
  instructions: string | null
  items: { ingredientId: string; quantity: string; unitId: string; notes?: string }[]
  additionalCosts: { description: string; amount: string }[]
}

export const costSourceLabels: Record<CostSource, string> = {
  INITIAL: 'Custo inicial do cadastro',
  MANUAL: 'Informado no cadastro',
  STOCK_MOVEMENT: 'Média ponderada do estoque',
}

export const recipesApi = {
  list: (query: { productId?: string; status?: RecipeStatus; page?: number; limit?: number } = {}) => http.get<Page<RecipeSummary>>('/recipes', query),
  get: (id: string) => http.get<{ data: RecipeDetail }>(`/recipes/${id}`).then((r) => r.data),
  create: (payload: RecipePayload) => http.post<{ data: RecipeDetail }>('/recipes', payload).then((r) => r.data),
  update: (id: string, payload: RecipePayload) => http.put<{ data: RecipeDetail }>(`/recipes/${id}`, payload).then((r) => r.data),
  remove: (id: string) => http.delete(`/recipes/${id}`),
  newVersion: (id: string) => http.post<{ data: RecipeDetail }>(`/recipes/${id}/new-version`).then((r) => r.data),
  activate: (id: string) => http.post<{ data: RecipeDetail }>(`/recipes/${id}/activate`).then((r) => r.data),
  snapshots: (id: string) => http.get<{ data: CostSnapshot[] }>(`/recipes/${id}/cost-snapshots`).then((r) => r.data),
  recordSnapshot: (id: string) => http.post<{ data: CostSnapshot }>(`/recipes/${id}/cost-snapshots`).then((r) => r.data),
}
