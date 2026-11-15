import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'

// Every page is loaded on demand so the first screen stays small and fast.
// `meta.permission` is the permission an admin needs to open the page (see docs/ACCESS_CONTROL.md: two accounts, developer and admin; the developer switches what the admin may see).
const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Sign in' } },
  {
    path: '/',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Dashboard', permission: 'dashboard.view' } },
      { path: 'products', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { title: 'Products', permission: 'products.view' } },
      { path: 'products/new', name: 'product-new', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'New product', permission: 'products.manage' } },
      { path: 'products/:id([0-9]+)/edit', name: 'product-edit', component: () => import('@/views/ProductFormView.vue'), meta: { title: 'Edit product', permission: 'products.view' } },
      { path: 'orders', name: 'orders', component: () => import('@/views/OrdersView.vue'), meta: { title: 'Orders', permission: 'orders.view' } },
      { path: 'orders/:id([0-9]+)', name: 'order', component: () => import('@/views/OrderDetailView.vue'), meta: { title: 'Order', permission: 'orders.view' } },
      { path: 'customers', name: 'customers', component: () => import('@/views/CustomersView.vue'), meta: { title: 'Customers', permission: 'customers.view' } },
      { path: 'wholesale', name: 'wholesale', component: () => import('@/views/WholesaleView.vue'), meta: { title: 'Wholesale', permission: 'wholesale.view' } },
      { path: 'wholesale/:id([0-9]+)', name: 'wholesale-request', component: () => import('@/views/WholesaleDetailView.vue'), meta: { title: 'Wholesale request', permission: 'wholesale.view' } },
      { path: 'discounts', name: 'discounts', component: () => import('@/views/DiscountsView.vue'), meta: { title: 'Discounts', permission: 'discounts.view' } },
      { path: 'discounts/new', name: 'discount-new', component: () => import('@/views/PromotionFormView.vue'), meta: { title: 'New discount', permission: 'discounts.manage', kind: 'discount' } },
      { path: 'discounts/:id([0-9]+)/edit', name: 'discount-edit', component: () => import('@/views/PromotionFormView.vue'), meta: { title: 'Edit discount', permission: 'discounts.manage', kind: 'discount' } },
      { path: 'coupons', name: 'coupons', component: () => import('@/views/CouponsView.vue'), meta: { title: 'Coupons', permission: 'coupons.view' } },
      { path: 'coupons/new', name: 'coupon-new', component: () => import('@/views/PromotionFormView.vue'), meta: { title: 'New coupon', permission: 'coupons.manage', kind: 'coupon' } },
      { path: 'coupons/:id([0-9]+)/edit', name: 'coupon-edit', component: () => import('@/views/PromotionFormView.vue'), meta: { title: 'Edit coupon', permission: 'coupons.manage', kind: 'coupon' } },
      // any Settings tab opens the page; the page lists only the tabs the admin may use (Banners is a tab of Settings)
      { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { title: 'Settings', permission: 'settings.*' } },
      { path: 'categories', name: 'categories', component: () => import('@/views/CategoriesView.vue'), meta: { title: 'Categories', permission: 'categories.view' } },
      { path: 'access-control', name: 'access-control', component: () => import('@/views/AccessControlView.vue'), meta: { title: 'Access Control', permission: 'access.manage' } },
      { path: 'help/:slug?', name: 'help', component: () => import('@/views/HelpView.vue'), meta: { title: 'Help' } },
      { path: 'profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { title: 'Profile' } },
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
  if (permission && auth.isLoggedIn && !auth.can(permission)) return { name: 'forbidden' }   // `settings.*` = any settings tab (see auth.can)
})

/** "<page> | <store name> Admin" */
export function updateDocumentTitle(route = router.currentRoute.value) {
  const site = useSiteStore()
  const page = route.meta?.title
  document.title = `${page ? page + ' | ' : ''}${site.rawName ? site.rawName + ' ' : ''}Admin`
}

router.afterEach((to) => updateDocumentTitle(to))

export default router
