import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '@/app/stores/session'
import AppLayout from '@/app/AppLayout.vue'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    /** Permissão necessária para abrir a página (o backend também valida). */
    permission?: string
    title?: string
  }
}

export const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: () => import('@/modules/auth/LoginPage.vue'), meta: { public: true, title: 'Entrar' } },
  { path: '/cadastro', name: 'register', component: () => import('@/modules/auth/RegisterPage.vue'), meta: { public: true, title: 'Criar conta' } },
  {
    path: '/',
    component: AppLayout,
    children: [
      { path: '', name: 'dashboard', component: () => import('@/modules/dashboard/DashboardPage.vue'), meta: { title: 'Início' } },
      { path: 'produtos', name: 'products', component: () => import('@/modules/catalog/ProductsPage.vue'), meta: { permission: 'catalog.read', title: 'Produtos' } },
      { path: 'ingredientes', name: 'ingredients', component: () => import('@/modules/catalog/IngredientsPage.vue'), meta: { permission: 'catalog.read', title: 'Ingredientes' } },
      { path: 'categorias', name: 'categories', component: () => import('@/modules/catalog/CategoriesPage.vue'), meta: { permission: 'catalog.read', title: 'Categorias' } },
      { path: 'unidades', name: 'units', component: () => import('@/modules/catalog/UnitsPage.vue'), meta: { permission: 'catalog.read', title: 'Unidades de medida' } },
      { path: 'receitas', name: 'recipes', component: () => import('@/modules/recipes/RecipesPage.vue'), meta: { permission: 'recipe.read', title: 'Fichas técnicas' } },
      { path: 'receitas/nova/:productId', name: 'recipe-new', component: () => import('@/modules/recipes/RecipeEditorPage.vue'), meta: { permission: 'recipe.write', title: 'Nova ficha técnica' } },
      { path: 'receitas/:id', name: 'recipe', component: () => import('@/modules/recipes/RecipeEditorPage.vue'), meta: { permission: 'recipe.read', title: 'Ficha técnica' } },
      { path: 'estoque', name: 'stock', component: () => import('@/modules/inventory/StockPage.vue'), meta: { permission: 'inventory.read', title: 'Estoque' } },
      { path: 'equipe', name: 'members', component: () => import('@/modules/members/MembersPage.vue'), meta: { permission: 'members.read', title: 'Equipe' } },
      { path: 'sem-acesso', name: 'forbidden', component: () => import('@/app/ForbiddenPage.vue'), meta: { title: 'Sem acesso' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export function createAppRouter() {
  const router = createRouter({ history: createWebHistory(), routes })

  router.beforeEach(async (to) => {
    const session = useSessionStore()
    if (!session.loaded) {
      try {
        await session.load()
      } catch {
        // Falha de rede: deixa a página decidir; não força logout.
      }
    }

    if (to.meta.public) {
      return session.isAuthenticated ? { name: 'dashboard' } : true
    }
    if (!session.isAuthenticated) {
      return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
    }
    if (to.meta.permission && !session.can(to.meta.permission)) {
      return to.name === 'forbidden' ? true : { name: 'forbidden' }
    }
    return true
  })

  router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} · Forno` : 'Forno'
  })

  return router
}
