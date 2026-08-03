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

  /* ------ Toggle icon helper ------ */
  function updateToggleIcon(toggle, sidebar) {
    const icon = toggle.querySelector('i');
    if (!icon) return;
    if (window.innerWidth <= 991) {
      // Mobile: show X when sidebar is open, hamburger when closed
      icon.className = sidebar.classList.contains('show') ? 'bi bi-x-lg' : 'bi bi-list';
    } else {
      // Desktop: show arrow-left when collapsed, hamburger when expanded
      icon.className = document.body.classList.contains('sidebar-collapsed')
        ? 'bi bi-arrow-bar-right'
        : 'bi bi-arrow-bar-left';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    inject('partials/sidebar.html', '#sidebar-placeholder', function () {
      document.querySelectorAll('#sidebar-placeholder .nav-link').forEach(link => {
        if (link.getAttribute('data-nav') === currentPage) link.classList.add('active');
      });

      // Restore persisted collapsed state on desktop
      if (window.innerWidth > 991 && localStorage.getItem('adsdash_sidebar_collapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
      }
    });

    inject('partials/topbar.html', '#topbar-placeholder', function () {
      const toggle  = document.getElementById('sidebarToggle');
      const sidebar = document.getElementById('sidebar');

      // Create overlay element if not present
      let backdrop = document.querySelector('.sidebar-backdrop');
      if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        document.body.appendChild(backdrop);
      }

      function toggleSidebar() {
        if (window.innerWidth <= 991) {
          sidebar.classList.toggle('show');
          backdrop.classList.toggle('show', sidebar.classList.contains('show'));
        } else {
          document.body.classList.toggle('sidebar-collapsed');
          // Persist state
          localStorage.setItem('adsdash_sidebar_collapsed',
            document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
        }
        updateToggleIcon(toggle, sidebar);
      }

      function closeSidebar() {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
        updateToggleIcon(toggle, sidebar);
      }

      if (toggle && sidebar) {
        // Initialise icon to match current state
        // Wait a tick for sidebar-placeholder injection to apply the class
        setTimeout(() => updateToggleIcon(toggle, sidebar), 50);

        toggle.addEventListener('click', function(e) {
          e.stopPropagation();
          toggleSidebar();
        });

        backdrop.addEventListener('click', closeSidebar);

        document.addEventListener('click', function (e) {
          if (window.innerWidth <= 991 && sidebar.classList.contains('show')
              && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
            closeSidebar();
          }
        });

        // Update icon if window resizes across the breakpoint
        window.addEventListener('resize', () => updateToggleIcon(toggle, sidebar));
      }

      // Smooth Page Navigation Transitions
      document.querySelectorAll('a[href$=".html"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
          const href = this.getAttribute('href');
          if (!href || href.startsWith('#') || href.includes('javascript:') || this.getAttribute('target') === '_blank') return;
          
          const targetUrl = new URL(href, window.location.href);
          if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) return;

          e.preventDefault();
          const pageEl = document.querySelector('.page');
          if (pageEl) {
            pageEl.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
            pageEl.style.opacity = '0';
            pageEl.style.transform = 'translateY(-8px)';
            setTimeout(() => {
              window.location.href = href;
            }, 200);
          } else {
            window.location.href = href;
          }
        });
      });

      document.dispatchEvent(new Event('adsdash:layoutReady'));
    });
  });
})();
