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

const formatFileSize = (bytes) => (bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/**
 * Input file bawaan browser diganti tampilan yang jelas: tombol "Pilih file" yang berubah jadi "Ganti file", dan kartu berisi
 * nama, ukuran, serta status file yang dipilih. Input aslinya tetap ada (hanya disembunyikan secara visual) sehingga validasi
 * `required`, keyboard, dan pembaca layar tetap bekerja. File di atas batas ukuran langsung ditandai sebelum dikirim.
 */
const initFilePicker = (input) => {
    const picker = document.createElement('div');
    picker.className = 'file-picker';
    input.parentNode.insertBefore(picker, input);
    picker.appendChild(input);
    input.classList.add('file-picker-input');

    const button = document.createElement('label');
    button.className = 'file-picker-button';
    button.htmlFor = input.id;
    const empty = document.createElement('span');
    empty.className = 'file-picker-empty';
    empty.textContent = 'Belum ada file dipilih';
    const chosen = document.createElement('div');
    chosen.className = 'file-picker-chosen';
    chosen.setAttribute('aria-live', 'polite');
    picker.append(button, empty, chosen);

    const maxKb = Number(input.dataset.maxKb || 0);
    const render = () => {
        const file = input.files && input.files[0];
        chosen.replaceChildren();
        picker.classList.remove('has-file', 'too-big');
        input.setCustomValidity('');
        if (!file) {
            button.textContent = 'Pilih file';
            empty.hidden = false;
            chosen.hidden = true;
            return;
        }
        const tooBig = maxKb > 0 && file.size > maxKb * 1024;
        const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = tooBig
            ? '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 7v6m0 3.5v.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>'
            : '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="m7.5 12.5 3 3 6-6.5" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>';
        const text = document.createElement('div');
        text.className = 'file-picker-text';
        const name = document.createElement('span');
        name.className = 'file-picker-name';
        name.textContent = file.name;
        const meta = document.createElement('span');
        meta.className = 'file-picker-meta';
        meta.textContent = tooBig
            ? `${formatFileSize(file.size)} · melebihi batas ${Math.round(maxKb / 1024)} MB, pilih file lain`
            : `${formatFileSize(file.size)} · siap dikirim`;
        text.append(name, meta);
        chosen.append(icon, text);
        chosen.hidden = false;
        empty.hidden = true;
        button.textContent = 'Ganti file';
        picker.classList.add(tooBig ? 'too-big' : 'has-file');
        if (tooBig) input.setCustomValidity(`Ukuran file melebihi batas ${Math.round(maxKb / 1024)} MB.`);
    };
    input.addEventListener('change', render);
    render();
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[type=file]').forEach(initFilePicker);
    const form = document.getElementById('request-form');
    if (form) initRequestForm(form);
    const lookupForm = document.getElementById('lookup-form');
    if (lookupForm) initLookupForm(lookupForm);
    document.querySelectorAll('[data-copy-link]').forEach(initCopyLink);
});
