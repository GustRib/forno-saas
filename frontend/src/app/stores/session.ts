import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, http } from '@/shared/api/http'

export interface SessionUser {
  id: string
  name: string
  email: string
}

export interface ActiveTenant {
  id: string
  name: string
  timezone: string
  currency: string
  role: string
  roleLabel: string
  permissions: string[]
}

export interface SessionData {
  user: SessionUser
  activeTenant: ActiveTenant | null
  tenants: { id: string; name: string; role: string }[]
  fiscal: { available: boolean; notice: string }
}

export interface RegisterInput {
  bakeryName: string
  name: string
  email: string
  password: string
}

/**
 * Estado da sessão. As permissões aqui servem só para esconder ações na
 * interface; o backend valida tudo de novo (REQUIREMENTS §2).
 */
export const useSessionStore = defineStore('session', () => {
  const session = ref<SessionData | null>(null)
  const loaded = ref(false)

  const user = computed(() => session.value?.user ?? null)
  const tenant = computed(() => session.value?.activeTenant ?? null)
  const tenants = computed(() => session.value?.tenants ?? [])
  const fiscalNotice = computed(() => session.value?.fiscal.notice ?? '')
  const isAuthenticated = computed(() => session.value !== null)

  function can(permission: string): boolean {
    return tenant.value?.permissions.includes(permission) ?? false
  }

  async function load(): Promise<void> {
    try {
      // 401 aqui é esperado (visitante sem sessão): não redireciona.
      const { data } = await http.get<{ data: SessionData }>('/auth/me', undefined, { skipAuthRedirect: true })
      session.value = data
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) throw e
      session.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(email: string, password: string): Promise<void> {
    const { data } = await http.post<{ data: SessionData }>('/auth/login', { email, password }, { skipAuthRedirect: true })
    session.value = data
    loaded.value = true
  }

  async function register(input: RegisterInput): Promise<void> {
    const { data } = await http.post<{ data: SessionData }>('/auth/register', input, { skipAuthRedirect: true })
    session.value = data
    loaded.value = true
  }

  async function logout(): Promise<void> {
    try {
      await http.post('/auth/logout', {}, { skipAuthRedirect: true })
    } finally {
      clear()
    }
  }

  async function switchTenant(tenantId: string): Promise<void> {
    const { data } = await http.post<{ data: SessionData }>('/session/tenant', { tenantId })
    session.value = data
  }

  function clear(): void {
    session.value = null
    loaded.value = true
  }

  function setSession(data: SessionData | null): void {
    session.value = data
    loaded.value = true
  }

  return { session, loaded, user, tenant, tenants, fiscalNotice, isAuthenticated, can, load, login, register, logout, switchTenant, clear, setSession }
})
