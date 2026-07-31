import { http, HttpResponse } from 'msw';

export const handlers = [
  http.get('https://example.test/api/health', () => {
    return HttpResponse.json({ status: 'ok' });
  }),
];
