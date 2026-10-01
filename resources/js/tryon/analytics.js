/**
 * Anonymous try-on funnel events. No cookies or identifiers survive the page:
 * the session id is random per visit.
 */

function randomId() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function detectDevice() {
    return window.matchMedia('(pointer: coarse)').matches ? 'mobile' : 'desktop';
}

export function createAnalytics(url) {
    const sessionId = randomId();
    const device = detectDevice();
    const sentOnce = new Set();

    return {
        track(type, productId, { variant = null, once = false } = {}) {
            const key = `${type}:${productId}`;
            if (once && sentOnce.has(key)) {
                return;
            }
            sentOnce.add(key);

            const body = JSON.stringify({ type, product_id: productId, session_id: sessionId, variant, device });
            const beaconSent = navigator.sendBeacon?.(url, new Blob([body], { type: 'application/json' }));
            if (!beaconSent) {
                fetch(url, {
                    method: 'POST',
                    body,
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                }).catch(() => {});
            }
        },
    };
}
