<template>
    <div class="flex items-center justify-center min-h-[70vh]">
        <div class="panel max-w-md w-full text-center">
            <!-- Success animation -->
            <div class="flex justify-center mb-6">
                <div class="w-20 h-20 rounded-full bg-success/20 flex items-center justify-center">
                    <svg class="w-10 h-10 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>

            <h2 class="text-2xl font-bold mb-2">QuickBooks Connected!</h2>
            <p class="text-white-dark/70 mb-2">
                Your QuickBooks account has been linked successfully.
            </p>
            <p class="text-white-dark/50 text-sm mb-8">
                Next, choose which QuickBooks client you want to work with in Bizwai.
            </p>

            <div class="flex gap-3 justify-center">
                <router-link to="/quickbooks/select-client" class="btn btn-success gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    Select Client →
                </router-link>
                <router-link :to="dashboardPath" class="btn btn-outline-primary">
                    Skip for now
                </router-link>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useMeta } from '../../composables/use-meta';
import { useAuthStore } from '../../stores/auth';
import { useQuickBooksStore } from '../../stores/quickbooks';

useMeta({ title: 'QuickBooks Connected' });

const authStore = useAuthStore();
const qbStore = useQuickBooksStore();
const router = useRouter();
const dashboardPath = authStore.isAdmin ? '/quickbooks/dashboard' : '/quickbooks/portal';

onMounted(async () => {
    await qbStore.fetchStatus(true);

    if (qbStore.needsClientSelection) {
        router.replace({ name: 'quickbooks-select-client' });
        return;
    }

    router.replace(dashboardPath);
});
</script>
