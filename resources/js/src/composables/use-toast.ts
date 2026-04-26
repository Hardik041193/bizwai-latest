import Swal from 'sweetalert2';

type ToastType = 'success' | 'error' | 'warning' | 'info';

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    customClass: { container: 'toast' },
    didOpen: (el) => {
        el.addEventListener('mouseenter', Swal.stopTimer);
        el.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

export function useToast() {
    const showToast = (message: string, type: ToastType = 'success') => {
        toast.fire({ icon: type, title: message, padding: '10px 20px' });
    };

    const confirmDialog = (options: {
        title?: string;
        text?: string;
        confirmText?: string;
        cancelText?: string;
        type?: 'warning' | 'error' | 'question';
    }): Promise<boolean> => {
        return Swal.fire({
            title:              options.title ?? 'Are you sure?',
            text:               options.text,
            icon:               options.type ?? 'warning',
            showCancelButton:   true,
            confirmButtonText:  options.confirmText ?? 'Yes',
            cancelButtonText:   options.cancelText  ?? 'Cancel',
            confirmButtonColor: '#e7515a',
            padding:            '2em',
            customClass:        { popup: 'sweet-alerts' },
        }).then((result) => result.isConfirmed);
    };

    return { showToast, confirmDialog };
}
