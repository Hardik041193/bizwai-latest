<template>
    <div class="flex items-center justify-center min-h-[70vh]">
        <div class="panel max-w-md w-full text-center">
            <div class="flex justify-center mb-6">
                <div class="w-20 h-20 rounded-full bg-danger/20 flex items-center justify-center">
                    <svg class="w-10 h-10 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
            </div>

            <h2 class="text-2xl font-bold mb-2">Connection Failed</h2>
            <p class="text-white-dark/70 mb-2">Something went wrong while connecting your QuickBooks account.</p>
            <p v-if="errorMessage" class="text-danger text-sm bg-danger/10 rounded-lg px-4 py-2 mb-6 break-words">
                {{ errorMessage }}
            </p>
            <p v-else class="text-white-dark/50 text-sm mb-6">
                The authorization was cancelled or an unexpected error occurred.
            </p>

            <div class="flex gap-3 justify-center">
                <router-link to="/quickbooks/connect" class="btn btn-primary gap-2">
                    Try Again
                </router-link>
                <router-link to="/" class="btn btn-outline-secondary">
                    Back to Home
                </router-link>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'QuickBooks Error' });

const route = useRoute();
const errorMessage = computed(() => {
    const msg = route.query.message as string | undefined;
    return msg ? decodeURIComponent(msg) : '';
});
</script>
