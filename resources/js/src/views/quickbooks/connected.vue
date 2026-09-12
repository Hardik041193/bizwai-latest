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

            <!-- Syncing state: the full data pull runs here (not in the OAuth
                 callback) so the user sees a clear progress indicator instead of
                 a blank page while invoices/customers/transactions are fetched. -->
            <div v-if="syncing" class="mt-6 flex flex-col items-center gap-3">
                <span class="animate-spin border-4 border-success border-l-transparent rounded-full w-9 h-9"></span>

                <!-- Progress bar, driven by the per-entity sync state. Gives the
                     user something that visibly moves instead of an open-ended
                     spinner while the background sync runs. -->
                <div class="w-full max-w-xs">
                    <div class="h-2 w-full rounded-full bg-white-dark/20 overflow-hidden">
                        <div
                            class="h-full rounded-full bg-success transition-all duration-500 ease-out"
                            :style="{ width: `${Math.max(syncPercent, 5)}%` }"
                        ></div>
                    </div>
                    <p class="mt-2 text-white-dark/60 text-xs">
                        {{ entitiesFinished }} of {{ entitiesTotal }} imported
                    </p>
                </div>

                <p class="text-white-dark/60 text-sm">
                    <template v-if="pendingLabels.length">
                        Importing {{ pendingLabels.join(', ') }}…
                    </template>
                    <template v-else>
                        Syncing all your QuickBooks data… this can take a few moments.
                    </template>
                </p>
            </div>
            <p v-else class="mt-6 text-white-dark/50 text-sm">
                Taking you to your dashboard…
            </p>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useMeta } from '../../composables/use-meta';
import { useToast } from '../../composables/use-toast';
import { useAuthStore } from '../../stores/auth';
import { useQuickBooksStore } from '../../stores/quickbooks';

useMeta({ title: 'QuickBooks Connected' });

const authStore = useAuthStore();
const qbStore = useQuickBooksStore();
const router = useRouter();
const { showToast } = useToast();
const dashboardPath = authStore.isAdmin ? '/quickbooks/dashboard' : '/quickbooks/portal';

const syncing = computed(() => qbStore.syncing);
const syncPercent = computed(() => qbStore.syncPercent);
const pendingLabels = computed(() => qbStore.syncPendingLabels);
const entitiesTotal = computed(() => qbStore.syncProgress?.entities_total ?? 0);
const entitiesFinished = computed(() => qbStore.syncProgress?.entities_finished ?? 0);

onMounted(async () => {
    await qbStore.fetchStatus(true);

    if (!qbStore.isConnected) {
        router.replace({ name: 'quickbooks-connect' });
        return;
    }

    // The OAuth callback only stores tokens + company info and redirects here
    // immediately. Pull the full dataset (all clients) now, in the foreground,
    // so the user gets a visible "syncing" state and a success confirmation.
    try {
        await qbStore.triggerSync();

        // triggerSync resolves on a partial sync too (some entities failed but
        // the run is over), so a clean resolve is not proof of success. Without
        // this check the user gets a success toast over missing data.
        if (qbStore.syncHadFailures || qbStore.error) {
            showToast(
                qbStore.error ?? 'Connected, but some data could not be imported. You can retry with Refresh.',
                'warning',
            );
        } else {
            showToast('QuickBooks connected, your data has been synced.', 'success');
        }
    } catch {
        showToast(
            qbStore.error ?? 'Connected, but the data sync didn\'t finish. You can retry with Refresh.',
            'error',
        );
    }

    router.replace(dashboardPath);
});
</script>
