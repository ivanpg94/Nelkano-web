const guideToc = document.querySelector('details.r-guide-toc');
if (guideToc && window.matchMedia) {
  const desktopGuide = window.matchMedia('(min-width: 1024px)');
  const fitGuideIndex = () => { guideToc.open = desktopGuide.matches; };
  fitGuideIndex();
  desktopGuide.addEventListener('change', fitGuideIndex);
}

const lightbox = document.querySelector('.r-lightbox');
let lightboxTrigger;
document.querySelectorAll('[data-r-lightbox]').forEach(link => {
  link.addEventListener('click', event => {
    if (!lightbox?.showModal) return;
    event.preventDefault();
    lightboxTrigger = link;
    lightbox.querySelector('img').src = link.href;
    lightbox.querySelector('img').alt = link.querySelector('img').alt;
    lightbox.showModal();
  });
});
lightbox?.querySelector('button')?.addEventListener('click', () => lightbox.close());
lightbox?.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });
lightbox?.addEventListener('close', () => lightboxTrigger?.focus({preventScroll: true}));
