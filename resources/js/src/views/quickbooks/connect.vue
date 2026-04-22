<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><router-link to="/" class="text-primary hover:underline">Dashboard</router-link></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">QuickBooks</li>
        </ul>

        <div class="flex items-center justify-center min-h-[60vh]">
            <div class="panel max-w-lg w-full text-center">
                <!-- QuickBooks Logo / Icon -->
                <div class="flex justify-center mb-6">
                    <div class="w-20 h-20 rounded-full bg-[#2CA01C]/10 flex items-center justify-center">
                        <svg class="w-10 h-10 text-[#2CA01C]" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                        </svg>
                    </div>
                </div>

                <h2 class="text-2xl font-bold text-white-dark mb-2">Connect QuickBooks</h2>
                <p class="text-white-dark/70 mb-8">
                    Link your QuickBooks Online account to sync invoices, expenses, transactions and accounts automatically.
                </p>

                <!-- Benefits list -->
                <ul class="text-left space-y-3 mb-8">
                    <li v-for="benefit in benefits" :key="benefit" class="flex items-center gap-3 text-sm text-white-dark">
                        <span class="w-5 h-5 rounded-full bg-success/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                        </span>
                        {{ benefit }}
                    </li>
                </ul>

                <button
                    @click="connectQBO"
                    :disabled="loading"
                    class="btn btn-success w-full text-base py-3 gap-2"
                >
                    <svg v-if="loading" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    <span>{{ loading ? 'Redirecting to QuickBooks…' : 'Connect QuickBooks Account' }}</span>
                </button>

                <p v-if="error" class="mt-4 text-danger text-sm">{{ error }}</p>

                <p class="mt-6 text-xs text-white-dark/50">
                    You will be redirected to Intuit to authorize access. BizWai never stores your QuickBooks password.
                </p>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ref } from 'vue';
import { useMeta } from '@/composables/use-meta';
import { useQuickBooksStore } from '@/stores/quickbooks';

useMeta({ title: 'Connect QuickBooks' });

const qbStore = useQuickBooksStore();
const loading = ref(false);
const error = ref('');

const benefits = [
    'Auto-sync invoices, expenses and accounts daily',
    'Real-time financial summary on your dashboard',
    'Profit & Loss data for AI-powered insights',
    'Secure OAuth 2.0 — no password sharing',
];

async function connectQBO() {
    loading.value = true;
    error.value = '';
    try {
        const url = await qbStore.getConnectUrl();
        window.location.href = url;
    } catch (err: any) {
        error.value = err.response?.data?.message ?? 'Failed to initiate QuickBooks connection.';
        loading.value = false;
    }
}
</script>
