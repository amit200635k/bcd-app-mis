import { login } from '../../auth';
import { ApiError } from '../../api/client';
import { navigate } from '../router';
import { APP_NAME } from '../../config';

export async function renderLogin(root: HTMLElement): Promise<void> {
  root.innerHTML = `
    <div class="row justify-content-center mt-4">
      <div class="col-11 col-sm-8 col-md-6 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h4 class="text-center mb-1">${APP_NAME}</h4>
            <p class="text-center text-muted small mb-4">Offline-first Survey Platform</p>

            <div class="alert alert-danger d-none" id="login-error" role="alert"></div>

            <form id="login-form" novalidate>
              <div class="mb-3">
                <label class="form-label" for="login-username">Username</label>
                <input class="form-control" id="login-username" type="text" autocomplete="username" required autofocus />
              </div>
              <div class="mb-4">
                <label class="form-label" for="login-password">Password</label>
                <input class="form-control" id="login-password" type="password" autocomplete="current-password" required />
              </div>
              <button class="btn btn-primary w-100" type="submit" id="login-submit">Sign in</button>
              <p class="text-center text-muted small mt-3 mb-0">
                First sign-in downloads your forms for offline use.
              </p>
            </form>
          </div>
        </div>
      </div>
    </div>`;

  const form = root.querySelector<HTMLFormElement>('#login-form')!;
  const submitBtn = root.querySelector<HTMLButtonElement>('#login-submit')!;
  const errorEl = root.querySelector<HTMLElement>('#login-error')!;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = (root.querySelector<HTMLInputElement>('#login-username')!.value ?? '').trim();
    const password = root.querySelector<HTMLInputElement>('#login-password')!.value ?? '';
    if (!username || !password) return;

    errorEl.classList.add('d-none');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Preparing offline data…';
    try {
      await login(username, password);
      navigate('home');
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? err.errors
            ? Object.values(err.errors).flat().join(' ')
            : err.message
          : 'Could not reach the server. Check your connection and try again.';
      errorEl.textContent = msg;
      errorEl.classList.remove('d-none');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Sign in';
    }
  });
}
