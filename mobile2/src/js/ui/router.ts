import { isLoggedIn, logout, currentUser } from '../auth';
import { renderLogin } from './screens/login';
import { renderForgotPassword } from './screens/forgotpassword';
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
  { pattern: /^forgot-password$/, title: 'Forgot Password', screen: renderForgotPassword, requiresAuth: false },
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
const drawerEl = document.getElementById('drawer') as HTMLElement;
const overlayEl = document.getElementById('drawer-overlay') as HTMLElement;

/** Closes the drawer if open. */
export function closeDrawer(): void {
  drawerEl.classList.remove('open');
  overlayEl.classList.remove('open');
}

/** Opens the drawer. */
export function openDrawer(): void {
  drawerEl.classList.add('open');
  overlayEl.classList.add('open');
}

/** Populate drawer with user info and highlight active route. */
export async function refreshDrawer(activeRoute: string): Promise<void> {
  const user = await currentUser();
  const nameEl = document.getElementById('drawer-user-name');
  const roleEl = document.getElementById('drawer-user-role');
  if (nameEl) nameEl.textContent = user?.full_name || user?.username || 'Surveyor';
  if (roleEl) roleEl.textContent = user?.role || '';
  drawerEl.querySelectorAll('.drawer-item[data-to]').forEach((el) => {
    el.classList.toggle('active', el.getAttribute('data-to') === activeRoute);
  });
}

export function navigate(to: string): void {
  closeDrawer();
  if (location.hash === `#/${to}`) {
    void route();
  } else {
    location.hash = `#/${to}`;
  }
}

function topbar(title: string, showAuth: boolean, showLogout: boolean): void {
  topbarEl.innerHTML = `
    <header class="topbar">
      ${showAuth ? '<button type="button" class="topbar-hamburger" id="btn-hamburger" aria-label="Menu">&#9776;</button>' : ''}
      <div class="topbar-title" id="topbar-title">${title}</div>
      <div class="d-flex align-items-center gap-2">
        ${showLogout ? '<button type="button" class="btn btn-sm btn-light" id="btn-logout">Sign out</button>' : ''}
      </div>
    </header>`;

  const hamburger = topbarEl.querySelector('#btn-hamburger');
  if (hamburger) {
    hamburger.addEventListener('click', openDrawer);
  }

  const btn = topbarEl.querySelector('#btn-logout');
  if (btn) {
    btn.addEventListener('click', () => {
      closeDrawer();
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
  topbar(matched.title, matched.requiresAuth, matched.requiresAuth);
  viewRoot.innerHTML = '';

  if (matched.requiresAuth) {
    await refreshDrawer(hash === '' ? 'home' : hash.split('/')[0]);
  }

  await matched.screen(viewRoot, params);
}
