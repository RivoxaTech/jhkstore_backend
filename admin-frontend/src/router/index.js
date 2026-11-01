import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'

// Every page is loaded on demand so the first screen stays small and fast.
// `meta.permission` is the permission an admin needs to open the page (see the roles in the Users & Roles screen).
const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Sign in' } },
  {
    path: '/',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Dashboard', permission: 'dashboard.view' } },
      { path: 'products', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { title: 'Products', permission: 'catalog.view' } },
      { path: 'products/new', name: 'product-new', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'New product', permission: 'catalog.manage' } },
      { path: 'products/:id([0-9]+)/edit', name: 'product-edit', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'Edit product', permission: 'catalog.view' } },
      { path: 'orders', name: 'orders', component: () => import('@/views/OrdersView.vue'), meta: { title: 'Orders', permission: 'orders.view' } },
      { path: 'orders/:id([0-9]+)', name: 'order', component: () => import('@/views/OrderDetailView.vue'), meta: { title: 'Order', permission: 'orders.view' } },
      { path: 'banners', name: 'banners', component: () => import('@/views/BannersView.vue'), meta: { title: 'Banners', permission: 'banners.view' } },
      { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { title: 'Settings', permission: 'settings.view' } },
      { path: 'categories', name: 'categories', component: () => import('@/views/CategoriesView.vue'), meta: { title: 'Categories', permission: 'catalog.view' } },
      { path: 'users', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { title: 'Users & roles', permission: 'admins.manage' } },
      { path: 'forbidden', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { title: 'No access' } },
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

  // a page the admin's role does not allow shows "no access" (the API refuses it as well)
  const permission = to.matched.map(r => r.meta.permission).find(Boolean)
  if (permission && auth.isLoggedIn && !auth.can(permission)) return { name: 'forbidden' }
})

/** "<page> | <store name> Admin" */
export function updateDocumentTitle(route = router.currentRoute.value) {
  const site = useSiteStore()
  const page = route.meta?.title
  document.title = `${page ? page + ' | ' : ''}${site.name} Admin`
}

router.afterEach((to) => updateDocumentTitle(to))

export default router
