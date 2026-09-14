import type { RouteDefinition } from '@/wayfinder';

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly body: unknown = null,
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Calls the app's session-authenticated JSON API (routes/api.php) with the
 * CSRF token Laravel keeps in the XSRF-TOKEN cookie. The browser only ever
 * talks to Laravel; Laravel talks to Nessus.
 */
export async function apiRequest<T>(
    route: RouteDefinition<Method>,
    body?: Record<string, unknown>,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body) {
        headers['Content-Type'] = 'application/json';
    }

    let response: Response;

    try {
        response = await fetch(route.url, {
            method: route.method.toUpperCase(),
            headers,
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new ApiError('Could not reach the application server.', 0);
    }

    const data: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            response.status === 429
                ? 'Too many attempts. Please wait a minute and try again.'
                : response.status === 419
                  ? 'Your session expired. Reload the page and try again.'
                  : ((data as { message?: string } | null)?.message ??
                    `Request failed (HTTP ${response.status}).`);

        throw new ApiError(message, response.status, data);
    }

    return data as T;
}
