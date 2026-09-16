import http from 'k6/http';
import { check } from 'k6';

// Example: k6 run -e BASE_URL=https://site.example tests/load/results.js
export const options = {
  scenarios: { readers: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '1m', target: 50 }, { duration: '3m', target: 200 }, { duration: '1m', target: 0 }] } },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<400'] },
};

export default function () {
  const url = `${__ENV.BASE_URL}/wp-json/apuracao/v1/results/eleicoes-2026/1/0001/BR`;
  const response = http.get(url);
  check(response, { '200 or CDN 304': (r) => r.status === 200 || r.status === 304, 'cache contract': (r) => r.headers['Cache-Control']?.includes('stale-while-revalidate') });
}
