import { http } from '@/shared/api/http'

export type Role = 'OWNER' | 'MANAGER' | 'CASHIER' | 'PRODUCTION' | 'STOCK'

export interface Member {
  id: string
  userId: string
  name: string | null
  email: string | null
  role: Role
  roleLabel: string
  status: 'ACTIVE' | 'DISABLED' | 'INVITED'
  permissions: string[]
  createdAt: string
}

export const roleLabels: Record<Role, string> = {
  OWNER: 'Proprietário',
  MANAGER: 'Gerente',
  CASHIER: 'Operador de caixa',
  PRODUCTION: 'Produção',
  STOCK: 'Estoque',
}

export const membersApi = {
  list: () => http.get<{ data: Member[] }>('/members').then((r) => r.data),
  create: (input: { name: string; email: string; role: Role; password: string }) =>
    http.post<{ data: Member }>('/members', input).then((r) => r.data),
  update: (id: string, input: { role: Role; status: 'ACTIVE' | 'DISABLED' }) =>
    http.put<{ data: Member }>(`/members/${id}`, input).then((r) => r.data),
}
