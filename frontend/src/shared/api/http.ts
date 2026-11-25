/**
 * Cliente HTTP da API Forno.
 *
 * - Autenticação por cookie de sessão HttpOnly (same-origin); nada sensível
 *   é guardado em localStorage.
 * - Toda mutação envia JSON + X-Requested-With (proteção CSRF do backend).
 * - Erros chegam no envelope {"error": {code, message, details}}.
 */

export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: FieldErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  get isNetworkError(): boolean {
    return this.status === 0
  }
}

export interface Page<T> {
  data: T[]
  meta: { page: number; limit: number; total: number }
}

type UnauthorizedHandler = () => void
let onUnauthorized: UnauthorizedHandler | null = null

/** Registrado pelo app para redirecionar ao login quando a sessão expira. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  onUnauthorized = handler
}

type Query = Record<string, string | number | boolean | null | undefined>

function buildUrl(path: string, query?: Query): string {
  const url = `/api/v1${path}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

export async function parseError(response: Response): Promise<ApiError> {
  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    // corpo vazio ou não-JSON
  }
  const error = (body as { error?: { code?: string; message?: string; details?: { fields?: FieldErrors } } } | null)?.error
  return new ApiError(
    response.status,
    error?.code ?? 'HTTP_ERROR',
    error?.message ?? 'Não foi possível concluir a operação. Tente novamente.',
    error?.details?.fields ?? {},
  )
}

async function request<T>(method: string, path: string, options: { body?: unknown; query?: Query; skipAuthRedirect?: boolean } = {}): Promise<T> {
  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    })
  } catch {
    // NFR-010: deixar claro que o problema é de conexão.
    throw new ApiError(0, 'NETWORK_ERROR', 'Sem conexão com o servidor. Verifique a internet e tente novamente.')
  }

  if (!response.ok) {
    const error = await parseError(response)
    if (response.status === 401 && !options.skipAuthRedirect) onUnauthorized?.()
    throw error
  }

  if (response.status === 204) return undefined as T
  return (await response.json()) as T
}

export const http = {
  get: <T>(path: string, query?: Query, opts?: { skipAuthRedirect?: boolean }) => request<T>('GET', path, { query, ...opts }),
  post: <T>(path: string, body?: unknown, opts?: { skipAuthRedirect?: boolean }) => request<T>('POST', path, { body: body ?? {}, ...opts }),
  put: <T>(path: string, body: unknown) => request<T>('PUT', path, { body }),
  delete: <T = void>(path: string) => request<T>('DELETE', path),
}
