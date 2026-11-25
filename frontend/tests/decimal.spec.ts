import { describe, expect, it } from 'vitest'
import { formatDecimal, formatMoney, parseDecimalInput, toInputDecimal } from '@/shared/format/decimal'

describe('parseDecimalInput', () => {
  it('aceita vírgula como separador decimal', () => {
    expect(parseDecimalInput('18,9')).toBe('18.9')
    expect(parseDecimalInput('1.234,56')).toBe('1234.56')
    expect(parseDecimalInput('R$ 7,50')).toBe('7.50')
  })

  it('aceita ponto decimal e remove zeros à esquerda', () => {
    expect(parseDecimalInput('0.0045')).toBe('0.0045')
    expect(parseDecimalInput('007')).toBe('7')
  })

  it('rejeita valores inválidos ou com casas demais', () => {
    expect(parseDecimalInput('')).toBeNull()
    expect(parseDecimalInput('abc')).toBeNull()
    expect(parseDecimalInput('-1')).toBeNull()
    expect(parseDecimalInput('1,234', 2)).toBeNull()
  })

  it('não usa float (sem perda de precisão)', () => {
    expect(parseDecimalInput('0,1')).toBe('0.1')
    expect(parseDecimalInput('123456789012,123456')).toBe('123456789012.123456')
  })
})

describe('formatação', () => {
  it('formata dinheiro em pt-BR', () => {
    expect(formatMoney('18.9')).toBe('R$ 18,90')
    expect(formatMoney('1234.5')).toBe('R$ 1.234,50')
    expect(formatMoney('0')).toBe('R$ 0,00')
  })

  it('formata decimais preservando as casas', () => {
    expect(formatDecimal('25000')).toBe('25.000')
    expect(formatDecimal('0.0045')).toBe('0,0045')
    expect(formatDecimal('1.5', 2)).toBe('1,50')
  })

  it('prepara valores da API para inputs', () => {
    expect(toInputDecimal('12.50')).toBe('12,50')
    expect(toInputDecimal(null)).toBe('')
  })
})
