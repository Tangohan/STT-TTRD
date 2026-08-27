(() => {
  const root = document.documentElement;
  addEventListener('pointermove', (e) => {
    root.style.setProperty('--mx', e.clientX + 'px');
    root.style.setProperty('--my', e.clientY + 'px');
  }, { passive: true });

  const isAdmin = document.body?.dataset?.page === 'admin';
  if (!isAdmin) {
    const probe = () => fetch('/api/probe', { method: 'POST' }).then((r) => r.json()).catch(() => null);
    const stale = document.body?.dataset?.stale === '1';
    if (stale) {
      probe().then((data) => {
        if (data && data.ok && !data.reason) location.reload();
      });
    }
    setInterval(probe, 60_000);
  }

  const search = document.querySelector('[data-filter-search]');
  const chips = [...document.querySelectorAll('[data-filter]')];
  const cards = [...document.querySelectorAll('[data-site-card]')];
  let mode = 'all';

  const apply = () => {
    const q = (search?.value || '').trim().toLowerCase();
    cards.forEach((card) => {
      const hay = (card.dataset.hay || '').toLowerCase();
      const group = card.dataset.group || '';
      const status = card.dataset.status || '';
      let ok = true;
      if (mode === 'ttrd' || mode === 'externe') ok = group === mode;
      else if (mode !== 'all') ok = status === mode;
      if (q && !hay.includes(q)) ok = false;
      card.classList.toggle('is-hidden', !ok);
    });
  };

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      chips.forEach((c) => c.classList.remove('is-on'));
      chip.classList.add('is-on');
      mode = chip.dataset.filter || 'all';
      apply();
    });
  });
  search?.addEventListener('input', apply);

  const migSearch = document.querySelector('[data-mig-search]');
  const migChips = [...document.querySelectorAll('[data-mig-filter]')];
  const migCards = [...document.querySelectorAll('[data-mig-card]')];
  let migMode = 'all';
  const applyMig = () => {
    const q = (migSearch?.value || '').trim().toLowerCase();
    migCards.forEach((card) => {
      const hay = (card.dataset.hay || '').toLowerCase();
      const attached = card.dataset.attached || 'x';
      const flagship = card.dataset.flagship === '1';
      let ok = true;
      if (migMode === 'flagship') ok = flagship;
      else if (migMode === 'unknown') ok = attached === 'x';
      else if (migMode === 'attached') ok = attached === '1';
      else if (migMode === 'skip') ok = attached === '0';
      if (q && !hay.includes(q)) ok = false;
      card.classList.toggle('is-hidden', !ok);
    });
  };
  migChips.forEach((chip) => {
    chip.addEventListener('click', () => {
      migChips.forEach((c) => c.classList.remove('is-on'));
      chip.classList.add('is-on');
      migMode = chip.dataset.migFilter || 'all';
      applyMig();
    });
  });
  migSearch?.addEventListener('input', applyMig);
})();
