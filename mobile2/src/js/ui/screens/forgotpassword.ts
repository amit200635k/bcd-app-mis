import { api, ApiError, ENDPOINTS } from '../../api/client';
import { navigate } from '../router';
import { APP_NAME } from '../../config';

export async function renderForgotPassword(root: HTMLElement): Promise<void> {
  root.innerHTML = `
    <div class="row justify-content-center mt-4">
      <div class="col-11 col-sm-8 col-md-6 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h4 class="text-center mb-1">${APP_NAME}</h4>
            <p class="text-center text-muted small mb-4">Reset your password</p>

            <div class="alert alert-danger d-none" id="fg-error" role="alert"></div>
            <div class="alert alert-success d-none" id="fg-success" role="alert"></div>

            <form id="fg-form" novalidate>
              <div class="mb-4">
                <label class="form-label" for="fg-username">Username</label>
                <input class="form-control" id="fg-username" type="text" autocomplete="username" required autofocus />
                <div class="form-text">A new password will be sent to the e-mail address registered for this account.</div>
              </div>
              <button class="btn btn-primary w-100" type="submit" id="fg-submit">Send new password</button>
              <p class="text-center mt-3 mb-0">
                <a href="#/login" id="fg-back">Back to sign in</a>
              </p>
            </form>
          </div>
        </div>
      </div>
    </div>`;

  const form = root.querySelector<HTMLFormElement>('#fg-form')!;
  const submitBtn = root.querySelector<HTMLButtonElement>('#fg-submit')!;
  const errorEl = root.querySelector<HTMLElement>('#fg-error')!;
  const successEl = root.querySelector<HTMLElement>('#fg-success')!;

  root.querySelector<HTMLElement>('#fg-back')!.addEventListener('click', (e) => {
    e.preventDefault();
    navigate('login');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = (root.querySelector<HTMLInputElement>('#fg-username')!.value ?? '').trim();
    if (!username) return;

    errorEl.classList.add('d-none');
    successEl.classList.add('d-none');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';
    try {
      const res = await api<{ message: string }>(ENDPOINTS.auth.forgotPassword, {
        method: 'POST',
        auth: false,
        body: { username },
      });
      successEl.textContent = res.message ?? 'If the account exists, a new password has been sent to its e-mail address.';
      successEl.classList.remove('d-none');
      form.querySelector<HTMLInputElement>('#fg-username')!.value = '';
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? err.errors
            ? Object.values(err.errors).flat().join(' ')
            : err.message
          : 'Could not reach the server. Check your connection and try again.';
      errorEl.textContent = msg;
      errorEl.classList.remove('d-none');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send new password';
    }
  });
}
