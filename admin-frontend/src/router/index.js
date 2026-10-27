import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

// Every page is loaded on demand so the first screen stays small and fast.
const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Sign in' } },
  {
    path: '/',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Dashboard' } },
      { path: 'categories', name: 'categories', component: () => import('@/views/CategoriesView.vue'), meta: { title: 'Categories' } },
      { path: 'companies', name: 'companies', component: () => import('@/views/CompaniesView.vue'), meta: { title: 'Companies' } },
      { path: 'companies/new', name: 'company-new', component: () => import('@/views/CompanyFormView.vue'), meta: { title: 'New company' } },
      { path: 'companies/:id(\\d+)', name: 'company', component: () => import('@/views/CompanyDetailView.vue'), meta: { title: 'Company' } },
      { path: 'companies/:id(\\d+)/edit', name: 'company-edit', component: () => import('@/views/CompanyFormView.vue'), meta: { title: 'Edit company' } },
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
})

router.afterEach((to) => {
  document.title = `${to.meta.title ? to.meta.title + ' | ' : ''}Dentalkart Admin`
})

export default router
