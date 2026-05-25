// resources/js/src/stores/contact.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

interface ContactForm {
    name:    string;
    email:   string;
    subject: string;
    message: string;
}

export const useContactStore = defineStore('contact', () => {

    const sending     = ref(false);
    const success     = ref('');
    const serverError = ref('');
    const errors      = ref<Record<string, string[]>>({});

    function clearState() {
        success.value     = '';
        serverError.value = '';
        errors.value      = {};
    }

    async function sendMessage(form: ContactForm): Promise<boolean> {
        clearState();
        sending.value = true;

        try {
            const res = await axios.post('/api/contact-us', form);
            success.value = res.data.message ?? 'Message sent successfully!';
            return true;
        } catch (err: any) {
            if (err.response?.status === 422) {
                errors.value = err.response.data.errors ?? {};
            } else {
                serverError.value =
                    err.response?.data?.message ?? 'Something went wrong. Please try again.';
            }
            return false;
        } finally {
            sending.value = false;
        }
    }

    return { sending, success, serverError, errors, sendMessage, clearState };
});