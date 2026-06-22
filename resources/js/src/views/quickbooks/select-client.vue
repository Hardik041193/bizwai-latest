<template>
    <div class="flex items-center justify-center min-h-[80vh] px-4 py-8 bg-[#f4f6f8] dark:bg-[#060818]">
        <div class="w-full max-w-xl bg-white dark:bg-[#0e1726] rounded-lg shadow-lg border border-gray-200 dark:border-gray-700">
            <div class="px-8 pt-8 pb-5">
                <h1 class="text-2xl font-normal text-gray-800 dark:text-white mb-6">Please select your company.</h1>

                <!-- Firm (read-only, like Intuit) -->
                <div class="mb-5">
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Search for a company or firm</label>
                    <div class="rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2.5 text-sm text-gray-900 dark:text-white flex items-center justify-between">
                        <span>{{ companyName || 'QuickBooks Company' }}</span>
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </div>

                <!-- Client search + list -->
                <div class="mb-2">
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Search for a client</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-[#2ca01c]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </span>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Find a client"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 py-2.5 pl-10 pr-4 text-sm focus:border-[#2ca01c] focus:ring-1 focus:ring-[#2ca01c] outline-none"
                            @input="debouncedLoad"
                        />
                        <span v-if="search" class="absolute inset-y-0 right-0 flex items-center pr-3">
                            <button type="button" class="text-gray-400 hover:text-gray-600" @click="clearSearch">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-between mb-2 mt-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">All clients</p>
                    <span v-if="selectAll" class="text-xs font-medium text-[#2ca01c]">All clients selected</span>
                    <span v-else-if="selectedClients.length" class="text-xs font-medium text-[#2ca01c]">
                        {{ selectedClients.length }} selected
                    </span>
                </div>

                <div v-if="loading" class="flex justify-center py-12 border border-gray-200 dark:border-gray-700 rounded">
                    <span class="animate-spin border-4 border-[#2ca01c] border-l-transparent rounded-full w-8 h-8"></span>
                </div>

                <div v-else-if="error" class="rounded border border-danger/30 bg-danger/10 text-danger text-sm px-4 py-3">
                    {{ error }}
                </div>

                <div
                    v-else-if="!clients.length"
                    class="text-center py-10 text-sm text-gray-500 border border-gray-200 dark:border-gray-700 rounded"
                >
                    No clients found. Try a different search term.
                </div>

                <div v-else class="border border-gray-200 dark:border-gray-700 rounded bg-white dark:bg-gray-900">
                    <!-- Select all clients -->
                    <label class="flex items-center gap-3 px-4 py-2.5 border-b border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800">
                        <input
                            type="checkbox"
                            class="form-checkbox text-[#2ca01c] rounded"
                            :checked="selectAll"
                            @change="toggleSelectAll"
                        />
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Select all clients</span>
                    </label>

                    <ul class="max-h-56 overflow-y-auto">
                        <li v-for="client in clients" :key="client.qbo_id">
                            <label
                                class="flex items-center gap-3 px-4 py-2.5 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0 transition"
                                :class="[
                                    isSelected(client.qbo_id) ? 'bg-[#2ca01c]/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800',
                                    selectAll ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
                                ]"
                            >
                                <input
                                    type="checkbox"
                                    class="form-checkbox text-[#2ca01c] rounded"
                                    :checked="isSelected(client.qbo_id)"
                                    :disabled="selectAll"
                                    @change="toggleClient(client)"
                                />
                                <span :class="isSelected(client.qbo_id) ? 'text-[#2ca01c] font-medium' : 'text-gray-800 dark:text-gray-200'">
                                    {{ client.display_name }}
                                </span>
                            </label>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="px-8 py-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                <button
                    type="button"
                    class="rounded-full bg-[#2ca01c] hover:bg-[#248f18] text-white text-sm font-semibold px-8 py-2.5 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    :disabled="!canConfirm || saving"
                    @click="confirmSelection"
                >
                    <span v-if="saving" class="inline-flex items-center gap-2">
                        <span class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4"></span>
                        Saving…
                    </span>
                    <span v-else>Next</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useAuthStore } from '@/stores/auth';
import { useQuickBooksStore } from '@/stores/quickbooks';

useMeta({ title: 'Select QuickBooks Client' });

interface SelectionClient {
    qbo_id: string;
    display_name: string;
    company_name: string | null;
}

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const qbStore = useQuickBooksStore();
const { showToast } = useToast();

const isChangeMode = computed(() => route.query.change === '1');

const search = ref('');
const clients = ref<SelectionClient[]>([]);
const companyName = ref<string | null>(null);
const selectAll = ref(false);
const selectedClients = ref<SelectionClient[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);

const canConfirm = computed(() => selectAll.value || selectedClients.value.length > 0);

let searchTimer: ReturnType<typeof setTimeout>;

const dashboardPath = computed(() =>
    authStore.isAdmin ? '/quickbooks/dashboard' : '/quickbooks/portal'
);

async function loadClients() {
    loading.value = true;
    error.value = null;
    try {
        const data = await qbStore.fetchSelectionClients(search.value.trim() || undefined);
        companyName.value = data.company_name ?? null;
        clients.value = data.clients ?? [];
    } catch (err: any) {
        error.value = err.response?.data?.message ?? 'Failed to load clients from QuickBooks.';
        clients.value = [];
    } finally {
        loading.value = false;
    }
}

function debouncedLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadClients, 350);
}

function clearSearch() {
    search.value = '';
    loadClients();
}

function isSelected(qboId: string): boolean {
    return selectAll.value || selectedClients.value.some((c) => c.qbo_id === qboId);
}

function toggleClient(client: SelectionClient) {
    if (selectAll.value) {
        return;
    }
    if (selectedClients.value.some((c) => c.qbo_id === client.qbo_id)) {
        selectedClients.value = selectedClients.value.filter((c) => c.qbo_id !== client.qbo_id);
    } else {
        selectedClients.value = [...selectedClients.value, client];
    }
}

function toggleSelectAll() {
    selectAll.value = !selectAll.value;
    if (selectAll.value) {
        selectedClients.value = [];
    }
}

async function confirmSelection() {
    if (!canConfirm.value) {
        return;
    }

    saving.value = true;
    try {
        if (isChangeMode.value) {
            await qbStore.clearClientSelection();
        }
        await qbStore.saveClientSelection({
            selectAll: selectAll.value,
            clients: selectAll.value
                ? []
                : selectedClients.value.map((c) => ({
                      qbo_id: c.qbo_id,
                      display_name: c.display_name,
                  })),
        });
        await qbStore.fetchStatus(true);

        const label = selectAll.value
            ? 'all clients'
            : selectedClients.value.length === 1
                ? selectedClients.value[0].display_name
                : `${selectedClients.value.length} clients`;
        showToast(`Working with ${label}`, 'success');
        router.push(dashboardPath.value);
    } catch (err: any) {
        showToast(err.response?.data?.message ?? 'Failed to save client selection.', 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await qbStore.fetchStatus(true);

    if (!qbStore.isConnected) {
        router.replace({ name: 'quickbooks-connect' });
        return;
    }

    if (!isChangeMode.value && !qbStore.needsClientSelection) {
        router.replace(dashboardPath.value);
        return;
    }

    if (isChangeMode.value) {
        if (qbStore.status?.all_clients) {
            selectAll.value = true;
        } else if (qbStore.status?.selected_clients?.length) {
            selectedClients.value = qbStore.status.selected_clients.map((c) => ({
                qbo_id: c.qbo_id,
                display_name: c.name ?? '',
                company_name: null,
            }));
        }
    }

    await loadClients();
});
</script>
