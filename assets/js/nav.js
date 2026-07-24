/* Shared site header: mobile drawer + megamenu accordions */
(function () {
  var header = document.querySelector('.site-header');
  if (!header) return;
  var toggle = header.querySelector('.mn-toggle');
  var isMobile = function () { return window.matchMedia('(max-width: 1040px)').matches; };

  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = header.classList.toggle('mn-nav-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.innerHTML = open ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
      document.body.style.overflow = open ? 'hidden' : '';
    });
  }

  // close drawer when a real link is tapped
  header.querySelectorAll('.mn-nav a').forEach(function (a) {
    a.addEventListener('click', function () {
      if (header.classList.contains('mn-nav-open')) {
        header.classList.remove('mn-nav-open');
        document.body.style.overflow = '';
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.innerHTML = '<i class="fas fa-bars"></i>'; }
      }
    });
  });

  // mega triggers: on mobile they toggle an accordion; on desktop hover/focus handles it
  header.querySelectorAll('.mn-trigger').forEach(function (trg) {
    var mega = trg.parentElement.querySelector('.mn-mega');
    trg.addEventListener('click', function (e) {
      if (!isMobile()) return; // desktop uses CSS hover
      e.preventDefault();
      var open = mega.classList.toggle('mn-open');
      trg.setAttribute('aria-expanded', open ? 'true' : 'false');
      // close siblings
      header.querySelectorAll('.mn-mega.mn-open').forEach(function (m) {
        if (m !== mega) { m.classList.remove('mn-open'); m.parentElement.querySelector('.mn-trigger').setAttribute('aria-expanded', 'false'); }
      });
    });
  });

  // reset accordion state when crossing the breakpoint back to desktop
  window.addEventListener('resize', function () {
    if (!isMobile()) {
      header.classList.remove('mn-nav-open');
      document.body.style.overflow = '';
      header.querySelectorAll('.mn-mega.mn-open').forEach(function (m) { m.classList.remove('mn-open'); });
    }
  });
})();
