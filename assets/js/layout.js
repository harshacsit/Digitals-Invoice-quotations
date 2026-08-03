/* ============================================================
   AdsDash — layout.js
   Loads partials/sidebar.html and partials/topbar.html into
   every page (so the nav/topbar markup lives in one place),
   then highlights the active link based on <body data-page="...">.
   Must load BEFORE script.js.
   ============================================================ */
(function () {
  const currentPage = document.body.getAttribute('data-page') || '';

  function inject(url, targetSelector, afterInject) {
    fetch(url)
      .then(res => res.text())
      .then(html => {
        document.querySelector(targetSelector).innerHTML = html;
        if (afterInject) afterInject();
      })
      .catch(err => console.error('AdsDash layout: failed to load', url, err));
  }

  document.addEventListener('DOMContentLoaded', function () {
    inject('partials/sidebar.html', '#sidebar-placeholder', function () {
      document.querySelectorAll('#sidebar-placeholder .nav-link').forEach(link => {
        if (link.getAttribute('data-nav') === currentPage) link.classList.add('active');
      });
    });

    inject('partials/topbar.html', '#topbar-placeholder', function () {
      const toggle  = document.getElementById('sidebarToggle');
      const sidebar = document.getElementById('sidebar');
      if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        document.addEventListener('click', function (e) {
          if (window.innerWidth <= 991 && sidebar.classList.contains('show')
              && !sidebar.contains(e.target) && e.target !== toggle) {
            sidebar.classList.remove('show');
          }
        });
      }
      document.dispatchEvent(new Event('adsdash:layoutReady'));
    });
  });
})();
