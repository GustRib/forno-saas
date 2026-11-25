import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSessionStore, type SessionData } from '@/app/stores/session'
import { ApiError, parseError } from '@/shared/api/http'

const sessionData: SessionData = {
  user: { id: 'u1', name: 'Ana Souza', email: 'ana@padaria.test' },
  activeTenant: {
    id: 't1',
    name: 'Padaria Central',
    timezone: 'America/Sao_Paulo',
    currency: 'BRL',
    role: 'CASHIER',
    roleLabel: 'Operador de caixa',
    permissions: ['catalog.read'],
  },
  tenants: [{ id: 't1', name: 'Padaria Central', role: 'CASHIER' }],
  fiscal: { available: false, notice: 'Emissão fiscal não disponível.' },
}

describe('session store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('expõe permissões do tenant ativo', () => {
    const store = useSessionStore()
    store.setSession(sessionData)

    expect(store.can('catalog.read')).toBe(true)
    expect(store.can('catalog.write')).toBe(false)
  })

  it('sem sessão não há permissões', () => {
    const store = useSessionStore()
    expect(store.isAuthenticated).toBe(false)
    expect(store.can('catalog.read')).toBe(false)
  })

  it('trata 401 em /auth/me como não autenticado', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { code: 'UNAUTHENTICATED', message: 'Faça login.' } }), { status: 401 })))
    const store = useSessionStore()
    await store.load()

    expect(store.loaded).toBe(true)
    expect(store.isAuthenticated).toBe(false)
    vi.unstubAllGlobals()
  })
})

describe('http errors', () => {
  it('lê o envelope de erro da API com erros por campo', async () => {
    const response = new Response(
      JSON.stringify({ error: { code: 'VALIDATION_FAILED', message: 'Alguns campos não são válidos.', details: { fields: { name: ['Informe o nome.'] } } } }),
      { status: 422 },
    )
    const error = await parseError(response)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.fields.name).toEqual(['Informe o nome.'])
  })

  it('usa mensagem genérica quando o corpo não é JSON', async () => {
    const error = await parseError(new Response('<html>', { status: 502 }))
    expect(error.code).toBe('HTTP_ERROR')
    expect(error.message).toContain('Não foi possível')
  })
})
