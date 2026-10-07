document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('request-form');
    if (!form) return;
    const type = document.getElementById('document-type');
    const company = document.getElementById('company');
    const update = () => {
        const otherBusiness = form.querySelector('[data-other-business]');
        otherBusiness.hidden = company.value !== 'other';
        otherBusiness.querySelector('input').disabled = otherBusiness.hidden;
        otherBusiness.querySelector('input').required = !otherBusiness.hidden;
        form.querySelectorAll('[data-document-type]').forEach(group => {
            group.hidden = group.dataset.documentType !== type.value;
        });
        form.querySelectorAll('[data-condition]').forEach(group => {
            group.hidden = ['cost', 'renewal'].includes(group.dataset.condition);
        });
        form.querySelectorAll('[data-document-type] input, [data-document-type] textarea, [data-condition] input, [data-condition] textarea').forEach(input => {
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
});
