import { emit } from '../components/toast.js';

/**
 * Uygulamanın tek fetch sarmalayıcısı.
 *
 * CSRF başlığını ekler, JSON bekler, hata durumlarını tek yerde ele alır.
 * Doğrulama hataları (422) çağırana alan bazında iletilir; diğer hatalar
 * kullanıcıya bildirim olarak gösterilir.
 */

class HttpError extends Error {
    constructor(message, status, payload) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.payload = payload;
        this.errors = payload?.errors ?? {};
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function request(method, url, body = null, options = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : null,
        signal: options.signal,
    });

    const payload = response.status === 204 ? null : await response.json().catch(() => null);

    if (response.ok) {
        return payload;
    }

    const error = new HttpError(payload?.message ?? 'İstek tamamlanamadı.', response.status, payload);

    // Doğrulama hatalarını çağıran ele alır; gerisi kullanıcıya bildirilir.
    if (response.status !== 422 && !options.silent) {
        emit(error.message, 'danger');
    }

    throw error;
}

export const http = {
    get: (url, options) => request('GET', url, null, options),
    post: (url, body, options) => request('POST', url, body, options),
    put: (url, body, options) => request('PUT', url, body, options),
    delete: (url, options) => request('DELETE', url, null, options),
};

export { HttpError };
