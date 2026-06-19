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

  // Dice-roll badges in post bodies (rendered server-side at post time).
  if (!document.getElementById('rp-roll-style')) {
    var rollStyle = document.createElement('style');
    rollStyle.id = 'rp-roll-style';
    rollStyle.textContent =
      '.rp-roll{display:inline-flex;align-items:center;gap:4px;font-size:.92em;font-weight:600;line-height:1.4;' +
      'background:rgba(91,91,214,.12);color:rgb(var(--c-primary));border:1px solid rgba(91,91,214,.28);' +
      'padding:0 7px;border-radius:6px;white-space:nowrap;vertical-align:baseline}' +
      '.rp-roll b{font-weight:800}';
    document.head.appendChild(rollStyle);
  }

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
