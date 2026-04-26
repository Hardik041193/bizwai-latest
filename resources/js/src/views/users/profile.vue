<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><span class="text-primary">Users</span></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Profile</li>
        </ul>

        <!-- Loading skeleton -->
        <div v-if="profileStore.loading" class="flex justify-center py-20">
            <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10 inline-block"></span>
        </div>

        <template v-else-if="profileStore.profile">
            <div class="grid grid-cols-1 lg:grid-cols-3 xl:grid-cols-4 gap-5">

                <!-- ── Left: avatar card ── -->
                <div class="panel lg:col-span-1">
                    <!-- Avatar -->
                    <div class="flex flex-col items-center text-center mb-6">
                        <div class="relative group mb-4">
                            <img
                                :src="avatarSrc"
                                :alt="profileStore.profile.name"
                                class="w-28 h-28 rounded-full object-cover border-4 border-white dark:border-[#1b2e4b] shadow"
                            />
                            <!-- Upload overlay -->
                            <label
                                class="absolute inset-0 flex flex-col items-center justify-center bg-black/50 rounded-full opacity-0 group-hover:opacity-100 cursor-pointer transition-opacity"
                                title="Change avatar"
                            >
                                <svg class="w-6 h-6 text-white mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span class="text-white text-xs">Change</span>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/jpg,image/png,image/webp"
                                    class="hidden"
                                    @change="onAvatarSelect"
                                />
                            </label>
                            <!-- Upload spinner -->
                            <div v-if="profileStore.uploadingAvatar"
                                class="absolute inset-0 flex items-center justify-center bg-black/60 rounded-full">
                                <span class="animate-spin border-4 border-white border-l-transparent rounded-full w-8 h-8 inline-block"></span>
                            </div>
                        </div>

                        <h2 class="text-xl font-bold dark:text-white">{{ profileStore.profile.name }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ profileStore.profile.job_title || 'No title set' }}</p>

                        <!-- Role badge -->
                        <span :class="profileStore.profile.role === 'admin'
                            ? 'badge badge-outline-success mt-2'
                            : 'badge badge-outline-primary mt-2'">
                            {{ profileStore.profile.role === 'admin' ? 'Administrator' : 'User' }}
                        </span>
                    </div>

                    <hr class="border-white-dark/20 mb-5" />

                    <!-- Quick info -->
                    <ul class="space-y-4 text-sm text-white-dark">
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span class="break-all text-primary font-medium">{{ profileStore.profile.email }}</span>
                        </li>
                        <li v-if="profileStore.profile.phone" class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <span>{{ profileStore.profile.phone }}</span>
                        </li>
                        <li v-if="profileStore.profile.address" class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>{{ profileStore.profile.address }}</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span>Member since {{ formatDate(profileStore.profile.created_at) }}</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0" :class="profileStore.profile.email_verified_at ? 'text-success' : 'text-danger'"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span :class="profileStore.profile.email_verified_at ? 'text-success' : 'text-danger'">
                                {{ profileStore.profile.email_verified_at ? 'Email Verified' : 'Email Not Verified' }}
                            </span>
                        </li>
                    </ul>

                    <!-- Bio -->
                    <template v-if="profileStore.profile.bio">
                        <hr class="border-white-dark/20 my-5" />
                        <p class="text-sm text-white-dark leading-relaxed">{{ profileStore.profile.bio }}</p>
                    </template>
                </div>

                <!-- ── Right: tabs ── -->
                <div class="panel lg:col-span-2 xl:col-span-3">
                    <!-- Tab nav -->
                    <div class="flex border-b border-white-dark/20 mb-6 gap-1">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            @click="activeTab = tab.id"
                            :class="[
                                'px-5 py-2.5 text-sm font-medium rounded-t transition-colors',
                                activeTab === tab.id
                                    ? 'border border-b-white dark:border-b-[#1b2e4b] border-white-dark/20 text-primary bg-white dark:bg-[#1b2e4b] -mb-px'
                                    : 'text-white-dark hover:text-primary'
                            ]"
                        >{{ tab.label }}</button>
                    </div>

                    <!-- Validation errors summary (field-level errors remain inline) -->
                    <div v-if="profileStore.error && Object.keys(profileStore.errors).length"
                        class="alert alert-danger flex items-center gap-3 mb-5 p-3.5 rounded">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Please fix the errors below before saving.</span>
                    </div>

                    <!-- ── Tab: Profile Info ── -->
                    <form v-if="activeTab === 'info'" @submit.prevent="submitProfileUpdate" class="space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Full Name <span class="text-danger">*</span></label>
                                <input
                                    v-model="profileForm.name"
                                    type="text"
                                    class="form-input"
                                    :class="{ 'border-danger': profileStore.errors.name }"
                                    placeholder="Your full name"
                                />
                                <p v-if="profileStore.errors.name" class="text-danger text-xs mt-1">
                                    {{ profileStore.errors.name[0] }}
                                </p>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Email Address <span class="text-danger">*</span></label>
                                <input
                                    v-model="profileForm.email"
                                    type="email"
                                    class="form-input"
                                    :class="{ 'border-danger': profileStore.errors.email }"
                                    placeholder="your@email.com"
                                />
                                <p v-if="profileStore.errors.email" class="text-danger text-xs mt-1">
                                    {{ profileStore.errors.email[0] }}
                                </p>
                                <p v-if="profileForm.email !== profileStore.profile?.email" class="text-warning text-xs mt-1">
                                    Changing your email will require re-verification.
                                </p>
                            </div>

                            <!-- Phone -->
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Phone Number</label>
                                <input
                                    v-model="profileForm.phone"
                                    type="tel"
                                    class="form-input"
                                    :class="{ 'border-danger': profileStore.errors.phone }"
                                    placeholder="+1 (555) 000-0000"
                                />
                                <p v-if="profileStore.errors.phone" class="text-danger text-xs mt-1">
                                    {{ profileStore.errors.phone[0] }}
                                </p>
                            </div>

                            <!-- Job Title -->
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Job Title</label>
                                <input
                                    v-model="profileForm.job_title"
                                    type="text"
                                    class="form-input"
                                    :class="{ 'border-danger': profileStore.errors.job_title }"
                                    placeholder="e.g. Software Engineer"
                                />
                                <p v-if="profileStore.errors.job_title" class="text-danger text-xs mt-1">
                                    {{ profileStore.errors.job_title[0] }}
                                </p>
                            </div>

                            <!-- Address -->
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1.5">Address</label>
                                <input
                                    v-model="profileForm.address"
                                    type="text"
                                    class="form-input"
                                    :class="{ 'border-danger': profileStore.errors.address }"
                                    placeholder="City, Country"
                                />
                                <p v-if="profileStore.errors.address" class="text-danger text-xs mt-1">
                                    {{ profileStore.errors.address[0] }}
                                </p>
                            </div>

                            <!-- Bio -->
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1.5">Bio</label>
                                <textarea
                                    v-model="profileForm.bio"
                                    rows="4"
                                    class="form-textarea"
                                    :class="{ 'border-danger': profileStore.errors.bio }"
                                    placeholder="A short bio about yourself..."
                                ></textarea>
                                <div class="flex justify-between mt-1">
                                    <p v-if="profileStore.errors.bio" class="text-danger text-xs">
                                        {{ profileStore.errors.bio[0] }}
                                    </p>
                                    <p class="text-xs text-gray-400 ml-auto">{{ (profileForm.bio || '').length }}/1000</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button
                                type="submit"
                                :disabled="profileStore.saving"
                                class="btn btn-primary gap-2"
                            >
                                <span v-if="profileStore.saving"
                                    class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block">
                                </span>
                                {{ profileStore.saving ? 'Saving…' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>

                    <!-- ── Tab: Change Password ── -->
                    <form v-else-if="activeTab === 'password'" @submit.prevent="submitPasswordUpdate" class="space-y-5 max-w-md">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Current Password <span class="text-danger">*</span></label>
                            <div class="relative">
                                <input
                                    v-model="passwordForm.current_password"
                                    :type="showPasswords.current ? 'text' : 'password'"
                                    class="form-input pr-10"
                                    :class="{ 'border-danger': profileStore.errors.current_password }"
                                    placeholder="Enter current password"
                                    autocomplete="current-password"
                                />
                                <button type="button" @click="showPasswords.current = !showPasswords.current"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <svg v-if="showPasswords.current" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="profileStore.errors.current_password" class="text-danger text-xs mt-1">
                                {{ profileStore.errors.current_password[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1.5">New Password <span class="text-danger">*</span></label>
                            <div class="relative">
                                <input
                                    v-model="passwordForm.password"
                                    :type="showPasswords.new ? 'text' : 'password'"
                                    class="form-input pr-10"
                                    :class="{ 'border-danger': profileStore.errors.password }"
                                    placeholder="Minimum 8 characters"
                                    autocomplete="new-password"
                                />
                                <button type="button" @click="showPasswords.new = !showPasswords.new"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <svg v-if="showPasswords.new" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <!-- Password strength bar -->
                            <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="passwordStrengthColor"
                                    :style="{ width: passwordStrengthWidth }"
                                ></div>
                            </div>
                            <p class="text-xs mt-1" :class="passwordStrengthTextColor">{{ passwordStrengthLabel }}</p>
                            <p v-if="profileStore.errors.password" class="text-danger text-xs mt-1">
                                {{ profileStore.errors.password[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1.5">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="relative">
                                <input
                                    v-model="passwordForm.password_confirmation"
                                    :type="showPasswords.confirm ? 'text' : 'password'"
                                    class="form-input pr-10"
                                    :class="{ 'border-danger': passwordMismatch }"
                                    placeholder="Repeat new password"
                                    autocomplete="new-password"
                                />
                                <button type="button" @click="showPasswords.confirm = !showPasswords.confirm"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <svg v-if="showPasswords.confirm" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="passwordMismatch" class="text-danger text-xs mt-1">Passwords do not match.</p>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button
                                type="submit"
                                :disabled="profileStore.savingPassword || passwordMismatch"
                                class="btn btn-primary gap-2"
                            >
                                <span v-if="profileStore.savingPassword"
                                    class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block">
                                </span>
                                {{ profileStore.savingPassword ? 'Updating…' : 'Update Password' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useProfileStore } from '@/stores/profile';
import { useToast }        from '@/composables/use-toast';

const profileStore = useProfileStore();
const { showToast } = useToast();

const activeTab = ref<'info' | 'password'>('info');

const tabs = [
    { id: 'info',     label: 'Profile Information' },
    { id: 'password', label: 'Change Password' },
] as const;

// ── Profile form ──────────────────────────────────────────────────────────────
const profileForm = reactive({
    name:      '',
    email:     '',
    phone:     '',
    job_title: '',
    address:   '',
    bio:       '',
});

function syncFormFromStore() {
    const p = profileStore.profile;
    if (!p) return;
    profileForm.name      = p.name       ?? '';
    profileForm.email     = p.email      ?? '';
    profileForm.phone     = p.phone      ?? '';
    profileForm.job_title = p.job_title  ?? '';
    profileForm.address   = p.address    ?? '';
    profileForm.bio       = p.bio        ?? '';
}

watch(() => profileStore.profile, syncFormFromStore, { immediate: true });

async function submitProfileUpdate() {
    profileStore.clearMessages();
    const ok = await profileStore.updateProfile({ ...profileForm });
    if (ok) {
        showToast(profileStore.successMessage ?? 'Profile updated successfully.', 'success');
    } else if (profileStore.error && !Object.keys(profileStore.errors).length) {
        showToast(profileStore.error, 'error');
    }
}

// ── Password form ─────────────────────────────────────────────────────────────
const passwordForm = reactive({
    current_password:      '',
    password:              '',
    password_confirmation: '',
});

const showPasswords = reactive({ current: false, new: false, confirm: false });

const passwordMismatch = computed(() =>
    passwordForm.password_confirmation.length > 0 &&
    passwordForm.password !== passwordForm.password_confirmation
);

function passwordScore(pwd: string): number {
    let score = 0;
    if (pwd.length >= 8)  score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    return score;
}

const passwordStrengthWidth = computed(() => {
    const s = passwordScore(passwordForm.password);
    return `${Math.min(100, s * 20)}%`;
});

const passwordStrengthColor = computed(() => {
    const s = passwordScore(passwordForm.password);
    if (!passwordForm.password) return 'bg-gray-300';
    if (s <= 1) return 'bg-danger';
    if (s <= 3) return 'bg-warning';
    return 'bg-success';
});

const passwordStrengthTextColor = computed(() => {
    const s = passwordScore(passwordForm.password);
    if (!passwordForm.password) return 'text-gray-400';
    if (s <= 1) return 'text-danger';
    if (s <= 3) return 'text-warning';
    return 'text-success';
});

const passwordStrengthLabel = computed(() => {
    const s = passwordScore(passwordForm.password);
    if (!passwordForm.password) return '';
    if (s <= 1) return 'Weak';
    if (s <= 3) return 'Fair';
    if (s === 4) return 'Strong';
    return 'Very Strong';
});

async function submitPasswordUpdate() {
    if (passwordMismatch.value) return;
    profileStore.clearMessages();
    const ok = await profileStore.updatePassword({ ...passwordForm });
    if (ok) {
        passwordForm.current_password      = '';
        passwordForm.password              = '';
        passwordForm.password_confirmation = '';
        showToast('Password updated successfully.', 'success');
    } else if (profileStore.error && !Object.keys(profileStore.errors).length) {
        showToast(profileStore.error, 'error');
    }
}

// ── Avatar ────────────────────────────────────────────────────────────────────
const FALLBACK_AVATAR = '/assets/images/profile-34.jpeg';

const avatarSrc = computed(() =>
    profileStore.profile?.avatar_url || FALLBACK_AVATAR
);

async function onAvatarSelect(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    profileStore.clearMessages();
    const ok = await profileStore.uploadAvatar(file);
    if (ok) {
        showToast('Avatar updated successfully.', 'success');
    } else {
        showToast(profileStore.error ?? 'Failed to upload avatar.', 'error');
    }
    (event.target as HTMLInputElement).value = '';
}

// ── Clear messages on tab change ──────────────────────────────────────────────
watch(activeTab, () => profileStore.clearMessages());

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatDate(val: string | null | undefined): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

// ── Init ──────────────────────────────────────────────────────────────────────
onMounted(() => profileStore.fetchProfile());
</script>
