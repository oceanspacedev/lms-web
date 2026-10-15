const initRequestForm = (form) => {
    const type = document.getElementById('document-type');
    const company = document.getElementById('company');
    const phone = document.getElementById('field-requester_phone');
    phone.addEventListener('input', () => {
        phone.value = phone.value.replace(/[^0-9+]/g, '').replace(/(?!^)\+/g, '');
    });
    const update = () => {
        const otherBusiness = form.querySelector('[data-other-business]');
        otherBusiness.hidden = company.value !== 'other';
        otherBusiness.querySelector('input').disabled = otherBusiness.hidden;
        otherBusiness.querySelector('input').required = !otherBusiness.hidden;
        form.querySelectorAll('[data-document-type]').forEach(group => {
            group.hidden = group.dataset.documentType !== type.value;
        });
        form.querySelectorAll('[data-document-type] input, [data-document-type] textarea').forEach(input => {
            input.disabled = Boolean(input.closest('[hidden]'));
            input.required = !input.disabled && input.dataset.required === '1';
        });
    };
    type.addEventListener('change', update);
    company.addEventListener('change', update);
    update();
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        button.textContent = 'Mengirim…';
    });
};

const initLookupForm = (form) => {
    const submissionId = form.querySelector('[name=submission_id]');
    const phone = form.querySelector('[name=requester_phone]');
    submissionId.addEventListener('input', () => {
        submissionId.value = submissionId.value.replace(/[^0-9#]/g, '').replace(/(?!^)#/g, '');
    });
    phone.addEventListener('input', () => {
        phone.value = phone.value.replace(/[^0-9+]/g, '').replace(/(?!^)\+/g, '');
    });
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        button.textContent = 'Memeriksa…';
    });
};

/**
 * Menyalin tautan status ke papan klip. Bila Clipboard API tidak tersedia (misalnya halaman bukan HTTPS),
 * teks dipilih dan perintah salin lama dicoba; bila itu gagal juga, teks tetap terpilih agar bisa disalin dengan Ctrl+C.
 */
const initCopyLink = (box) => {
    const input = box.querySelector('input');
    const button = box.querySelector('[data-copy-button]');
    const feedback = box.querySelector('[data-copy-feedback]');
    let resetTimer;
    const selectText = () => {
        input.focus();
        input.select();
        input.setSelectionRange(0, input.value.length);
    };
    const copy = async () => {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(input.value);
                return true;
            } catch (error) {
                // Lanjut ke cadangan di bawah.
            }
        }
        selectText();
        try {
            return Boolean(document.execCommand && document.execCommand('copy'));
        } catch (error) {
            return false;
        }
    };
    input.addEventListener('focus', () => input.select());
    button.addEventListener('click', async () => {
        const copied = await copy();
        if (!copied) selectText();
        feedback.textContent = copied ? 'Tautan disalin' : 'Tautan sudah dipilih. Tekan Ctrl+C (atau tahan lalu pilih Salin) untuk menyalin.';
        feedback.classList.toggle('copied', copied);
        clearTimeout(resetTimer);
        resetTimer = setTimeout(() => {
            feedback.textContent = '';
            feedback.classList.remove('copied');
        }, 6000);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('request-form');
    if (form) initRequestForm(form);
    const lookupForm = document.getElementById('lookup-form');
    if (lookupForm) initLookupForm(lookupForm);
    document.querySelectorAll('[data-copy-link]').forEach(initCopyLink);
});
