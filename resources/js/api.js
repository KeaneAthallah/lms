import axios from 'axios';

const api = axios.create({
    baseURL: '/',
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        if (status === 419 || status === 401) {
            const current = window.location.pathname;
            if (!current.startsWith('/login') && !current.startsWith('/register')) {
                const params = current === '/' ? '' : `?next=${encodeURIComponent(current + window.location.search)}`;
                window.location.assign(`/login${params}`);
            }
        }

        return Promise.reject(error);
    },
);

export function apiError(error, fallback = 'Something went wrong. Please try again.') {
    const data = error.response?.data;
    if (typeof data?.message === 'string') {
        return data.message;
    }
    if (data?.errors) {
        return Object.values(data.errors).flat()[0] || fallback;
    }
    return fallback;
}

export default api;