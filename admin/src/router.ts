import { createRouter, createWebHistory } from 'vue-router'
import ListsView from './views/ListsView.vue'
import ListDetailView from './views/ListDetailView.vue'

// Caddy liefert fuer jeden Pfad unter /admin/ ohne Datei die index.html aus.
export const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    { path: '/', name: 'lists', component: ListsView },
    { path: '/lists/:id', name: 'list', component: ListDetailView, props: true },
    { path: '/:pathMatch(.*)*', redirect: { name: 'lists' } },
  ],
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
})
