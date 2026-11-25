import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { emptyRecipeForm, formFromDetail, toRecipePayload } from '@/modules/recipes/recipeForm'
import RecipeCostSummary from '@/modules/recipes/RecipeCostSummary.vue'
import type { RecipeCost, RecipeDetail } from '@/modules/recipes/api'

describe('recipeForm', () => {
  it('converte digitação pt-BR em payload com decimais com ponto', () => {
    const form = {
      ...emptyRecipeForm('u-kg'),
      name: 'Pão francês',
      expectedYieldQty: '16,5',
      instructions: '  ',
      items: [
        { ingredientId: 'flour', quantity: '10', unitId: 'u-kg', notes: '' },
        { ingredientId: 'salt', quantity: '0,2', unitId: 'u-kg', notes: ' refinado ' },
      ],
      additionalCosts: [{ description: ' Embalagem ', amount: '3,5' }],
    }
    const { payload, errors } = toRecipePayload(form, 'bread')

    expect(errors).toEqual({})
    expect(payload).toEqual({
      productId: 'bread',
      name: 'Pão francês',
      expectedYieldQty: '16.5',
      yieldUnitId: 'u-kg',
      instructions: null,
      items: [
        { ingredientId: 'flour', quantity: '10', unitId: 'u-kg' },
        { ingredientId: 'salt', quantity: '0.2', unitId: 'u-kg', notes: 'refinado' },
      ],
      additionalCosts: [{ description: 'Embalagem', amount: '3.5' }],
    })
  })

  it('aponta erros com os mesmos caminhos de campo do backend', () => {
    const form = {
      ...emptyRecipeForm('u-kg'),
      expectedYieldQty: 'muito',
      items: [{ ingredientId: '', quantity: 'abc', unitId: '', notes: '' }],
      additionalCosts: [{ description: 'Gás', amount: '1,234' }],
    }
    const { errors } = toRecipePayload(form)

    expect(Object.keys(errors).sort()).toEqual(['additionalCosts[0][amount]', 'expectedYieldQty', 'items[0][ingredientId]', 'items[0][quantity]'])
  })

  it('não envia productId ao atualizar', () => {
    const { payload } = toRecipePayload({ ...emptyRecipeForm('u'), expectedYieldQty: '1' })
    expect(payload).not.toHaveProperty('productId')
  })

  it('preenche o formulário a partir da API em formato pt-BR', () => {
    const detail = {
      name: 'Pão', expectedYieldQty: '16.5', yieldUnit: { id: 'u-kg' }, instructions: null,
      items: [{ ingredientId: 'flour', quantity: '0.2', unit: { id: 'u-kg' }, notes: null }],
      additionalCosts: [{ description: 'Gás', amount: '1.50' }],
    } as unknown as RecipeDetail

    const form = formFromDetail(detail)
    expect(form.expectedYieldQty).toBe('16,5')
    expect(form.items[0]).toEqual({ ingredientId: 'flour', quantity: '0,2', unitId: 'u-kg', notes: '' })
    expect(form.additionalCosts[0]?.amount).toBe('1,50')
  })
})

describe('RecipeCostSummary', () => {
  const cost: RecipeCost = {
    lines: [
      { ingredientId: 'f', ingredientName: 'Farinha', quantity: '10', unitCode: 'KG', stockQuantity: '10000', stockUnitCode: 'G', unitCost: '0.0045', lineCost: '45.000000', costChangedAt: '2026-11-25T12:00:00+00:00', costSource: 'STOCK_MOVEMENT' },
      { ingredientId: 'w', ingredientName: 'Água', quantity: '6', unitCode: 'L', stockQuantity: '6', stockUnitCode: 'L', unitCost: '0', lineCost: '0.000000', costChangedAt: null, costSource: 'MANUAL' },
    ],
    ingredientsCost: '45.00',
    additionalCost: '7.50',
    totalCost: '52.50',
    costPerYieldUnit: '3.088235',
    costPerSaleUnit: '0.003088',
    complete: true,
    warnings: [{ code: 'ZERO_COST', message: 'Água está com custo zero.', ingredientId: 'w' }],
  }

  it('mostra totais, custo por unidade, conversão e origem do custo', () => {
    const wrapper = mount(RecipeCostSummary, { props: { cost, yieldQty: '17', yieldUnitCode: 'KG', saleUnitCode: 'G', timeZone: 'America/Sao_Paulo' } })
    const text = wrapper.text()

    expect(text).toContain('R$ 45,00')
    expect(text).toContain('R$ 52,50')
    expect(text).toContain('rende 17 KG')
    expect(text).toContain('Por KG produzido')
    expect(text).toContain('R$ 3,0882')
    expect(text).toContain('Por G vendido: R$ 0,0030')
    expect(text).toContain('= 10.000 G')
    expect(text).toContain('Média ponderada do estoque · 25/11/2026, 09:00')
    expect(text).toContain('Água está com custo zero.')
  })

  it('destaca avisos que impedem a ativação', () => {
    const wrapper = mount(RecipeCostSummary, {
      props: { cost: { ...cost, complete: false, warnings: [{ code: 'NO_ITEMS', message: 'A receita não tem ingredientes.' }] }, yieldQty: '1', yieldUnitCode: 'UN', saleUnitCode: 'UN', timeZone: 'UTC' },
    })

    expect(wrapper.find('.warnings li.blocking').text()).toBe('A receita não tem ingredientes.')
    expect(wrapper.text()).not.toContain('vendido')
  })
})
