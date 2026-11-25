(function(){
  function ready(fn){
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function(){
    var isStandalone = false;
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
      isStandalone = true;
    }
    if (window.navigator && window.navigator.standalone) {
      isStandalone = true;
    }
    if (isStandalone) {
      document.body.classList.add('is-standalone');
    }

    var kioskTimeoutUrl = document.body.getAttribute('data-kiosk-timeout-url');
    var kioskTimeoutAction = document.body.getAttribute('data-kiosk-timeout-action') || 'reset_student';
    var kioskTimeoutCsrf = document.body.getAttribute('data-kiosk-timeout-csrf') || '';
    var kioskTimeoutMs = parseInt(document.body.getAttribute('data-kiosk-timeout-ms') || '10000', 10);
    if (kioskTimeoutUrl && kioskTimeoutMs > 0) {
      var kioskTimer = null;
      var resetKioskTimer = function(){
        window.clearTimeout(kioskTimer);
        kioskTimer = window.setTimeout(function(){
          if (kioskTimeoutCsrf && window.fetch) {
            var body = new URLSearchParams();
            body.set('csrf', kioskTimeoutCsrf);
            body.set('action', kioskTimeoutAction);
            window.fetch(kioskTimeoutUrl, {method:'POST', credentials:'same-origin', body:body})
              .then(function(){ window.location.href = kioskTimeoutUrl; })
              .catch(function(){ window.location.href = kioskTimeoutUrl; });
          } else {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = kioskTimeoutUrl;
            [['csrf', kioskTimeoutCsrf], ['action', kioskTimeoutAction]].forEach(function(pair){
              var input = document.createElement('input');
              input.type = 'hidden';
              input.name = pair[0];
              input.value = pair[1];
              form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
          }
        }, kioskTimeoutMs);
      };
      ['click', 'keydown', 'pointerdown', 'touchstart', 'mousemove', 'scroll'].forEach(function(eventName){
        document.addEventListener(eventName, resetKioskTimer, {passive: true});
      });
      resetKioskTimer();
    }

    // Burger menu
    var btn = document.querySelector('[data-burger]');
    var nav = document.getElementById('mainnav');
    if (btn && nav){
      var setMenuOpen = function(open, returnFocus){
        nav.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
        if (returnFocus) btn.focus();
      };
      btn.addEventListener('click', function(){
        setMenuOpen(!nav.classList.contains('is-open'));
      });

      // Close on outside click (mobile)
      document.addEventListener('click', function(e){
        if (!nav.classList.contains('is-open')) return;
        if (nav.contains(e.target) || btn.contains(e.target)) return;
        setMenuOpen(false);
      });
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && nav.classList.contains('is-open')) {
          setMenuOpen(false, true);
        }
      });
      document.addEventListener('focusin', function(e){
        if (nav.classList.contains('is-open') && !nav.contains(e.target) && !btn.contains(e.target)) {
          setMenuOpen(false);
        }
      });
      nav.addEventListener('click', function(e){
        if (e.target.closest('a, button[type="submit"]')) setMenuOpen(false);
      });
      // A closed mobile menu must not remain open after a breakpoint change.
      var mobileMenu = window.matchMedia('(max-width: 720px)');
      if (mobileMenu.addEventListener) mobileMenu.addEventListener('change', function(){ setMenuOpen(false); });
      else if (mobileMenu.addListener) mobileMenu.addListener(function(){ setMenuOpen(false); });
    }

    // Tables -> mobile card labels (generic)
    var tables = document.querySelectorAll('table.table');
    tables.forEach(function(table){
      if (table.dataset.cardsProcessed) return;
      var ths = table.querySelectorAll('thead th');
      if (!ths.length) return;

      var labels = Array.prototype.map.call(ths, function(th){
        return (th.textContent || '').trim();
      });

      var rows = table.querySelectorAll('tbody tr');
      rows.forEach(function(tr){
        var tds = tr.querySelectorAll('td');
        tds.forEach(function(td, idx){
          if (!td.getAttribute('data-label') && labels[idx]){
            td.setAttribute('data-label', labels[idx]);
          }
        });
      });

      table.dataset.cardsProcessed = '1';
    });
  });
})();
