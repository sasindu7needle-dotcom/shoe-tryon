import '@google/model-viewer';

const panel = document.querySelector('[data-poster-capture]');

if (panel) {
    const viewer = panel.querySelector('model-viewer');
    const button = panel.querySelector('button');
    const status = panel.querySelector('[data-poster-status]');
    const preview = panel.querySelector('[data-poster-preview]');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Capturing…';

        try {
            const blob = await viewer.toBlob({ mimeType: 'image/webp', qualityArgument: 0.9, idealAspect: false });
            const body = new FormData();
            body.append('poster', blob, 'poster.webp');

            const response = await fetch(panel.dataset.url, {
                method: 'POST',
                body,
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const { posterUrl } = await response.json();
            preview.src = posterUrl;
            preview.hidden = false;
            status.textContent = 'Poster saved.';
        } catch (error) {
            console.error(error);
            status.textContent = 'The poster could not be saved.';
        } finally {
            button.disabled = false;
        }
    });
}
