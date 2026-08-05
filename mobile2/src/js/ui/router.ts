import { isLoggedIn, logout } from '../auth';
import { renderLogin } from './screens/login';
import { renderHome } from './screens/home';
import { renderForms } from './screens/forms';
import { renderFormFill } from './screens/formfill';
import { renderRecords } from './screens/records';
import { renderSync } from './screens/sync';
import { renderNotifications } from './screens/notifications';
import { renderSettings } from './screens/settings';
import { APP_NAME } from '../config';

export interface RouteParams {
  [name: string]: string;
}

interface Screen {
  (root: HTMLElement, params: RouteParams): Promise<void> | void;
}

interface Route {
  pattern: RegExp;
  title: string;
  screen: Screen;
  /** When true the route redirects to #/login when signed out. */
  requiresAuth: boolean;
}

const ROUTES: Route[] = [
  { pattern: /^login$/, title: 'Sign In', screen: renderLogin, requiresAuth: false },
  { pattern: /^(|home)$/, title: 'Home', screen: renderHome, requiresAuth: true },
  { pattern: /^forms$/, title: 'Surveys', screen: renderForms, requiresAuth: true },
  { pattern: /^form\/(?<id>\d+)$/, title: 'Fill Survey', screen: renderFormFill, requiresAuth: true },
  { pattern: /^records$/, title: 'My Records', screen: renderRecords, requiresAuth: true },
  { pattern: /^sync$/, title: 'Sync', screen: renderSync, requiresAuth: true },
  { pattern: /^notifications$/, title: 'Notifications', screen: renderNotifications, requiresAuth: true },
  { pattern: /^settings$/, title: 'Settings', screen: renderSettings, requiresAuth: true },
];

const viewRoot = document.getElementById('view-root') as HTMLElement;
const topbarEl = document.getElementById('topbar') as HTMLElement;

export function navigate(to: string): void {
  if (location.hash === `#/${to}`) {
    void route();
  } else {
    location.hash = `#/${to}`;
  }
}

function topbar(title: string, showLogout: boolean): void {
  topbarEl.innerHTML = `
    <header class="topbar">
      <div class="topbar-title" id="topbar-title">${title}</div>
      <div class="d-flex align-items-center gap-2">
        ${showLogout ? '<button type="button" class="btn btn-sm btn-light" id="btn-logout">Sign out</button>' : ''}
      </div>
    </header>`;
  const btn = topbarEl.querySelector('#btn-logout');
  if (btn) {
    btn.addEventListener('click', () => {
      void logout().finally(() => navigate('login'));
    });
  }
}

/** Match the current hash to a route and render its screen. */
export async function route(): Promise<void> {
  const hash = location.hash.replace(/^#\/?/, '');

  let matched: Route | null = null;
  let params: RouteParams = {};
  for (const r of ROUTES) {
    const m = r.pattern.exec(hash);
    if (m) {
      matched = r;
      params = m.groups ?? {};
      break;
    }
  }

  if (!matched) {
    navigate('home');
    return;
  }

  if (matched.requiresAuth && !(await isLoggedIn())) {
    navigate('login');
    return;
  }

  document.title = `${matched.title} — ${APP_NAME}`;
  topbar(matched.title, matched.requiresAuth);
  viewRoot.innerHTML = '';
  await matched.screen(viewRoot, params);
}
