<template>
    <div>
        <div class="absolute inset-0">
            <img src="/assets/images/auth/bg-gradient.png" alt="image" class="h-full w-full object-cover" />
        </div>

        <div
            class="relative flex min-h-screen items-center justify-center bg-[url(/assets/images/auth/map.png)] bg-cover bg-center bg-no-repeat px-6 py-10 dark:bg-[#060818] sm:px-16"
        >
            <img src="/assets/images/auth/coming-soon-object1.png" alt="image" class="absolute left-0 top-1/2 h-full max-h-[893px] -translate-y-1/2" />
            <img src="/assets/images/auth/coming-soon-object2.png" alt="image" class="absolute left-24 top-0 h-40 md:left-[30%]" />
            <img src="/assets/images/auth/coming-soon-object3.png" alt="image" class="absolute right-0 top-0 h-[300px]" />
            <img src="/assets/images/auth/polygon-object.svg" alt="image" class="absolute bottom-0 end-[28%]" />

            <div
                class="relative w-full max-w-[870px] rounded-md bg-[linear-gradient(45deg,#fff9f9_0%,rgba(255,255,255,0)_25%,rgba(255,255,255,0)_75%,_#fff9f9_100%)] p-2 dark:bg-[linear-gradient(52.22deg,#0E1726_0%,rgba(14,23,38,0)_18.66%,rgba(14,23,38,0)_51.04%,rgba(14,23,38,0)_80.07%,#0E1726_100%)]"
            >
                <div class="relative flex flex-col justify-center rounded-md bg-white/60 px-6 py-20 backdrop-blur-lg dark:bg-black/50 lg:min-h-[758px]">
                    <div class="absolute top-6 end-6">
                        <div class="dropdown">
                            <Popper :placement="store.rtlClass === 'rtl' ? 'bottom-start' : 'bottom-end'" offsetDistance="8">
                                <button
                                    type="button"
                                    class="flex items-center gap-2.5 rounded-lg border border-white-dark/30 bg-white px-2 py-1.5 text-white-dark hover:border-primary hover:text-primary dark:bg-black"
                                >
                                    <img :src="currentFlag" alt="image" class="h-5 w-5 rounded-full object-cover" />
                                    <div class="text-base font-bold uppercase">{{ store.locale }}</div>
                                </button>
                                <template #content="{ close }">
                                    <ul class="!px-2 text-dark dark:text-white-dark grid grid-cols-2 gap-2 font-semibold dark:text-white-light/90 w-[280px]">
                                        <template v-for="item in store.languageList" :key="item.code">
                                            <li>
                                                <button
                                                    type="button"
                                                    class="w-full hover:text-primary"
                                                    :class="{ 'bg-primary/10 text-primary': i18n.locale === item.code }"
                                                    @click="changeLanguage(item), close()"
                                                >
                                                    <img class="w-5 h-5 object-cover rounded-full" :src="`/assets/images/flags/${item.code.toUpperCase()}.svg`" alt="" />
                                                    <span class="ltr:ml-3 rtl:mr-3">{{ item.name }}</span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </template>
                            </Popper>
                        </div>
                    </div>

                    <div class="mx-auto w-full max-w-[440px]">
                        <div class="mb-7">
                            <h1 class="mb-3 text-2xl font-bold !leading-snug dark:text-white">Create New Password</h1>
                            <p class="text-white-dark">Enter your new password below to regain access to your account.</p>
                        </div>

                        <div v-if="successMessage" class="mb-5 rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">
                            {{ successMessage }}
                        </div>
                        <div v-if="authStore.error" class="mb-5 rounded-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger">
                            {{ authStore.error }}
                        </div>

                        <form class="space-y-5" @submit.prevent="submitResetPassword">
                            <div>
                                <label for="Email" class="dark:text-white">Email</label>
                                <div class="relative text-white-dark">
                                    <input
                                        id="Email"
                                        v-model="form.email"
                                        type="email"
                                        placeholder="Enter Email"
                                        class="form-input ps-10 placeholder:text-white-dark"
                                        required
                                    />
                                    <span class="absolute start-4 top-1/2 -translate-y-1/2">
                                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                                            <path opacity="0.5" d="M10.65 2.25H7.35C4.23873 2.25 2.6831 2.25 1.71655 3.23851C0.75 4.22703 0.75 5.81802 0.75 9C0.75 12.182 0.75 13.773 1.71655 14.7615C2.6831 15.75 4.23873 15.75 7.35 15.75H10.65C13.7613 15.75 15.3169 15.75 16.2835 14.7615C17.25 13.773 17.25 12.182 17.25 9C17.25 5.81802 17.25 4.22703 16.2835 3.23851C15.3169 2.25 13.7613 2.25 10.65 2.25Z" fill="currentColor" />
                                            <path d="M14.3465 6.02574C14.609 5.80698 14.6445 5.41681 14.4257 5.15429C14.207 4.89177 13.8168 4.8563 13.5543 5.07507L11.7732 6.55931C11.0035 7.20072 10.4691 7.6446 10.018 7.93476C9.58125 8.21564 9.28509 8.30993 9.00041 8.30993C8.71572 8.30993 8.41956 8.21564 7.98284 7.93476C7.53168 7.6446 6.9973 7.20072 6.22761 6.55931L4.44652 5.07507C4.184 4.8563 3.79384 4.89177 3.57507 5.15429C3.3563 5.41681 3.39177 5.80698 3.65429 6.02574L5.4664 7.53583C6.19764 8.14522 6.79033 8.63914 7.31343 8.97558C7.85834 9.32604 8.38902 9.54743 9.00041 9.54743C9.6118 9.54743 10.1425 9.32604 10.6874 8.97558C11.2105 8.63914 11.8032 8.14522 12.5344 7.53582L14.3465 6.02574Z" fill="currentColor" />
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <div>
                                <label for="Password" class="dark:text-white">New Password</label>
                                <input
                                    id="Password"
                                    v-model="form.password"
                                    type="password"
                                    placeholder="Minimum 8 characters"
                                    class="form-input"
                                    autocomplete="new-password"
                                    required
                                />
                            </div>

                            <div>
                                <label for="ConfirmPassword" class="dark:text-white">Confirm Password</label>
                                <input
                                    id="ConfirmPassword"
                                    v-model="form.password_confirmation"
                                    type="password"
                                    placeholder="Confirm new password"
                                    class="form-input"
                                    autocomplete="new-password"
                                    required
                                />
                                <p v-if="passwordMismatch" class="mt-1 text-xs text-danger">Passwords do not match.</p>
                            </div>

                            <button
                                type="submit"
                                :disabled="authStore.loading || passwordMismatch"
                                class="btn btn-gradient !mt-6 w-full border-0 uppercase shadow-[0_10px_20px_-10px_rgba(67,97,238,0.44)]"
                            >
                                {{ authStore.loading ? 'Resetting...' : 'Reset Password' }}
                            </button>
                        </form>

                        <div class="mt-6 text-center dark:text-white">
                            <router-link to="/auth/boxed-signin" class="uppercase text-primary underline transition hover:text-black dark:hover:text-white">
                                Back to Sign In
                            </router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import appSetting from '@/app-setting';
import { useAppStore } from '@/stores/index';
import { useAuthStore } from '@/stores/auth';
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Reset Password' });

const route = useRoute();
const router = useRouter();
const store = useAppStore();
const authStore = useAuthStore();
const successMessage = ref('');

const form = reactive({
    token: String(route.params.token || ''),
    email: String(route.query.email || ''),
    password: '',
    password_confirmation: '',
});

const passwordMismatch = computed(
    () => form.password_confirmation.length > 0 && form.password !== form.password_confirmation
);

const submitResetPassword = async () => {
    successMessage.value = '';

    if (passwordMismatch.value) {
        authStore.error = 'Passwords do not match.';
        return;
    }

    try {
        successMessage.value = await authStore.resetPassword(form);
        setTimeout(() => router.push({ name: 'boxed-signin', query: { reset: '1' } }), 1200);
    } catch (_) {}
};

const i18n = reactive(useI18n());
const changeLanguage = (item: any) => {
    i18n.locale = item.code;
    appSetting.toggleLanguage(item);
};
const currentFlag = computed(() => `/assets/images/flags/${i18n.locale.toUpperCase()}.svg`);
</script>
