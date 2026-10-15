for (const slot of document.querySelectorAll('[data-photo-slot]')) {
    const input = slot.querySelector('[data-photo]');
    const preview = slot.querySelector('[data-photo-preview]');
    const remove = slot.querySelector('[data-remove-photo]');
    let url;
    function update() {
        if (url) URL.revokeObjectURL(url);
        const file = input.files[0];
        preview.hidden = remove.hidden = !file;
        if (file) preview.src = url = URL.createObjectURL(file);
        else preview.removeAttribute('src');
    }
    input.addEventListener('change', update);
    remove.addEventListener('click', () => { input.value = ''; update(); });
}

for (const form of document.querySelectorAll('[data-generation-form]')) {
    form.addEventListener('submit', () => {
        for (const button of form.querySelectorAll('button')) button.disabled = true;
    });
}

const status = document.querySelector('[data-generation-status]');
const active = ['queued', 'uploading', 'submitting', 'generating', 'validating'];
async function poll() {
    if (!status || !active.includes(status.dataset.status)) return;
    try {
        const response = await fetch(status.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) throw new Error('Status unavailable');
        const data = await response.json();
        if (!active.includes(data.status)) { window.location.reload(); return; }
        status.dataset.status = data.status;
        status.querySelector('[data-status-label]').textContent = data.status === 'submitting' ? 'Submitting paid task' : data.status[0].toUpperCase() + data.status.slice(1);
        status.querySelector('[data-progress]').textContent = data.provider_progress === null ? '' : ` (${data.provider_progress}%)`;
        status.querySelector('[data-error]').textContent = data.error ?? '';
    } catch {
        status.querySelector('[data-error]').textContent = 'Status could not be refreshed. Retrying; your generation remains saved.';
    }
    setTimeout(poll, 5000);
}
setTimeout(poll, 3000);
