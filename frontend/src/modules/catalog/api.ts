import { http, type Page } from '@/shared/api/http'

export type CategoryKind = 'SELLABLE' | 'INGREDIENT' | 'BOTH'
export type Dimension = 'MASS' | 'VOLUME' | 'COUNT' | 'LENGTH'
export type ProductionType = 'MANUFACTURED' | 'RESALE' | 'SERVICE'

export interface Category {
  id: string
  name: string
  kind: CategoryKind
  sortOrder: number
  active: boolean
}

export interface Unit {
  id: string
  code: string
  name: string
  dimension: Dimension
  dimensionLabel: string
  baseFactor: string
  isBase: boolean
}

export interface Product {
  id: string
  name: string
  categoryId: string | null
  sku: string | null
  barcode: string | null
  description: string | null
  saleUnitId: string
  stockTracked: boolean
  productionType: ProductionType
  salePrice: string
  active: boolean
}

export interface Ingredient {
  id: string
  name: string
  categoryId: string | null
  sku: string | null
  stockUnitId: string
  purchaseUnitId: string | null
  purchaseToStockFactor: string | null
  averageUnitCost: string
  minimumStock: string
  active: boolean
}

export interface IngredientCostChange {
  id: string
  previousCost: string | null
  newCost: string
  source: 'INITIAL' | 'MANUAL' | 'STOCK_MOVEMENT'
  sourceLabel: string
  stockMovementId: string | null
  costPolicy: string | null
  changedAt: string
  changedBy: { id: string; name: string | null } | null
}

export type CategoryInput = Omit<Category, 'id'>
export type UnitInput = Pick<Unit, 'code' | 'name' | 'dimension' | 'baseFactor'>
export type ProductInput = Omit<Product, 'id'>
export type IngredientInput = Omit<Ingredient, 'id'>

export interface ListQuery {
  q?: string
  active?: boolean
  categoryId?: string
  page?: number
  limit?: number
  [key: string]: string | number | boolean | undefined
}

export const categoryKindLabels: Record<CategoryKind, string> = {
  SELLABLE: 'Produtos de venda',
  INGREDIENT: 'Ingredientes',
  BOTH: 'Ambos',
}

export const dimensionLabels: Record<Dimension, string> = {
  MASS: 'Massa',
  VOLUME: 'Volume',
  COUNT: 'Contagem',
  LENGTH: 'Comprimento',
}

export const productionTypeLabels: Record<ProductionType, string> = {
  MANUFACTURED: 'Fabricado aqui',
  RESALE: 'Revenda',
  SERVICE: 'Serviço',
}

export const catalogApi = {
  categories: {
    list: (query: { kind?: CategoryKind; active?: boolean } = {}) => http.get<{ data: Category[] }>('/categories', query).then((r) => r.data),
    create: (input: Omit<CategoryInput, 'active'>) => http.post<{ data: Category }>('/categories', input).then((r) => r.data),
    update: (id: string, input: CategoryInput) => http.put<{ data: Category }>(`/categories/${id}`, input).then((r) => r.data),
  },
  units: {
    list: () => http.get<{ data: Unit[] }>('/units').then((r) => r.data),
    create: (input: UnitInput) => http.post<{ data: Unit }>('/units', input).then((r) => r.data),
    update: (id: string, input: UnitInput) => http.put<{ data: Unit }>(`/units/${id}`, input).then((r) => r.data),
    remove: (id: string) => http.delete(`/units/${id}`),
    convert: (quantity: string, from: string, to: string) =>
      http.get<{ data: { result: string; from: string; to: string } }>('/units/convert', { quantity, from, to }).then((r) => r.data),
  },
  products: {
    list: (query: ListQuery) => http.get<Page<Product>>('/products', query),
    get: (id: string) => http.get<{ data: Product }>(`/products/${id}`).then((r) => r.data),
    create: (input: ProductInput) => http.post<{ data: Product }>('/products', input).then((r) => r.data),
    update: (id: string, input: ProductInput) => http.put<{ data: Product }>(`/products/${id}`, input).then((r) => r.data),
  },
  ingredients: {
    list: (query: ListQuery) => http.get<Page<Ingredient>>('/ingredients', query),
    costHistory: (id: string, page = 1, limit = 20) => http.get<Page<IngredientCostChange>>(`/ingredients/${id}/cost-history`, { page, limit }),
    create: (input: IngredientInput) => http.post<{ data: Ingredient }>('/ingredients', input).then((r) => r.data),
    update: (id: string, input: IngredientInput) => http.put<{ data: Ingredient }>(`/ingredients/${id}`, input).then((r) => r.data),
  },
}
