import { describe, expect, it } from 'vitest'
import { formatDateTime, localInputToIso, toIsoWithOffset } from '@/shared/format/datetime'

describe('datetime', () => {
  it('gera ISO 8601 com offset explícito e sem milissegundos', () => {
    const iso = toIsoWithOffset(new Date(2026, 10, 25, 8, 30, 5))
    expect(iso).toMatch(/^2026-11-25T08:30:05[+-]\d{2}:\d{2}$/)
    expect(new Date(iso).getTime()).toBe(new Date(2026, 10, 25, 8, 30, 5).getTime())
  })

  it('converte valor de datetime-local', () => {
    expect(localInputToIso('')).toBeNull()
    expect(localInputToIso('não é data')).toBeNull()
    expect(localInputToIso('2026-11-25T08:30')).toMatch(/^2026-11-25T08:30:00[+-]\d{2}:\d{2}$/)
  })

  it('exibe no fuso do estabelecimento', () => {
    expect(formatDateTime('2026-11-25T12:00:00+00:00', 'America/Sao_Paulo')).toBe('25/11/2026, 09:00')
  })
})
