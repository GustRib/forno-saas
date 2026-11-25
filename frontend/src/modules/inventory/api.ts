import { http, type Page } from '@/shared/api/http'
import type { Dimension } from '@/modules/catalog/api'

export type StockItemType = 'INGREDIENT' | 'PRODUCT'
export type AdjustmentType = 'WASTE' | 'ADJUSTMENT_IN' | 'ADJUSTMENT_OUT' | 'RETURN_IN'
export type MovementType =
  | 'OPENING_BALANCE'
  | 'PURCHASE_RECEIPT'
  | 'PRODUCTION_CONSUMPTION'
  | 'PRODUCTION_OUTPUT'
  | 'SALE'
  | AdjustmentType
  | 'REVERSAL'

export interface StockLocation {
  id: string
  name: string
  isDefault: boolean
}

export interface StockUnit {
  id: string
  code: string
  name: string
  dimension: Dimension
}

export interface StockBalanceRow {
  itemType: StockItemType
  itemId: string
  name: string
  sku: string | null
  active: boolean
  unit: StockUnit
  /** Decimal em string, na unidade de estoque. */
  quantity: string
  location: StockLocation
}

export interface StockItemDetail {
  itemType: StockItemType
  itemId: string
  name: string
  sku: string | null
  active: boolean
  unit: StockUnit
  stockTracked: boolean
  balances: { location: StockLocation; quantity: string }[]
  totalQuantity: string
  hasMovements: boolean
}

export interface StockMovement {
  id: string
  itemType: StockItemType
  itemId: string
  locationId: string
  type: MovementType
  typeLabel: string
  /** Positivo entra, negativo sai; na unidade de estoque. */
  quantityDelta: string
  unitId: string
  unitCost: string | null
  reason: string | null
  reversalOfId: string | null
  reversed: boolean
  reversible: boolean
  occurredAt: string
  createdAt: string
  createdBy: { id: string; name: string | null }
}

export interface MovementPayload {
  itemType: StockItemType
  itemId: string
  quantity: string
  unitId?: string | null
  reason?: string | null
  unitCost?: string | null
  occurredAt?: string | null
}

export const adjustmentLabels: Record<AdjustmentType, { label: string; hint: string; inbound: boolean }> = {
  WASTE: { label: 'Perda / descarte', hint: 'Produto estragado, vencido, queimado ou derrubado.', inbound: false },
  ADJUSTMENT_OUT: { label: 'Ajuste de saída', hint: 'A contagem física deu menos que o sistema.', inbound: false },
  ADJUSTMENT_IN: { label: 'Ajuste de entrada', hint: 'A contagem física deu mais que o sistema.', inbound: true },
  RETURN_IN: { label: 'Devolução', hint: 'Item que voltou para o estoque.', inbound: true },
}

export const inventoryApi = {
  locations: () => http.get<{ data: StockLocation[] }>('/stock-locations').then((r) => r.data),
  balances: (query: { itemType: StockItemType; q?: string; includeInactive?: boolean; page?: number; limit?: number }) =>
    http.get<Page<StockBalanceRow>>('/stock/balances', { ...query, includeInactive: query.includeInactive ? 1 : undefined }),
  item: (itemType: StockItemType, itemId: string) => http.get<{ data: StockItemDetail }>(`/stock/items/${itemType}/${itemId}`).then((r) => r.data),
  movements: (itemType: StockItemType, itemId: string, page = 1, limit = 20) =>
    http.get<Page<StockMovement>>(`/stock/items/${itemType}/${itemId}/movements`, { page, limit }),
  openingBalance: (payload: MovementPayload) => http.post<{ data: StockMovement }>('/stock/opening-balances', payload).then((r) => r.data),
  adjustment: (type: AdjustmentType, payload: MovementPayload) =>
    http.post<{ data: StockMovement }>('/stock/adjustments', { ...payload, type }).then((r) => r.data),
  reverse: (movementId: string, reason: string) =>
    http.post<{ data: StockMovement }>(`/stock/movements/${movementId}/reversal`, { reason }).then((r) => r.data),
}
