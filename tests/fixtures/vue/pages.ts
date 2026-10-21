import type { Component } from 'vue'
import Create from './pages/Create.vue'
import Index from './pages/Index.vue'
import PostCard from './pages/PostCard.vue'

export const pages: Record<string, Component> = { Create, Index, PostCard }
