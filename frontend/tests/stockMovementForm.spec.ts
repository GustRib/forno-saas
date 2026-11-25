import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StockMovementForm from '@/modules/inventory/StockMovementForm.vue'
import type { StockItemDetail } from '@/modules/inventory/api'
import type { Unit } from '@/modules/catalog/api'
import { ApiError } from '@/shared/api/http'

vi.mock('@/modules/inventory/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/modules/inventory/api')>()
  return {
    ...original,
    inventoryApi: { openingBalance: vi.fn(), adjustment: vi.fn() },
  }
})

const { inventoryApi } = await import('@/modules/inventory/api')

const units: Unit[] = [
  { id: 'u-g', code: 'G', name: 'Grama', dimension: 'MASS', dimensionLabel: 'Massa', baseFactor: '1', isBase: true },
  { id: 'u-kg', code: 'KG', name: 'Quilograma', dimension: 'MASS', dimensionLabel: 'Massa', baseFactor: '1000', isBase: false },
  { id: 'u-l', code: 'L', name: 'Litro', dimension: 'VOLUME', dimensionLabel: 'Volume', baseFactor: '1000', isBase: false },
]

const item: StockItemDetail = {
  itemType: 'INGREDIENT',
  itemId: 'flour',
  name: 'Farinha de trigo',
  sku: null,
  active: true,
  unit: { id: 'u-g', code: 'G', name: 'Grama', dimension: 'MASS' },
  stockTracked: true,
  balances: [],
  totalQuantity: '1500',
  hasMovements: true,
}

const movement = {
  id: 'm1', itemType: 'INGREDIENT', itemId: 'flour', locationId: 'loc', type: 'WASTE', typeLabel: 'Perda / descarte',
  quantityDelta: '-250', unitId: 'u-g', unitCost: null, reason: 'x', reversalOfId: null, reversed: false, reversible: true,
  occurredAt: '2026-11-25T12:00:00+00:00', createdAt: '2026-11-25T12:00:00+00:00', createdBy: { id: 'u', name: 'Ana' },
}

function mountForm(mode: 'opening' | 'adjustment' = 'adjustment') {
  return mount(StockMovementForm, { props: { item, mode, units }, attachTo: document.body })
}

function input(wrapper: ReturnType<typeof mountForm>, label: string) {
  const field = wrapper.findAll('.f-field').find((f) => f.find('label').text().startsWith(label))
  if (!field) throw new Error(`Campo ${label} não encontrado`)
  return field.find('input, select, textarea')
}

describe('StockMovementForm', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('oferece só unidades da mesma dimensão do item', () => {
    const wrapper = mountForm()
    const options = input(wrapper, 'Unidade').findAll('option').map((o) => o.text())
    expect(options).toEqual(['Grama (G)', 'Quilograma (KG)'])
  })

  it('envia quantidade pt-BR normalizada, tipo e motivo; unidade de estoque vai como null', async () => {
    vi.mocked(inventoryApi.adjustment).mockResolvedValue(movement as never)
    const wrapper = mountForm()

    await input(wrapper, 'Quantidade').setValue('250,5')
    await input(wrapper, 'Motivo').setValue('Saco rasgado')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(inventoryApi.adjustment).toHaveBeenCalledWith('WASTE', {
      itemType: 'INGREDIENT', itemId: 'flour', quantity: '250.5', unitId: null, reason: 'Saco rasgado', unitCost: null, occurredAt: null,
    })
    expect(wrapper.emitted('saved')?.[0]).toEqual([movement])
  })

  it('saldo inicial em outra unidade envia o unitId e o custo informado', async () => {
    vi.mocked(inventoryApi.openingBalance).mockResolvedValue({ ...movement, type: 'OPENING_BALANCE' } as never)
    const wrapper = mountForm('opening')

    await input(wrapper, 'Quantidade').setValue('1,5')
    await input(wrapper, 'Unidade').setValue('u-kg')
    await input(wrapper, 'Custo por G').setValue('0,0045')
    expect(wrapper.text()).toContain('Será convertido para G.')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(inventoryApi.openingBalance).toHaveBeenCalledWith(expect.objectContaining({ quantity: '1.5', unitId: 'u-kg', unitCost: '0.0045' }))
  })

  it('valida quantidade antes de chamar a API', async () => {
    const wrapper = mountForm()
    await input(wrapper, 'Quantidade').setValue('abc')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(inventoryApi.adjustment).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Informe uma quantidade maior que zero')
  })

  it('mostra o erro de estoque insuficiente vindo do backend', async () => {
    vi.mocked(inventoryApi.adjustment).mockRejectedValue(
      new ApiError(422, 'INSUFFICIENT_STOCK', 'Estoque insuficiente: há 1500 G disponível.'),
    )
    const wrapper = mountForm()
    await input(wrapper, 'Quantidade').setValue('2000')
    await input(wrapper, 'Motivo').setValue('Teste')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('Estoque insuficiente: há 1500 G disponível.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('mostra erros por campo e desabilita o botão enquanto envia', async () => {
    let reject: (e: unknown) => void = () => {}
    vi.mocked(inventoryApi.adjustment).mockReturnValue(new Promise((_, r) => (reject = r)) as never)
    const wrapper = mountForm()
    await input(wrapper, 'Quantidade').setValue('10')
    await wrapper.find('form').trigger('submit')

    const submit = wrapper.find('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(submit.attributes('aria-busy')).toBe('true')

    reject(new ApiError(422, 'VALIDATION_FAILED', 'Alguns campos não são válidos.', { reason: ['Informe o motivo.'] }))
    await flushPromises()
    expect(wrapper.text()).toContain('Informe o motivo.')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })
})
