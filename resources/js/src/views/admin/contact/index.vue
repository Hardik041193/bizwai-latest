<template>
  <div class="p-6">

    <!-- ── Breadcrumb + Header ─────────────────────────────────────── -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <nav class="text-sm text-gray-500 mb-1">
          <span>Admin</span>
          <span class="mx-1">/</span>
          <span class="text-gray-700 font-medium">Contact Messages</span>
        </nav>
        <h1 class="text-2xl font-bold text-gray-800">Contact Messages</h1>
      </div>
      <span
        v-if="stats.unread > 0"
        class="px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-600"
      >
        {{ stats.unread }} Unread
      </span>
    </div>

    <!-- ── Stat Cards ──────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">

      <!-- Total -->
      <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7
                 a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-400">Total</p>
          <p class="text-xl font-bold text-gray-800">{{ stats.total }}</p>
          <p class="text-xs text-indigo-500 mt-0.5">All messages</p>
        </div>
      </div>

      <!-- Unread -->
      <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-red-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11
                 a6.004 6.004 0 00-9.788-4.612M6 11v3.159c0 .538-.214 1.055-.595
                 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-400">Unread</p>
          <p class="text-xl font-bold text-red-600">{{ stats.unread }}</p>
          <p class="text-xs text-red-400 mt-0.5">Need attention</p>
        </div>
      </div>

      <!-- Read -->
      <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-green-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-400">Read</p>
          <p class="text-xl font-bold text-green-600">{{ stats.read }}</p>
          <p class="text-xs text-green-400 mt-0.5">Reviewed</p>
        </div>
      </div>

      <!-- Today -->
      <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-yellow-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7
                 a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-400">Today</p>
          <p class="text-xl font-bold text-gray-800">{{ stats.today }}</p>
          <p class="text-xs text-yellow-500 mt-0.5">New today</p>
        </div>
      </div>

    </div>

    <!-- ── Filters Bar ─────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm p-4 mb-4 flex flex-wrap gap-3 items-center justify-between">
      <div class="flex flex-wrap gap-3 items-center">

        <!-- Search -->
        <div class="relative">
          <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400 pointer-events-none"
            fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            v-model="filters.search"
            @input="debouncedFetch"
            type="text"
            placeholder="Search name, email, subject..."
            class="border border-gray-200 rounded-lg pl-9 pr-3 py-2 text-sm w-64
                   focus:outline-none focus:ring-2 focus:ring-indigo-300"
          />
        </div>

        <!-- Status Filter -->
        <select
          v-model="filters.status"
          @change="resetAndFetch"
          class="border border-gray-200 rounded-lg px-3 py-2 text-sm
                 focus:outline-none focus:ring-2 focus:ring-indigo-300"
        >
          <option value="">All Status</option>
          <option value="unread">Unread</option>
          <option value="read">Read</option>
        </select>

        <!-- Per Page -->
        <select
          v-model="filters.perPage"
          @change="resetAndFetch"
          class="border border-gray-200 rounded-lg px-3 py-2 text-sm
                 focus:outline-none focus:ring-2 focus:ring-indigo-300"
        >
          <option :value="10">10 / page</option>
          <option :value="15">15 / page</option>
          <option :value="25">25 / page</option>
          <option :value="50">50 / page</option>
        </select>

      </div>

      <!-- Bulk Actions -->
      <div v-if="selectedIds.length > 0" class="flex gap-2 items-center">
        <span class="text-xs text-gray-500 font-medium">{{ selectedIds.length }} selected</span>
        <button
          @click="bulkMarkRead"
          :disabled="bulkLoading"
          class="px-3 py-1.5 rounded-lg bg-green-50 hover:bg-green-100 text-green-700
                 text-xs font-medium transition disabled:opacity-50"
        >
          Mark Read
        </button>
        <button
          @click="bulkDelete"
          :disabled="bulkLoading"
          class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-700
                 text-xs font-medium transition disabled:opacity-50"
        >
          Delete
        </button>
      </div>
    </div>

    <!-- ── Table ───────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">

      <!-- Loader -->
      <div v-if="loading" class="flex justify-center items-center py-16">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      </div>

      <table v-else class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
          <tr>
            <th class="px-4 py-3 text-left w-10">
              <input
                type="checkbox"
                :checked="allSelected"
                @change="toggleSelectAll"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
            </th>
            <th class="px-4 py-3 text-left">#</th>
            <th class="px-4 py-3 text-left">Name</th>
            <th class="px-4 py-3 text-left">Email</th>
            <th class="px-4 py-3 text-left">Subject</th>
            <th class="px-4 py-3 text-left">Message</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left">Date</th>
            <th class="px-4 py-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">

          <!-- Empty state -->
          <tr v-if="contacts.length === 0">
            <td colspan="9" class="text-center py-16">
              <div class="flex flex-col items-center gap-2 text-gray-400">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7
                       a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span class="text-sm font-medium">No messages found.</span>
                <span class="text-xs">Try adjusting your search or filter.</span>
              </div>
            </td>
          </tr>

          <!-- Data rows -->
          <tr
            v-for="contact in contacts"
            :key="contact.id"
            :class="contact.status === 'unread' ? 'bg-indigo-50/40' : 'bg-white'"
            class="hover:bg-gray-50 transition"
          >
            <!-- Checkbox -->
            <td class="px-4 py-3">
              <input
                type="checkbox"
                :value="contact.id"
                v-model="selectedIds"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
            </td>

            <!-- ID -->
            <td class="px-4 py-3 text-gray-400 font-mono text-xs">{{ contact.id }}</td>

            <!-- Name -->
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div
                  class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold
                         flex items-center justify-center flex-shrink-0 uppercase"
                >
                  {{ contact.name?.charAt(0) ?? '?' }}
                </div>
                <span
                  :class="contact.status === 'unread'
                    ? 'font-semibold text-gray-900'
                    : 'font-medium text-gray-700'"
                >
                  {{ contact.name }}
                </span>
              </div>
            </td>

            <!-- Email -->
            <td class="px-4 py-3 text-gray-500 text-xs">{{ contact.email }}</td>

            <!-- Subject -->
            <td class="px-4 py-3 text-gray-700 max-w-[160px]">
              <div class="truncate" :title="contact.subject">{{ contact.subject }}</div>
            </td>

            <!-- Message preview -->
            <td class="px-4 py-3 text-gray-500 max-w-[180px]">
              <div class="truncate text-xs" :title="contact.message">{{ contact.message }}</div>
            </td>

            <!-- Status badge -->
            <td class="px-4 py-3">
              <span
                :class="contact.status === 'unread'
                  ? 'bg-red-100 text-red-600'
                  : 'bg-green-100 text-green-600'"
                class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize"
              >
                {{ contact.status }}
              </span>
            </td>

            <!-- Date -->
            <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">
              {{ formatDate(contact.created_at) }}
            </td>

            <!-- Actions -->
            <td class="px-4 py-3">
              <div class="flex gap-1.5">
                <!-- View -->
                <button
                  @click="openModal(contact)"
                  class="p-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-600 transition"
                  title="View message"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7
                         -1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                </button>
                <!-- Mark Read -->
                <button
                  v-if="contact.status === 'unread'"
                  @click="markRead(contact.id)"
                  class="p-1.5 rounded-lg bg-green-50 hover:bg-green-100 text-green-600 transition"
                  title="Mark as read"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M5 13l4 4L19 7"/>
                  </svg>
                </button>
                <!-- Delete -->
                <button
                  @click="deleteContact(contact.id)"
                  class="p-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 transition"
                  title="Delete"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858
                         L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            </td>
          </tr>

        </tbody>
      </table>

      <!-- ── Pagination ──────────────────────────────────────────── -->
      <div
        v-if="pagination && pagination.last_page > 1"
        class="flex items-center justify-between px-5 py-4 border-t border-gray-100 text-sm text-gray-500"
      >
        <span>
          Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} messages
        </span>
        <div class="flex gap-1">
          <button
            @click="goToPage(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="w-8 h-8 rounded-lg text-sm font-medium transition bg-gray-100
                   hover:bg-gray-200 text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed"
          >&lsaquo;</button>

          <template v-for="page in visiblePages" :key="page">
            <span
              v-if="page === '...'"
              class="w-8 h-8 flex items-center justify-center text-gray-400 text-xs"
            >…</span>
            <button
              v-else
              @click="goToPage(page)"
              :class="page === pagination.current_page
                ? 'bg-indigo-600 text-white'
                : 'bg-gray-100 hover:bg-gray-200 text-gray-700'"
              class="w-8 h-8 rounded-lg text-sm font-medium transition"
            >{{ page }}</button>
          </template>

          <button
            @click="goToPage(pagination.current_page + 1)"
            :disabled="pagination.current_page === pagination.last_page"
            class="w-8 h-8 rounded-lg text-sm font-medium transition bg-gray-100
                   hover:bg-gray-200 text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed"
          >&rsaquo;</button>
        </div>
      </div>

    </div>

    <!-- ── View Message Modal ───────────────────────────────────────── -->
    <Transition name="modal-fade">
      <div
        v-if="selectedContact"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
        @click.self="selectedContact = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">

          <!-- Modal Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 font-bold text-base
                       flex items-center justify-center uppercase flex-shrink-0"
              >
                {{ selectedContact.name?.charAt(0) ?? '?' }}
              </div>
              <div>
                <h2 class="text-base font-semibold text-gray-800 leading-tight">
                  {{ selectedContact.name }}
                </h2>
                <p class="text-xs text-gray-400">{{ selectedContact.email }}</p>
              </div>
            </div>
            <button
              @click="selectedContact = null"
              class="w-8 h-8 rounded-lg bg-gray-100 hover:bg-gray-200 flex items-center
                     justify-center text-gray-500 hover:text-gray-700 transition"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Modal Body -->
          <div class="px-6 py-4 space-y-3 text-sm">
            <div class="flex items-center gap-3">
              <span class="text-gray-400 w-16 flex-shrink-0 text-xs uppercase tracking-wide font-medium">Subject</span>
              <span class="text-gray-800 font-medium">{{ selectedContact.subject }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-gray-400 w-16 flex-shrink-0 text-xs uppercase tracking-wide font-medium">Status</span>
              <span
                :class="selectedContact.status === 'unread'
                  ? 'bg-red-100 text-red-600'
                  : 'bg-green-100 text-green-600'"
                class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize"
              >{{ selectedContact.status }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-gray-400 w-16 flex-shrink-0 text-xs uppercase tracking-wide font-medium">Date</span>
              <span class="text-gray-600 text-xs">{{ formatDate(selectedContact.created_at) }}</span>
            </div>
            <div>
              <span class="text-gray-400 text-xs uppercase tracking-wide font-medium block mb-2">Message</span>
              <div class="bg-gray-50 rounded-xl p-4 text-gray-700 leading-relaxed text-sm
                          whitespace-pre-wrap border border-gray-100 max-h-48 overflow-y-auto">
                {{ selectedContact.message }}
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="flex gap-2 px-6 py-4 border-t border-gray-100">
            <button
              v-if="selectedContact.status === 'unread'"
              @click="markReadAndClose"
              class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium
                     py-2.5 rounded-xl transition flex items-center justify-center gap-2"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
              Mark as Read
            </button>
            <button
              @click="deleteFromModal"
              class="px-4 py-2.5 rounded-xl bg-red-50 hover:bg-red-100 text-red-600
                     text-sm font-medium transition flex items-center gap-2"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858
                     L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Delete
            </button>
            <button
              @click="selectedContact = null"
              class="px-4 py-2.5 rounded-xl bg-gray-100 hover:bg-gray-200
                     text-gray-700 text-sm font-medium transition"
            >
              Close
            </button>
          </div>

        </div>
      </div>
    </Transition>

    <!-- ── Toast Notification ──────────────────────────────────────── -->
    <Transition name="toast-slide">
      <div
        v-if="toast.show"
        :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'"
        class="fixed bottom-6 right-6 z-50 text-white text-sm font-medium
               px-4 py-3 rounded-xl shadow-lg flex items-center gap-2"
      >
        <svg v-if="toast.type === 'success'" class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ toast.message }}
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

// ── State ────────────────────────────────────────────────────────────────────
const contacts        = ref([])
const pagination      = ref(null)
const loading         = ref(false)
const bulkLoading     = ref(false)
const selectedContact = ref(null)
const selectedIds     = ref([])

const filters = ref({
  search:  '',
  status:  '',
  perPage: 15,
})
const currentPage = ref(1)

// Stats are computed from a separate full fetch (no filters)
const stats = ref({ total: 0, unread: 0, read: 0, today: 0 })

const toast = ref({ show: false, message: '', type: 'success' })

// ── Computed ─────────────────────────────────────────────────────────────────
const allSelected = computed(
  () =>
    contacts.value.length > 0 &&
    contacts.value.every(c => selectedIds.value.includes(c.id))
)

const visiblePages = computed(() => {
  if (!pagination.value) return []
  const { current_page, last_page } = pagination.value
  if (last_page <= 7) return Array.from({ length: last_page }, (_, i) => i + 1)
  const pages = [1]
  if (current_page > 3) pages.push('...')
  const start = Math.max(2, current_page - 1)
  const end   = Math.min(last_page - 1, current_page + 1)
  for (let i = start; i <= end; i++) pages.push(i)
  if (current_page < last_page - 2) pages.push('...')
  pages.push(last_page)
  return pages
})

// ── Debounce ─────────────────────────────────────────────────────────────────
let debounceTimer = null
function debouncedFetch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    currentPage.value = 1
    fetchContacts()
  }, 400)
}

function resetAndFetch() {
  currentPage.value = 1
  fetchContacts()
}

// ── API Calls ─────────────────────────────────────────────────────────────────

/**
 * Fetch the paginated contacts list (respects filters + page)
 */
async function fetchContacts() {
  loading.value     = true
  selectedIds.value = []
  try {
    const { data } = await axios.get('/api/admin/contact', {
      params: {
        search:   filters.value.search   || undefined,
        status:   filters.value.status   || undefined,
        per_page: filters.value.perPage,
        page:     currentPage.value,
      },
    })

    // Laravel paginate() returns: data, current_page, last_page, from, to, total
    contacts.value   = data.data
    pagination.value = {
      current_page: data.current_page,
      last_page:    data.last_page,
      from:         data.from ?? 0,
      to:           data.to   ?? 0,
      total:        data.total,
    }
  } catch (err) {
    console.error('fetchContacts error:', err)
    showToast('Failed to load messages', 'error')
  } finally {
    loading.value = false
  }
}

/**
 * Fetch stats independently (large per_page, no filters)
 * so stat cards show global counts, not filtered counts.
 */
async function fetchStats() {
  try {
    const { data } = await axios.get('/api/admin/contact', {
      params: { per_page: 1000 },
    })
    const all   = data.data ?? []
    const today = new Date().toDateString()
    stats.value = {
      total:  data.total ?? all.length,
      unread: all.filter(c => c.status === 'unread').length,
      read:   all.filter(c => c.status === 'read').length,
      today:  all.filter(c => new Date(c.created_at).toDateString() === today).length,
    }
  } catch {
    // Stats failing is non-critical, ignore silently
  }
}

function goToPage(page) {
  if (!pagination.value) return
  if (page < 1 || page > pagination.value.last_page) return
  currentPage.value = page
  fetchContacts()
}

// ── Single Row Actions ────────────────────────────────────────────────────────

function openModal(contact) {
  selectedContact.value = { ...contact }
  // Auto-mark as read when admin opens the modal
  if (contact.status === 'unread') {
    markRead(contact.id, { silent: true })
  }
}

async function markRead(id, options = {}) {
  try {
    await axios.patch(`/api/admin/contact/${id}/read`)

    // Update the current page list reactively
    const row = contacts.value.find(c => c.id === id)
    if (row) row.status = 'read'

    // Update the open modal if it's showing this contact
    if (selectedContact.value?.id === id) {
      selectedContact.value = { ...selectedContact.value, status: 'read' }
    }

    // Refresh stats
    fetchStats()

    if (!options.silent) showToast('Marked as read')
  } catch {
    showToast('Failed to update status', 'error')
  }
}

function markReadAndClose() {
  if (selectedContact.value) {
    markRead(selectedContact.value.id)
  }
  selectedContact.value = null
}

async function deleteContact(id) {
  if (!confirm('Delete this message?')) return
  try {
    await axios.delete(`/api/admin/contact/${id}`)
    contacts.value = contacts.value.filter(c => c.id !== id)
    fetchStats()
    showToast('Message deleted')
  } catch {
    showToast('Failed to delete', 'error')
  }
}

function deleteFromModal() {
  if (!selectedContact.value) return
  const id = selectedContact.value.id
  selectedContact.value = null
  deleteContact(id)
}

// ── Bulk Actions ──────────────────────────────────────────────────────────────

function toggleSelectAll(e) {
  selectedIds.value = e.target.checked
    ? contacts.value.map(c => c.id)
    : []
}

async function bulkMarkRead() {
  const unreadIds = selectedIds.value.filter(id => {
    const c = contacts.value.find(c => c.id === id)
    return c && c.status === 'unread'
  })
  if (unreadIds.length === 0) {
    showToast('All selected messages are already read')
    return
  }

  bulkLoading.value = true
  try {
    await axios.post('/api/admin/contact/bulk-read', { ids: unreadIds })

    // Update rows reactively
    unreadIds.forEach(id => {
      const row = contacts.value.find(c => c.id === id)
      if (row) row.status = 'read'
    })

    selectedIds.value = []
    fetchStats()
    showToast(`${unreadIds.length} message(s) marked as read`)
  } catch {
    showToast('Failed to mark messages as read', 'error')
  } finally {
    bulkLoading.value = false
  }
}

async function bulkDelete() {
  if (!confirm(`Delete ${selectedIds.value.length} message(s)?`)) return

  bulkLoading.value = true
  try {
    await axios.delete('/api/admin/contact/bulk-destroy', {
      data: { ids: selectedIds.value },
    })

    const removed = new Set(selectedIds.value)
    contacts.value    = contacts.value.filter(c => !removed.has(c.id))
    selectedIds.value = []
    fetchStats()
    showToast(`${removed.size} message(s) deleted`)
  } catch {
    showToast('Failed to delete selected messages', 'error')
  } finally {
    bulkLoading.value = false
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('en-US', {
    year:   'numeric',
    month:  'short',
    day:    'numeric',
    hour:   '2-digit',
    minute: '2-digit',
  })
}

let toastTimer = null
function showToast(message, type = 'success') {
  clearTimeout(toastTimer)
  toast.value = { show: true, message, type }
  toastTimer  = setTimeout(() => (toast.value.show = false), 3000)
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(() => {
  fetchContacts()
  fetchStats()
})
</script>

<style scoped>
/* Modal fade */
.modal-fade-enter-active,
.modal-fade-leave-active { transition: opacity 0.2s ease; }
.modal-fade-enter-from,
.modal-fade-leave-to    { opacity: 0; }

/* Toast slide-up */
.toast-slide-enter-active,
.toast-slide-leave-active { transition: all 0.3s ease; }
.toast-slide-enter-from,
.toast-slide-leave-to     { opacity: 0; transform: translateY(12px); }
</style>