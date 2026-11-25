(() => {
  const dialog = document.querySelector('#screenshot-dialog');
  const triggers = [...document.querySelectorAll('.screenshot-trigger')];
  if (!dialog || typeof dialog.showModal !== 'function') return;

  const image = dialog.querySelector('.lightbox-image');
  const title = dialog.querySelector('#lightbox-title');
  const position = dialog.querySelector('[data-gallery-position]');
  let current = 0;
  let lastTrigger = null;

  function showImage(index) {
    current = (index + triggers.length) % triggers.length;
    const trigger = triggers[current];
    const preview = trigger.querySelector('img');
    image.src = trigger.href;
    image.alt = preview.alt;
    image.width = Number(preview.getAttribute('width'));
    image.height = Number(preview.getAttribute('height'));
    title.textContent = trigger.dataset.title;
    position.textContent = `${current + 1} / ${triggers.length}`;
  }

  triggers.forEach((trigger, index) => {
    trigger.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      lastTrigger = trigger;
      showImage(index);
      dialog.showModal();
      document.body.classList.add('gallery-open');
    });
  });

  dialog.querySelector('.lightbox-close').addEventListener('click', () => dialog.close());
  dialog.querySelector('[data-gallery-prev]').addEventListener('click', () => showImage(current - 1));
  dialog.querySelector('[data-gallery-next]').addEventListener('click', () => showImage(current + 1));
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  });
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      showImage(current + (event.key === 'ArrowRight' ? 1 : -1));
    }
  });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('gallery-open');
    lastTrigger?.focus({ preventScroll: true });
  });
})();
