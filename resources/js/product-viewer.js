import '@google/model-viewer';

const viewer = document.getElementById('product-viewer');
const picker = document.getElementById('variant-picker');
const tryOnLinks = document.querySelectorAll('a[href*="/try-on/"]');

function selectedVariant(variants) {
    // Exporters often lower-case variant names, so match loosely.
    const wanted = String(viewer.variantName ?? '').toLowerCase();

    return variants.find((variant) => variant.toLowerCase() === wanted) ?? variants[0];
}

function syncTryOnLinks(variant) {
    tryOnLinks.forEach((link) => {
        const url = new URL(link.href);
        url.searchParams.set('variant', variant);
        link.href = url.toString();
    });
}

viewer?.addEventListener(
    'load',
    () => {
        const variants = viewer.availableVariants ?? [];
        if (!picker || variants.length < 2) {
            return;
        }

        const label = picker.querySelector('[data-variant-label]');
        const options = picker.querySelector('[data-variant-options]');
        // Apply the resolved name so what is rendered matches what is labelled.
        viewer.variantName = selectedVariant(variants);

        const render = () => {
            const current = selectedVariant(variants);
            label.textContent = current;
            options.querySelectorAll('button').forEach((button) => {
                button.setAttribute('aria-pressed', String(button.dataset.variant === current));
            });
            syncTryOnLinks(current);
        };

        options.replaceChildren(
            ...variants.map((name) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.variant = name;
                button.textContent = name;
                button.className = 'rounded-full border border-stone-300 px-4 py-2 text-sm font-medium capitalize transition hover:border-stone-900 aria-pressed:border-stone-900 aria-pressed:bg-stone-900 aria-pressed:text-white';
                button.addEventListener('click', () => {
                    viewer.variantName = name;
                    render();
                });

                return button;
            }),
        );

        picker.hidden = false;
        render();
    },
    { once: true },
);
