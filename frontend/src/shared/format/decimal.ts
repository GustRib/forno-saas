/**
 * Valores monetários e quantidades trafegam como string decimal ("12.50")
 * para não perder precisão em float. Estes helpers só convertem a entrada
 * do usuário (pt-BR, com vírgula) e formatam para exibição; o backend é a
 * fonte da verdade para qualquer cálculo.
 */

/**
 * Converte texto digitado em pt-BR para decimal com ponto.
 * "1.234,56" → "1234.56"; "18,9" → "18.9"; "0.5" → "0.5".
 * Retorna null se não for um número válido.
 */
export function parseDecimalInput(input: string, maxDecimals = 6): string | null {
  let value = input.trim().replace(/\s/g, '').replace(/^R\$/i, '')
  if (value === '') return null

  if (value.includes(',')) {
    // Vírgula é o separador decimal; pontos são milhares.
    value = value.replace(/\./g, '').replace(',', '.')
  } else if ((value.match(/\./g) ?? []).length > 1) {
    value = value.replace(/\./g, '')
  }

  if (!/^\d+(\.\d+)?$/.test(value)) return null
  const [intPart = '0', fraction = ''] = value.split('.')
  if (fraction.length > maxDecimals) return null

  const normalizedInt = intPart.replace(/^0+(?=\d)/, '')
  return fraction ? `${normalizedInt}.${fraction}` : normalizedInt
}

/** "1234.5" → "1.234,5" (sem arredondar; preserva as casas informadas). */
export function formatDecimal(value: string | null | undefined, minDecimals = 0): string {
  if (value === null || value === undefined || value === '') return ''
  const negative = value.startsWith('-')
  const [intPart = '0', fraction = ''] = value.replace('-', '').split('.')
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const frac = fraction.padEnd(minDecimals, '0')
  return `${negative ? '-' : ''}${grouped}${frac ? ',' + frac : ''}`
}

/** "18.9" → "R$ 18,90". */
export function formatMoney(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  const [intPart = '0', fraction = ''] = value.split('.')
  const cents = (fraction + '00').slice(0, 2)
  return `R$ ${formatDecimal(`${intPart}.${cents}`)}`
}

/** Para preencher inputs a partir de valores da API. */
export function toInputDecimal(value: string | null | undefined): string {
  if (value === null || value === undefined) return ''
  return value.replace('.', ',')
}
