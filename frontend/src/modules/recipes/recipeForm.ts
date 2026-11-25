import { parseDecimalInput, toInputDecimal } from '@/shared/format/decimal'
import type { RecipeDetail, RecipePayload } from './api'

/**
 * Estado do formulário da ficha técnica (texto pt-BR) e conversão para o
 * payload da API (decimais com ponto). Nenhum cálculo de custo aqui: o custo
 * vem sempre do backend.
 */
export interface RecipeFormState {
  name: string
  expectedYieldQty: string
  yieldUnitId: string
  instructions: string
  items: { ingredientId: string; quantity: string; unitId: string; notes: string }[]
  additionalCosts: { description: string; amount: string }[]
}

export function emptyRecipeForm(yieldUnitId = ''): RecipeFormState {
  return { name: '', expectedYieldQty: '', yieldUnitId, instructions: '', items: [], additionalCosts: [] }
}

export function formFromDetail(detail: RecipeDetail): RecipeFormState {
  return {
    name: detail.name,
    expectedYieldQty: toInputDecimal(detail.expectedYieldQty),
    yieldUnitId: detail.yieldUnit?.id ?? '',
    instructions: detail.instructions ?? '',
    items: detail.items.map((i) => ({ ingredientId: i.ingredientId, quantity: toInputDecimal(i.quantity), unitId: i.unit?.id ?? '', notes: i.notes ?? '' })),
    additionalCosts: detail.additionalCosts.map((c) => ({ description: c.description, amount: toInputDecimal(c.amount) })),
  }
}

export type FormErrors = Record<string, string[]>

/**
 * Converte o formulário em payload. Valores inválidos voltam como erros
 * locais, com os mesmos caminhos de campo que o backend usa
 * (ex.: "items[0][quantity]"), para o formulário exibir de forma uniforme.
 */
export function toRecipePayload(form: RecipeFormState, productId?: string): { payload: RecipePayload; errors: FormErrors } {
  const errors: FormErrors = {}
  const yieldQty = parseDecimalInput(form.expectedYieldQty)
  if (yieldQty === null) errors.expectedYieldQty = ['Informe o rendimento (ex.: 17 ou 16,5).']

  const items = form.items.map((item, index) => {
    const quantity = parseDecimalInput(item.quantity)
    if (quantity === null) errors[`items[${index}][quantity]`] = ['Quantidade inválida.']
    if (!item.ingredientId) errors[`items[${index}][ingredientId]`] = ['Selecione o ingrediente.']
    const notes = item.notes.trim()
    return { ingredientId: item.ingredientId, quantity: quantity ?? item.quantity, unitId: item.unitId, ...(notes ? { notes } : {}) }
  })

  const additionalCosts = form.additionalCosts.map((cost, index) => {
    const amount = parseDecimalInput(cost.amount, 2)
    if (amount === null) errors[`additionalCosts[${index}][amount]`] = ['Valor inválido (ex.: 3,50).']
    return { description: cost.description.trim(), amount: amount ?? cost.amount }
  })

  return {
    payload: {
      ...(productId ? { productId } : {}),
      name: form.name,
      expectedYieldQty: yieldQty ?? form.expectedYieldQty,
      yieldUnitId: form.yieldUnitId,
      instructions: form.instructions.trim() || null,
      items,
      additionalCosts,
    },
    errors,
  }
}
