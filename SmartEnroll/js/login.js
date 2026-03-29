document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('loginForm');
  const popup = document.getElementById('loginErrorPopup');
  const closePopup = document.getElementById('closeLoginPopup');
  const popupIcon = document.getElementById('loginPopupIcon');
  const emailInput = document.getElementById('loginEmail');
  const rememberInput = document.getElementById('rememberLogin');
  const rememberedEmailKey = 'smartenrollRememberedEmail';

  if (!loginForm) return;

  const savedEmail = window.localStorage.getItem(rememberedEmailKey) || '';
  if (savedEmail && emailInput) {
    emailInput.value = savedEmail;
    if (rememberInput) {
      rememberInput.checked = true;
    }
  }

  function showPopup() {
    if (popup) {
      popup.classList.add('active');
      if (popupIcon) {
        popupIcon.classList.remove('show-alert');
        setTimeout(() => {
          popupIcon.classList.add('show-alert');
        }, 120);
      }
    }
  }

  function hidePopup() {
    if (popup) {
      popup.classList.remove('active');
    }
    if (popupIcon) {
      popupIcon.classList.remove('show-alert');
    }
  }

  loginForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const email = (emailInput?.value || '').trim();
    const password = loginForm.querySelector('input[name="password"]')?.value || '';
    const shouldRemember = Boolean(rememberInput?.checked);

    if (email === 'adreomontessori@gmail.com' && password === 'adreo') {
      if (shouldRemember) {
        window.localStorage.setItem(rememberedEmailKey, email);
      } else {
        window.localStorage.removeItem(rememberedEmailKey);
      }
      window.location.href = 'dashboard.php';
      return;
    }

    showPopup();
  });

  if (closePopup) {
    closePopup.addEventListener('click', hidePopup);
  }

  if (popup) {
    popup.addEventListener('click', (event) => {
      if (event.target === popup) {
        hidePopup();
      }
    });
  }
});
