import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'

// Every page is loaded on demand so the first screen stays small and fast.
// `meta.feature` hides a page while that feature is switched off in the settings.
const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Sign in' } },
  {
    path: '/',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Dashboard' } },
      { path: 'products', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { title: 'Products' } },
      { path: 'products/new', name: 'product-new', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'New product' } },
      { path: 'products/:id(\d+)/edit', name: 'product-edit', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'Edit product' } },
      { path: 'orders', name: 'orders', component: () => import('@/views/OrdersView.vue'), meta: { title: 'Orders' } },
      { path: 'orders/:id(\d+)', name: 'order', component: () => import('@/views/OrderDetailView.vue'), meta: { title: 'Order' } },
      { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { title: 'Settings' } },
      { path: 'categories', name: 'categories', component: () => import('@/views/CategoriesView.vue'), meta: { title: 'Categories' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Not found' } },
]

const router = createRouter({
  // Dev server: app at "/". Production: Laravel serves the app under "/admin" (assets under /admin-assets).
  history: createWebHistory(import.meta.env.DEV ? '/' : '/admin/'),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.restore() // one "who am I" call, then cached

  if (to.meta.requiresAuth && !auth.isLoggedIn) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.guest && auth.isLoggedIn) return { name: 'dashboard' }

  // a page of a switched-off feature (a route with meta.feature) is not reachable
  const feature = to.matched.map(r => r.meta.feature).find(Boolean)
  if (feature && auth.isLoggedIn) {
    const catalog = useCatalogStore()
    try { await catalog.load() } catch { /* the page shows its own error */ }
    if (catalog.loaded && !catalog.features[feature]) return { name: 'dashboard' }
  }
})

/** "<page> | <store name> Admin" */
export function updateDocumentTitle(route = router.currentRoute.value) {
  const site = useSiteStore()
  const page = route.meta?.title
  document.title = `${page ? page + ' | ' : ''}${site.name} Admin`
}

router.afterEach((to) => updateDocumentTitle(to))

export default router
