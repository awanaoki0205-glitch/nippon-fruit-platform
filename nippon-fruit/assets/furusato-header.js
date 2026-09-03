
(function () {
  'use strict';

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function openBrowseIfNeeded() {
    const hash = window.location.hash;

    if (
      hash !== '#nf_catalog_browse_section' &&
      hash !== '#nf_catalog_browse_drawer'
    ) {
      return;
    }

    const drawer = qs('#nf_catalog_browse_drawer');
    const button = qs('#nf_catalog_browse_toggle');

    if (drawer) drawer.hidden = false;

    if (button) {
      button.setAttribute('aria-expanded', 'true');
      const mark = button.querySelector('span');
      if (mark) mark.textContent = '−';
    }
  }

  function openAndFocusTarget(targetId) {
    const target = document.getElementById(targetId);
    if (!target) return false;

    if (target.matches('.nf-catalog-category-filter-card')) {
      target.hidden = false;
      const toggle = document.querySelector('[aria-controls="' + targetId + '"]');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'true');
        const mark = toggle.querySelector('[aria-hidden="true"]');
        if (mark) mark.textContent = '▲';
      }
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (target.matches('input,button,select,a[href]')) {
      window.setTimeout(function () { target.focus(); }, 350);
    }
    return true;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const menuButton = qs('#nf_furusato_menu_button');
    const mobileMenu = qs('#nf_furusato_mobile_menu');
    function closeMobileMenu() {
      if (!menuButton || !mobileMenu) return;
      mobileMenu.hidden = true;
      menuButton.setAttribute('aria-expanded', 'false');
    }
    if (menuButton && mobileMenu) {
      menuButton.addEventListener('click', function () {
        const opening = mobileMenu.hidden;
        mobileMenu.hidden = !opening;
        menuButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
      });
      mobileMenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeMobileMenu);
      });
      document.addEventListener('click', function (event) {
        const header = qs('#nf_furusato_header');
        if (header && !header.contains(event.target)) closeMobileMenu();
      });
    }

    document.querySelectorAll(
      '.nf-furusato-header a'
    ).forEach(function (link) {
      link.addEventListener('click', function (event) {
        const targetId = link.getAttribute('data-nf-target');
        if (targetId && openAndFocusTarget(targetId)) {
          event.preventDefault();
          window.history.replaceState(null, '', '#' + targetId);
        }
      });
    });

    openBrowseIfNeeded();

    window.addEventListener(
      'hashchange',
      openBrowseIfNeeded
    );
  });
})();
