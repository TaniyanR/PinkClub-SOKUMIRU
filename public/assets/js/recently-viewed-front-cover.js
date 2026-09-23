(() => {
  'use strict';

  const STORAGE_KEY = 'pcf_recently_viewed_v1';
  const list = document.getElementById('pcf-recent-list');
  if (!list) return;

  let history;
  try {
    history = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
  } catch (_) {
    return;
  }
  if (!Array.isArray(history) || history.length === 0) return;

  const ids = [];
  history.forEach((entry) => {
    const id = Number.parseInt(String(entry && entry.id || ''), 10);
    if (Number.isInteger(id) && id > 0 && !ids.includes(id)) ids.push(id);
  });
  if (ids.length === 0) return;

  const endpoint = new URL('recent_images.php', window.location.href);
  endpoint.searchParams.set('ids', ids.slice(0, 10).join(','));
  endpoint.searchParams.set('v', 'portrait-2');

  fetch(endpoint.href, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' }
  })
    .then((response) => response.ok ? response.json() : null)
    .then((payload) => {
      const images = payload && payload.images && typeof payload.images === 'object'
        ? payload.images
        : null;
      if (!images) return;

      // Preserve removals/hide actions performed while the request was pending.
      try {
        history = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      } catch (_) { return; }
      if (!Array.isArray(history)) return;
      let changed = false;
      history = history.map((entry) => {
        if (!entry || typeof entry !== 'object') return entry;
        const id = Number.parseInt(String(entry.id || ''), 10);
        const image = typeof images[String(id)] === 'string' ? images[String(id)].trim() : '';
        if (!Object.prototype.hasOwnProperty.call(images, String(id))) return entry;
        if (entry.image === image && entry.imageType === 'front-cover') return entry;
        changed = true;
        return { ...entry, image, imageType: 'front-cover' };
      });

      if (changed) {
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch (_) {}
      }

      window.dispatchEvent(new Event('pcf-recent-images-updated'));
    })
    .catch(() => {});
})();
