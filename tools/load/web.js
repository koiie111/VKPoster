import http from 'k6/http';
import { check } from 'k6';
// A synthetic session prepared on the isolated stand can survive switching APP_ENV to production.
// Never point this benchmark at an owner/production account or put the fixture file in Git.
const fixtureSession = __ENV.LOAD_SESSION_FILE ? JSON.parse(open(__ENV.LOAD_SESSION_FILE)) : null;
export const options = {
  insecureSkipTLSVerify: __ENV.SELF_SIGNED_FIXTURE === 'true',
  scenarios: { web: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '60s', preAllocatedVUs: 100, maxVUs: 500 } },
  thresholds: { checks: ['rate==1'], http_req_duration: ['p(95)<300'], http_req_failed: ['rate<0.01'], dropped_iterations: ['count==0'] },
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};
export function setup() {
  if (fixtureSession) return fixtureSession;
  const response = http.get(__ENV.BASE_URL + '/dev/login-as/staff%40ezposter.local', { redirects: 0 });
  const sid = response.cookies.sid?.[0]?.value;
  if (response.status !== 302 || !sid) throw new Error('Isolated staff fixture login failed');
  return { sid };
}
// Deliberately include authenticated HTML, not liveness probes or the rate-limited staff back office.
// Admin security/availability is checked separately; its brute-force limits remain enabled.
const paths = ['/', '/account/security', '/help', '/account/notifications', '/status'];
export default function (data) {
  const path = paths[__ITER % paths.length];
  const cookieName = __ENV.BASE_URL.startsWith('https://') ? '__Host-sid' : 'sid';
  const r = http.get(__ENV.BASE_URL + path, { redirects: 0, headers: { Cookie: cookieName + '=' + data.sid }, tags: { page: path } });
  check(r, { 'expected status': x => x.status === 200 });
}
