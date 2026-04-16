<template>
  <section class="rounded-3xl border border-slate-800 bg-slate-900/60 p-8 text-slate-100">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Publications</p>
        <h1 class="text-2xl font-semibold uppercase tracking-[0.12em]">Authors</h1>
        <p class="mt-2 max-w-2xl text-xs text-slate-400">
          Browse publication authors, see whether they are linked to faculty, and review the publications tied to each name.
        </p>
      </div>
      <input
        v-model.trim="searchQuery"
        type="text"
        placeholder="Search author, faculty, or publication..."
        class="w-full max-w-sm rounded-full border border-slate-700 bg-slate-950/70 px-4 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none"
        @input="debounceFetch"
      />
    </div>

    <p v-if="error" class="mt-4 text-sm text-rose-300">{{ error }}</p>

    <div class="mt-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Visible Authors</p>
        <p class="mt-2 text-2xl font-semibold text-slate-100">{{ pagination.total || authors.length }}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Faculty Linked</p>
        <p class="mt-2 text-2xl font-semibold text-emerald-200">{{ facultyLinkedCount }}</p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Non-Faculty / Pending</p>
        <p class="mt-2 text-2xl font-semibold text-sky-200">{{ nonFacultyCount }}</p>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-800">
      <div v-if="loading" class="px-4 py-6 text-sm text-slate-300">Loading authors...</div>
      <div v-else-if="authors.length === 0" class="px-4 py-6 text-sm text-slate-300">No authors found.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
          <thead class="bg-slate-900/70 text-xs uppercase tracking-[0.16em] text-slate-400">
            <tr>
              <th class="px-4 py-3 font-semibold">Author</th>
              <th class="px-4 py-3 font-semibold">Category</th>
              <th class="px-4 py-3 font-semibold">Faculty Link</th>
              <th class="px-4 py-3 font-semibold">Publications</th>
              <th class="px-4 py-3 font-semibold">Latest Year</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800 text-slate-200">
            <tr v-for="author in authors" :key="`${author.author_name}-${author.faculty_id || 'na'}`">
              <td class="px-4 py-3 font-medium text-slate-100">{{ author.author_name }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.14em]"
                  :class="categoryBadgeClass(author.author_category)"
                >
                  {{ author.author_category }}
                </span>
              </td>
              <td class="px-4 py-3">{{ author.faculty_name || '-' }}</td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="publication in author.publications.slice(0, 3)"
                    :key="publication.id"
                    class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1 text-[11px] text-slate-300"
                  >
                    {{ publication.title }}
                    <span v-if="publication.year" class="text-slate-500">({{ publication.year }})</span>
                  </span>
                  <span
                    v-if="author.publication_count > 3"
                    class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1 text-[11px] text-slate-400"
                  >
                    +{{ author.publication_count - 3 }} more
                  </span>
                </div>
              </td>
              <td class="px-4 py-3">{{ author.latest_year || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="pagination.total_pages > 1" class="mt-6 flex items-center justify-center gap-2">
      <button
        type="button"
        class="rounded-full border border-slate-800 px-4 py-2 text-xs uppercase tracking-[0.3em] text-slate-200 disabled:opacity-40"
        :disabled="pagination.current_page === 1"
        @click="changePage(pagination.current_page - 1)"
      >
        Prev
      </button>
      <button
        v-for="page in visiblePages"
        :key="page"
        type="button"
        class="rounded-full border px-4 py-2 text-xs uppercase tracking-[0.3em]"
        :class="page === pagination.current_page
          ? 'border-emerald-400 bg-emerald-400/10 text-emerald-100'
          : 'border-slate-800 text-slate-200 hover:border-emerald-400'"
        @click="changePage(page)"
      >
        {{ page }}
      </button>
      <button
        type="button"
        class="rounded-full border border-slate-800 px-4 py-2 text-xs uppercase tracking-[0.3em] text-slate-200 disabled:opacity-40"
        :disabled="pagination.current_page === pagination.total_pages"
        @click="changePage(pagination.current_page + 1)"
      >
        Next
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'

const apiBase = import.meta.env.VITE_API_URL || 'http://localhost:8080/api'

const loading = ref(false)
const error = ref('')
const searchQuery = ref('')
const authors = ref([])
const pagination = ref({
  current_page: 1,
  per_page: 20,
  total: 0,
  total_pages: 0
})

const fetchAuthors = async () => {
  loading.value = true
  error.value = ''
  try {
    const params = {
      page: pagination.value.current_page,
      per_page: pagination.value.per_page
    }
    if (searchQuery.value.trim()) {
      params.search = searchQuery.value.trim()
    }
    const response = await axios.get(`${apiBase}/authors`, { params })
    authors.value = response.data?.data || []
    pagination.value = response.data?.pagination || pagination.value
  } catch (err) {
    authors.value = []
    error.value = err?.response?.data?.message || 'Failed to load authors.'
  } finally {
    loading.value = false
  }
}

const facultyLinkedCount = computed(() =>
  authors.value.filter((author) => author.author_category === 'Faculty').length
)

const nonFacultyCount = computed(() =>
  authors.value.filter((author) => author.author_category !== 'Faculty').length
)

const visiblePages = computed(() => {
  const pages = []
  const current = pagination.value.current_page
  const total = pagination.value.total_pages
  const start = Math.max(1, current - 2)
  const end = Math.min(total, current + 2)
  for (let i = start; i <= end; i += 1) {
    pages.push(i)
  }
  return pages
})

const categoryBadgeClass = (category) => {
  if (category === 'Faculty') {
    return 'border-emerald-500/60 bg-emerald-500/10 text-emerald-200'
  }
  if (String(category).includes('Non-Faculty')) {
    return 'border-sky-500/60 bg-sky-500/10 text-sky-200'
  }
  return 'border-amber-500/60 bg-amber-500/10 text-amber-200'
}

const changePage = (page) => {
  if (page < 1 || page > pagination.value.total_pages) return
  pagination.value.current_page = page
  fetchAuthors()
}

let searchTimeout
const debounceFetch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    pagination.value.current_page = 1
    fetchAuthors()
  }, 300)
}

onMounted(() => {
  fetchAuthors()
})
</script>
