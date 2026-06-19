// convoro-roleplay — forum runtime.
//
// Displaying a post's character byline is entirely server-side (the core
// PostIdentity hook), so nothing here touches existing posts. This file adds the
// composer "post as" selector: it sets an `rp_as` cookie naming the active
// character, and the server links each newly created post to it (Post::created).
(function () {
  var c = window.Convoro;
  if (!c || typeof c.registerSlot !== 'function') return;

  var COOKIE = 'rp_as';
  var YEAR = 60 * 60 * 24 * 365;

  function getActive() {
    var m = document.cookie.match(/(?:^|;\s*)rp_as=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setActive(v) {
    document.cookie = COOKIE + '=' + encodeURIComponent(v) + '; path=/; max-age=' + YEAR + '; samesite=lax';
  }

  // Global role-play styling: dice badges, character avatar palette, and the
  // in-character post treatment. Injected once, applies on every forum page.
  if (!document.getElementById('rp-global-style')) {
    var gStyle = document.createElement('style');
    gStyle.id = 'rp-global-style';
    gStyle.textContent =
      // dice-roll badges (rendered server-side at post time)
      '.rp-roll{display:inline-flex;align-items:center;gap:4px;font-size:.92em;font-weight:600;line-height:1.4;' +
      'background:rgba(91,91,214,.12);color:rgb(var(--c-primary));border:1px solid rgba(91,91,214,.28);' +
      'padding:0 7px;border-radius:6px;white-space:nowrap;vertical-align:baseline}' +
      '.rp-roll b{font-weight:800}' +
      // character avatar / banner palette (mirrors core Avatar.vue gradients)
      '.rp-g1{background:linear-gradient(135deg,#f472b6,#db2777)}' +
      '.rp-g2{background:linear-gradient(135deg,#60a5fa,#2563eb)}' +
      '.rp-g3{background:linear-gradient(135deg,#34d399,#059669)}' +
      '.rp-g4{background:linear-gradient(135deg,#fbbf24,#d97706)}' +
      '.rp-g5{background:linear-gradient(135deg,#a78bfa,#7c3aed)}' +
      '.rp-g6{background:linear-gradient(135deg,#f87171,#dc2626)}' +
      // in-character post treatment
      '.q-post.rp-ic{position:relative;border-left:4px solid rgb(var(--c-primary));' +
      'background-image:linear-gradient(rgba(91,91,214,.05),rgba(91,91,214,.05))}' +
      '.rp-ic-badge{position:absolute;top:14px;right:16px;display:inline-flex;align-items:center;gap:4px;' +
      'font-size:11px;font-weight:700;letter-spacing:.02em;color:rgb(var(--c-primary));' +
      'background:rgba(91,91,214,.12);border:1px solid rgba(91,91,214,.28);padding:2px 9px;' +
      'border-radius:999px;pointer-events:none;z-index:2}';
    document.head.appendChild(gStyle);
  }

  // Mark posts authored as a character (their author links to /characters/…).
  function markRpPosts() {
    document.querySelectorAll('article.q-post').forEach(function (art) {
      if (art.getAttribute('data-rp-checked')) return;
      art.setAttribute('data-rp-checked', '1');
      var links = art.querySelectorAll('a[href^="/characters/"]');
      var isIc = false;
      for (var i = 0; i < links.length; i++) {
        // ignore character links that appear inside the post body itself
        if (!links[i].closest('.prose-q')) { isIc = true; break; }
      }
      if (!isIc) return;
      art.classList.add('rp-ic');
      if (!art.querySelector('.rp-ic-badge')) {
        var b = document.createElement('span');
        b.className = 'rp-ic-badge';
        b.textContent = '🎭 In character';
        art.appendChild(b);
      }
    });
  }
  var rpScan;
  function scheduleScan() { cancelAnimationFrame(rpScan); rpScan = requestAnimationFrame(markRpPosts); }
  scheduleScan();
  // Re-scan on SPA navigation + when live replies are appended.
  new MutationObserver(scheduleScan).observe(document.body, { childList: true, subtree: true });

  // Inject styles once.
  if (!document.getElementById('rp-as-style')) {
    var style = document.createElement('style');
    style.id = 'rp-as-style';
    style.textContent =
      '.rp-as{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:rgb(var(--c-muted));' +
      'white-space:nowrap}' +
      '.rp-as svg{width:15px;height:15px;opacity:.8}' +
      '.rp-as-select{font:inherit;font-size:13px;font-weight:600;padding:3px 22px 3px 8px;border-radius:var(--c-radius,8px);' +
      'border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;' +
      "appearance:none;-webkit-appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");" +
      'background-repeat:no-repeat;background-position:right 5px center;background-size:12px}' +
      '.rp-as-select:hover{border-color:rgb(var(--c-primary))}' +
      '.rp-as-select.rp-as-on{border-color:rgb(var(--c-primary));color:rgb(var(--c-primary))}';
    document.head.appendChild(style);
  }

  var MASK_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h7v6a3.5 3.5 0 0 1-7 0V5z"/>' +
    '<path d="M14 5h7v6a3.5 3.5 0 0 1-7 0V5z"/><path d="M6.5 14c.5 1 1.5 1.5 3 1.5"/></svg>';

  c.registerSlot('composer:toolbar', {
    ext: 'convoro-roleplay',
    order: 40,
    mount: function (el) {
      var wrap = document.createElement('div');
      wrap.className = 'rp-as';
      wrap.style.display = 'none'; // hidden until we know the user has characters
      wrap.innerHTML = MASK_SVG;

      var sel = document.createElement('select');
      sel.className = 'rp-as-select';
      sel.title = 'Choose which character to post as';
      var opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Yourself';
      sel.appendChild(opt);
      wrap.appendChild(sel);
      el.appendChild(wrap);

      function reflect() {
        sel.classList.toggle('rp-as-on', !!sel.value);
      }

      fetch('/api/ext/rp/me', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { characters: [] }; })
        .then(function (d) {
          var chars = (d && d.characters) || [];
          if (!chars.length) return; // no characters → stay hidden, no clutter
          chars.forEach(function (ch) {
            var o = document.createElement('option');
            o.value = String(ch.id);
            o.textContent = ch.name;
            sel.appendChild(o);
          });
          // Restore the active character if it still belongs to the user.
          var active = getActive();
          if (active && sel.querySelector('option[value="' + active + '"]')) {
            sel.value = active;
          } else if (active) {
            setActive(''); // stale (character gone) — clear
          }
          reflect();
          wrap.style.display = '';
        })
        .catch(function () {});

      sel.addEventListener('change', function () {
        setActive(sel.value);
        reflect();
      });

      return function () { if (el.contains(wrap)) el.removeChild(wrap); };
    },
  });
})();
