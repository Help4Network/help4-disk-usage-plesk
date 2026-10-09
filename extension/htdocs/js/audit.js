'use strict';
document.addEventListener('click', async event => {
  const button = event.target.closest('button[data-copy]');
  if (!button) return;
  const message = document.querySelector('.h4-copy-status');
  try {
    await navigator.clipboard.writeText(button.dataset.copy);
    message.textContent = 'Relative path copied.';
  } catch (_) {
    message.textContent = 'Clipboard unavailable. Select and copy the relative path.';
  }
});
