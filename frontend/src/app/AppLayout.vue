<script setup lang="ts">
/**
 * Layout autenticado: navegação lateral no desktop; no tablet/retrato a
 * navegação vira uma gaveta aberta pelo botão de menu (alvos grandes).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from './stores/session'
import { notify } from '@/shared/ui/toast'
import { ApiError } from '@/shared/api/http'

const session = useSessionStore()
const router = useRouter()
const route = useRoute()
const navOpen = ref(false)
const switching = ref(false)

interface NavItem {
  to: string
  label: string
  icon: string
  permission?: string
}

const nav: { section: string; items: NavItem[] }[] = [
  { section: 'Geral', items: [{ to: '/', label: 'Início', icon: '🏠' }] },
  {
    section: 'Operação',
    items: [
      { to: '/estoque', label: 'Estoque', icon: '📦', permission: 'inventory.read' },
      { to: '/receitas', label: 'Fichas técnicas', icon: '📋', permission: 'recipe.read' },
    ],
  },
  {
    section: 'Cadastros',
    items: [
      { to: '/produtos', label: 'Produtos', icon: '🥐', permission: 'catalog.read' },
      { to: '/ingredientes', label: 'Ingredientes', icon: '🌾', permission: 'catalog.read' },
      { to: '/categorias', label: 'Categorias', icon: '🗂️', permission: 'catalog.read' },
      { to: '/unidades', label: 'Unidades', icon: '⚖️', permission: 'catalog.read' },
    ],
  },
  { section: 'Estabelecimento', items: [{ to: '/equipe', label: 'Equipe', icon: '👥', permission: 'members.read' }] },
]

const visibleNav = computed(() =>
  nav.map((s) => ({ ...s, items: s.items.filter((i) => !i.permission || session.can(i.permission)) })).filter((s) => s.items.length > 0),
)

watch(
  () => route.fullPath,
  () => {
    navOpen.value = false
  },
)

async function onSwitchTenant(event: Event): Promise<void> {
  const tenantId = (event.target as HTMLSelectElement).value
  if (!tenantId || tenantId === session.tenant?.id) return
  switching.value = true
  try {
    await session.switchTenant(tenantId)
    notify(`Agora você está em ${session.tenant?.name}.`, 'info')
    await router.push('/')
  } catch (e) {
    notify(e instanceof ApiError ? e.message : 'Não foi possível trocar de estabelecimento.', 'error')
  } finally {
    switching.value = false
  }
}

async function logout(): Promise<void> {
  await session.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="layout" :class="{ 'nav-open': navOpen }">
    <aside id="app-nav" class="sidebar" aria-label="Navegação principal">
      <div class="brand">
        <img src="/favicon.svg" alt="" width="32" height="32" />
        <span>Forno</span>
      </div>

      <nav>
        <div v-for="section in visibleNav" :key="section.section" class="nav-section">
          <p class="nav-title">{{ section.section }}</p>
          <RouterLink v-for="item in section.items" :key="item.to" :to="item.to" class="nav-link" :exact-active-class="item.to === '/' ? 'is-active' : ''" :active-class="item.to === '/' ? '' : 'is-active'">
            <span class="nav-icon" aria-hidden="true">{{ item.icon }}</span>
            {{ item.label }}
          </RouterLink>
        </div>
      </nav>

      <div class="soon">
        <p class="nav-title">Em breve</p>
        <p class="soon-text">Produção, caixa e vendas chegam nas próximas etapas.</p>
      </div>
    </aside>

    <div class="backdrop" aria-hidden="true" @click="navOpen = false"></div>

    <div class="main">
      <header class="topbar">
        <button type="button" class="menu-btn" aria-controls="app-nav" :aria-expanded="navOpen" aria-label="Abrir menu" @click="navOpen = !navOpen">☰</button>

        <div class="tenant">
          <label v-if="session.tenants.length > 1" class="sr-only" for="tenant-switch">Estabelecimento</label>
          <select v-if="session.tenants.length > 1" id="tenant-switch" class="f-input tenant-select" :value="session.tenant?.id" :disabled="switching" @change="onSwitchTenant">
            <option v-for="t in session.tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
          <strong v-else class="tenant-name">{{ session.tenant?.name ?? 'Sem estabelecimento' }}</strong>
          <span v-if="session.tenant" class="badge">{{ session.tenant.roleLabel }}</span>
        </div>

        <div class="user">
          <span class="user-name">{{ session.user?.name }}</span>
          <button type="button" class="logout" @click="logout">Sair</button>
        </div>
      </header>

      <p class="fiscal-notice" role="note">
        <strong>Aviso:</strong> {{ session.fiscalNotice }}
      </p>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  grid-template-columns: var(--sidebar) 1fr;
  min-height: 100vh;
}

.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  background: #2b2118;
  color: #f4efe7;
  padding: 1rem 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.25rem 0.5rem 0.75rem;
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.nav-section {
  margin-bottom: 0.75rem;
}

.nav-title {
  margin: 0 0 0.25rem;
  padding: 0 0.75rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #b8a994;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-height: var(--touch);
  padding: 0 0.75rem;
  border-radius: var(--radius-sm);
  color: inherit;
  text-decoration: none;
  font-weight: 500;
}

.nav-link:hover {
  background: rgb(255 255 255 / 8%);
}

.nav-link.is-active {
  background: var(--c-primary);
  color: #fff;
}

.nav-icon {
  width: 1.4rem;
  text-align: center;
}

.soon {
  margin-top: auto;
  padding: 0.75rem;
  border-radius: var(--radius-sm);
  background: rgb(255 255 255 / 6%);
}

.soon-text {
  margin: 0;
  font-size: 0.85rem;
  color: #d6cbbb;
}

.main {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.topbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.5rem 1.5rem;
  min-height: 64px;
  background: var(--c-surface);
  border-bottom: 1px solid var(--c-border);
  position: sticky;
  top: 0;
  z-index: 10;
}

.menu-btn {
  display: none;
  width: var(--touch);
  height: var(--touch);
  border: 1px solid var(--c-border-strong);
  border-radius: var(--radius-sm);
  background: var(--c-surface);
  font-size: 1.2rem;
  cursor: pointer;
}

.tenant {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
  flex: 1;
}

.tenant-select {
  max-width: 280px;
}

.tenant-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-name {
  color: var(--c-text-muted);
}

.logout {
  min-height: var(--touch);
  padding: 0 1rem;
  border: 1px solid var(--c-border-strong);
  border-radius: var(--radius-sm);
  background: var(--c-surface);
  font: inherit;
  cursor: pointer;
}

.fiscal-notice {
  margin: 0;
  padding: 0.5rem 1.5rem;
  background: var(--c-warning-soft);
  color: var(--c-warning);
  font-size: 0.88rem;
  border-bottom: 1px solid #f3dfb5;
}

.content {
  padding: 1.5rem;
  max-width: 1200px;
  width: 100%;
}

.backdrop {
  display: none;
}

/* Tablet em retrato e telas menores: navegação em gaveta. */
@media (max-width: 1024px) {
  .layout {
    grid-template-columns: 1fr;
  }

  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: min(300px, 85vw);
    z-index: 40;
    transform: translateX(-100%);
    transition: transform 0.2s;
  }

  .nav-open .sidebar {
    transform: none;
  }

  .nav-open .backdrop {
    display: block;
    position: fixed;
    inset: 0;
    background: rgb(43 33 24 / 40%);
    z-index: 30;
  }

  .menu-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .topbar {
    padding: 0.5rem 1rem;
  }

  .content {
    padding: 1rem;
  }
}

@media (max-width: 600px) {
  .user-name {
    display: none;
  }
}
</style>
