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
  function csrf() {
    var m = document.querySelector('meta[name=csrf-token]');
    return m ? m.getAttribute('content') : '';
  }

  // Role-play context for a topic/category, cached so the picker, dice button and
  // topic badge share one request. Returns {rp, canToggle, topicId, characters}.
  var ctxCache = {};
  function getRpContext(topicId, categoryId, slug) {
    var key = topicId ? 't' + topicId : (slug ? 's' + slug : (categoryId ? 'c' + categoryId : ''));
    if (!key) return Promise.resolve(null);
    if (ctxCache[key]) return ctxCache[key];
    var q = topicId ? 'topic=' + topicId : (slug ? 'slug=' + encodeURIComponent(slug) : 'category=' + categoryId);
    ctxCache[key] = fetch('/api/ext/rp/context?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
    return ctxCache[key];
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
      'border-radius:999px;pointer-events:none;z-index:2}' +
      // dice button in the composer toolbar
      '.rp-dice{position:relative;display:inline-flex}' +
      '.rp-dice-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;' +
      'border-radius:8px;border:none;background:transparent;color:rgb(var(--c-muted));cursor:pointer}' +
      '.rp-dice-btn:hover{background:rgb(var(--c-surface));color:rgb(var(--c-text))}' +
      '.rp-dice-btn svg{width:18px;height:18px}' +
      '.rp-dice-pop{position:absolute;top:36px;right:0;z-index:50;width:236px;padding:12px;' +
      'background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:12px;' +
      'box-shadow:0 14px 34px -12px rgba(0,0,0,.45)}' +
      '.rp-dice-row{display:flex;align-items:center;gap:6px}' +
      '.rp-dice-n,.rp-dice-m{width:50px}' +
      '.rp-dice-n,.rp-dice-m,.rp-dice-d{font:inherit;font-size:13px;padding:7px 8px;border-radius:8px;' +
      'border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text))}' +
      '.rp-dice-d{flex:1}' +
      '.rp-dice-foot{display:flex;align-items:center;justify-content:space-between;margin-top:11px}' +
      '.rp-dice-prev{font-size:14px;font-weight:800;color:rgb(var(--c-primary))}' +
      '.rp-dice-go{font:inherit;font-size:13px;font-weight:600;padding:7px 13px;border-radius:8px;' +
      'border:none;background:rgb(var(--c-primary));color:#fff;cursor:pointer}' +
      // topic-page role-play badge + author/staff toggle
      '.rp-topic-bar{display:flex;align-items:center;gap:10px;margin:0 0 4px}' +
      '.rp-topic-tag{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;' +
      'letter-spacing:.02em;color:rgb(var(--c-primary));background:rgba(91,91,214,.12);' +
      'border:1px solid rgba(91,91,214,.28);padding:3px 10px;border-radius:999px}' +
      '.rp-topic-toggle{font:inherit;font-size:12px;font-weight:600;color:rgb(var(--c-muted));' +
      'background:none;border:none;cursor:pointer;text-decoration:underline;padding:0}' +
      '.rp-topic-toggle:hover{color:rgb(var(--c-primary))}';
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
  // Topic-page role-play marker: an "In character" badge on role-play threads,
  // plus a toggle for the author/staff to flip the topic between RP and regular.
  function setupTopicRp() {
    if (!/^\/t\//.test(location.pathname)) return;
    var h1 = document.querySelector('article.q-post h1');
    if (!h1 || h1.getAttribute('data-rp-done')) return;
    var slug = decodeURIComponent(location.pathname.split('/')[2] || '');
    if (!slug) return;
    h1.setAttribute('data-rp-done', '1');
    getRpContext(null, null, slug).then(function (d) {
      if (!d || (!d.rp && !d.canToggle)) return;
      var bar = document.createElement('div');
      bar.className = 'rp-topic-bar';
      if (d.rp) {
        var tag = document.createElement('span');
        tag.className = 'rp-topic-tag';
        tag.textContent = '🎭 Role-play thread';
        bar.appendChild(tag);
      }
      if (d.canToggle && d.topicId) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rp-topic-toggle';
        btn.textContent = d.rp ? 'Make regular' : 'Make role-play';
        btn.addEventListener('click', function () {
          btn.disabled = true;
          fetch('/api/ext/rp/topic/' + d.topicId + '/rp', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ rp: !d.rp }),
          }).then(function (r) { if (!r.ok) throw 0; location.reload(); })
            .catch(function () { btn.disabled = false; });
        });
        bar.appendChild(btn);
      }
      h1.parentNode.insertBefore(bar, h1);
    });
  }

  // ── Tactical combat tracker (injected into role-play topics) ────────────
  if (!document.getElementById('rp-combat-style')) {
    var cStyle = document.createElement('style');
    cStyle.id = 'rp-combat-style';
    cStyle.textContent =
      '.rp-combat-host{margin:12px 0 16px}' +
      '.rpc{border:1px solid rgb(var(--c-border));border-radius:14px;background:rgb(var(--c-surface));padding:14px 16px}' +
      '.rpc-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}' +
      '.rpc-title{font-weight:800;display:inline-flex;align-items:center;gap:6px}.rpc-title .fas{color:rgb(var(--c-primary))}' +
      '.rpc-round{font-size:11px;font-weight:800;color:#16a34a;background:rgba(22,163,74,.12);padding:2px 9px;border-radius:999px}' +
      '.rpc-badge{font-size:11px;font-weight:700;color:rgb(var(--c-muted));text-transform:uppercase;letter-spacing:.03em}' +
      '.rpc-list{list-style:none;margin:0 0 10px;padding:0;display:flex;flex-direction:column;gap:6px}' +
      '.rpc-cb{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:9px;transition:background .12s}' +
      '.rpc-cb.is-active{background:rgba(124,58,237,.1);box-shadow:inset 0 0 0 1px rgba(124,58,237,.4)}' +
      '.rpc-cb.is-down{opacity:.5}.rpc-turn{width:12px;color:#7c3aed;text-align:center}' +
      '.rpc-dot{flex:0 0 auto;width:10px;height:10px;border-radius:50%}.rpc-av{flex:0 0 auto;width:22px;height:22px;border-radius:50%;object-fit:cover}' +
      '.rpc-main{flex:1;min-width:0}.rpc-top{display:flex;justify-content:space-between;gap:6px}' +
      '.rpc-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.rpc-init{flex:0 0 auto;font-size:11px;font-weight:700;color:rgb(var(--c-muted))}' +
      '.rpc-bar{height:6px;border-radius:3px;background:rgba(0,0,0,.12);overflow:hidden;margin:3px 0 1px}.rpc-fill{display:block;height:100%;transition:width .3s ease}' +
      '.rpc-hp{font-size:11px;color:rgb(var(--c-muted))}' +
      '.rpc-controls{display:flex;flex-direction:column;gap:6px;padding-top:8px;border-top:1px solid rgb(var(--c-border))}' +
      '.rpc-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.rpc-in{font:inherit;font-size:13px;padding:6px 8px;border-radius:8px;border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));min-width:0}' +
      '.rpc-in.grow{flex:1}.rpc-btn{font:inherit;font-size:13px;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid rgb(var(--c-border));background:transparent;color:inherit;cursor:pointer}' +
      '.rpc-btn.primary{background:rgb(var(--c-primary));color:#fff;border-color:transparent}.rpc-btn.grow{flex:1}.rpc-btn.block{width:100%;justify-content:center}.rpc-end{color:#dc2626}' +
      '.rpc-play{background:rgba(124,58,237,.06);margin:0 -16px;padding:10px 16px}' +
      '.rpc-log{margin-top:10px;padding-top:8px;border-top:1px solid rgb(var(--c-border));display:flex;flex-direction:column;gap:4px;font-size:12px}' +
      '.rpc-dmg{color:#dc2626;font-weight:800}.rpc-miss{color:rgb(var(--c-muted));font-style:italic}.rpc-critx{color:#f59e0b;font-weight:800}' +
      '.rpc-outcome{display:flex;align-items:center;justify-content:center;gap:8px;margin:4px 0 10px;padding:9px;border-radius:10px;font-weight:800}' +
      '.rpc-victory{background:rgba(22,163,74,.14);color:#16a34a}.rpc-defeat{background:rgba(220,38,38,.12);color:#dc2626}';
    document.head.appendChild(cStyle);
  }

  function esc2(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function rpApi(url, opts) {
    return fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' } }, opts || {}))
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); });
  }

  function setupCombat() {
    if (!/^\/t\//.test(location.pathname)) return;
    var h1 = document.querySelector('article.q-post h1');
    if (!h1 || h1.getAttribute('data-rp-combat')) return;
    var slug = decodeURIComponent(location.pathname.split('/')[2] || '');
    if (!slug) return;
    h1.setAttribute('data-rp-combat', '1');
    getRpContext(null, null, slug).then(function (d) {
      if (!d || !d.rp || !d.topicId) return;
      var host = document.createElement('div');
      host.className = 'rp-combat-host';
      h1.parentNode.insertBefore(host, h1.nextSibling);
      mountTracker(host, d);
    });
  }

  function mountTracker(host, ctx) {
    var topicId = ctx.topicId, userId = ctx.userId, myChars = ctx.characters || [];
    var enc = null, cards = null, log = [], busy = false;
    var add = { name: '', team: 'foe', hp: '10', defense: '' };

    function activeCb() { if (!enc) return null; for (var i = 0; i < enc.combatants.length; i++) if (enc.combatants[i].id === enc.activeId) return enc.combatants[i]; return null; }
    function myTurn() { var a = activeCb(); return a && a.character && a.character.userId === userId; }
    function refetch() { rpApi('/api/ext/rp/encounter?topic=' + topicId, {}).then(function (res) { enc = res.j && res.j.data; render(); }); }
    function act(promise) {
      busy = true;
      promise.then(function (res) {
        busy = false;
        if (!res.ok) { alert((res.j && res.j.error) || 'That action failed.'); render(); return; }
        var data = res.j && res.j.data;
        if (data && data.result) { log.unshift(data.result); log = log.slice(0, 6); enc = data.encounter; }
        else { enc = data; }
        render();
      });
    }
    try { if (window.Echo) window.Echo.channel('rp.encounter.' + topicId).listen('.EncounterTouched', function (e) { if (e && e.encounter && !busy) { enc = e.encounter; render(); } }); } catch (x) {}
    setInterval(function () { if (!document.hidden && !busy) refetch(); }, 15000);

    function bar(cb) { var pct = cb.maxHp ? Math.max(0, Math.round((cb.hp / cb.maxHp) * 100)) : 0; return '<div class="rpc-bar"><span class="rpc-fill" style="width:' + pct + '%;background:' + (cb.team === 'party' ? '#16a34a' : '#dc2626') + '"></span></div>'; }
    function cbRow(cb) {
      var active = enc.activeId === cb.id;
      var av = cb.character && cb.character.avatarUrl ? '<img class="rpc-av" src="' + esc2(cb.character.avatarUrl) + '" alt="">' : '<span class="rpc-dot" style="background:' + (cb.team === 'party' ? '#16a34a' : '#dc2626') + '"></span>';
      var rm = (enc.isGm && enc.status === 'setup') ? '<button class="rpc-btn" data-rm="' + cb.id + '" title="Remove">&times;</button>' : '';
      return '<li class="rpc-cb' + (active ? ' is-active' : '') + (cb.isDown ? ' is-down' : '') + '"><span class="rpc-turn">' + (active ? '▶' : '') + '</span>' + av +
        '<span class="rpc-main"><span class="rpc-top"><span class="rpc-name">' + esc2(cb.name) + '</span>' + (enc.status !== 'setup' ? '<span class="rpc-init">⚡' + cb.initiative + '</span>' : '') + '</span>' +
        bar(cb) + '<span class="rpc-hp">' + (cb.isDown ? 'Down' : (cb.hp + '/' + cb.maxHp + ' HP')) + '</span></span>' + rm + '</li>';
    }
    function outcome() {
      if (!enc || enc.status !== 'active' || !enc.combatants.length) return '';
      var party = enc.combatants.filter(function (c) { return c.team === 'party'; }), foes = enc.combatants.filter(function (c) { return c.team === 'foe'; });
      if (party.length && party.every(function (c) { return c.isDown; })) return '<div class="rpc-outcome rpc-defeat">Defeat…</div>';
      if (foes.length && foes.every(function (c) { return c.isDown; })) return '<div class="rpc-outcome rpc-victory">Victory!</div>';
      return '';
    }
    function logRows() {
      if (!log.length) return '';
      return '<div class="rpc-log">' + log.map(function (r) {
        var line = '<span><i class="' + (r.icon || 'fas fa-bolt') + '"></i> <b>' + esc2(r.actor) + '</b> ' + esc2(r.card) + (r.target ? ' → ' + esc2(r.target) : '');
        if (r.crit) line += ' <span class="rpc-critx">CRIT!</span>';
        if (r.hit && r.amount > 0) line += ' <span class="rpc-dmg">−' + r.amount + '</span>'; else if (!r.hit) line += ' <span class="rpc-miss">miss</span>';
        return line + '</span>';
      }).join('') + '</div>';
    }

    function render() {
      if (!enc) {
        host.innerHTML = userId ? '<div class="rpc"><div class="rpc-row"><input class="rpc-in grow" id="rpc-encname" placeholder="Encounter name (optional)"><button class="rpc-btn primary" id="rpc-create">⚔ Start an encounter</button></div></div>' : '';
        var cc = host.querySelector('#rpc-create');
        if (cc) cc.onclick = function () { var nm = (host.querySelector('#rpc-encname') || {}).value || ''; act(rpApi('/api/ext/rp/encounter', { method: 'POST', body: JSON.stringify({ topic: topicId, name: nm }) })); };
        return;
      }
      var h = '<div class="rpc"><div class="rpc-head"><span class="rpc-title"><i class="fas fa-dragon"></i> ' + esc2(enc.name || 'Encounter') + '</span>' +
        (enc.status === 'active' ? '<span class="rpc-round">Round ' + enc.round + '</span>' : '<span class="rpc-badge">' + esc2(enc.status) + '</span>') + '</div>' + outcome() +
        '<ul class="rpc-list">' + enc.combatants.map(cbRow).join('') + '</ul>';

      if (enc.status === 'setup' && userId) {
        var joinable = myChars.filter(function (mc) { return !enc.combatants.some(function (cb) { return cb.characterId === mc.id; }); });
        if (joinable.length) h += '<div class="rpc-controls"><div class="rpc-row"><select class="rpc-in grow" id="rpc-join">' + joinable.map(function (c) { return '<option value="' + c.id + '">' + esc2(c.name) + '</option>'; }).join('') + '</select><button class="rpc-btn" id="rpc-joinbtn">Join the fray</button></div></div>';
      }
      if (enc.isGm && enc.status === 'setup') {
        h += '<div class="rpc-controls"><div class="rpc-row"><input class="rpc-in grow" id="rpc-aname" placeholder="Combatant name" value="' + esc2(add.name) + '"><select class="rpc-in" id="rpc-ateam"><option value="foe">Foe</option><option value="party">Party</option></select></div>' +
          '<div class="rpc-row"><input class="rpc-in" id="rpc-ahp" type="number" min="1" placeholder="HP" value="' + esc2(add.hp) + '"><input class="rpc-in" id="rpc-adef" type="number" min="0" placeholder="Defense" value="' + esc2(add.defense) + '"><button class="rpc-btn" id="rpc-addbtn">Add</button></div>';
        var foeCards = (cards || []).filter(function (c) { return c.type === 'enemy' || c.hp != null; });
        if (foeCards.length) h += '<div class="rpc-row"><select class="rpc-in grow" id="rpc-spawn"><option value="">Spawn foe from card…</option>' + foeCards.map(function (c) { return '<option value="' + c.id + '">' + esc2(c.name) + '</option>'; }).join('') + '</select></div>';
        h += '<button class="rpc-btn primary block" id="rpc-start"' + (enc.combatants.length < 1 ? ' disabled' : '') + '>Roll initiative &amp; start</button></div>';
      }
      if (enc.status === 'active' && (myTurn() || enc.isGm)) {
        var a = activeCb(), targets = enc.combatants.filter(function (c) { return !c.isDown; });
        h += '<div class="rpc-play"><div style="font-weight:700;color:#7c3aed;margin-bottom:6px">Your move' + (a ? ' — ' + esc2(a.name) : '') + '</div><div class="rpc-row">' +
          '<select class="rpc-in grow" id="rpc-card"><option value="">Choose a card…</option>' + (cards || []).map(function (c) { return '<option value="' + c.id + '">' + esc2(c.name) + '</option>'; }).join('') + '</select>' +
          '<select class="rpc-in" id="rpc-target"><option value="">No target</option>' + targets.map(function (c) { return '<option value="' + c.id + '">' + esc2(c.name) + '</option>'; }).join('') + '</select><button class="rpc-btn primary" id="rpc-playbtn">Play</button></div></div>';
      }
      if (enc.isGm && enc.status === 'active') h += '<div class="rpc-controls"><div class="rpc-row"><button class="rpc-btn primary grow" id="rpc-next">Next turn</button><button class="rpc-btn rpc-end" id="rpc-end">End</button></div></div>';
      h += logRows() + '</div>';
      host.innerHTML = h;

      function on(id, fn) { var x = host.querySelector('#' + id); if (x) x.onclick = fn; }
      host.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { act(rpApi('/api/ext/rp/combatant/' + b.getAttribute('data-rm') + '/remove', { method: 'POST', body: '{}' })); }; });
      on('rpc-joinbtn', function () { act(rpApi('/api/ext/rp/encounter/' + enc.id + '/join', { method: 'POST', body: JSON.stringify({ characterId: host.querySelector('#rpc-join').value }) })); });
      on('rpc-addbtn', function () {
        add.name = host.querySelector('#rpc-aname').value; add.team = host.querySelector('#rpc-ateam').value; add.hp = host.querySelector('#rpc-ahp').value; add.defense = host.querySelector('#rpc-adef').value;
        if (!add.name.trim()) { alert('Name the combatant.'); return; }
        act(rpApi('/api/ext/rp/encounter/' + enc.id + '/combatant', { method: 'POST', body: JSON.stringify({ name: add.name, team: add.team, maxHp: add.hp, defense: add.defense }) })); add.name = '';
      });
      var sp = host.querySelector('#rpc-spawn'); if (sp) sp.onchange = function () { if (this.value) act(rpApi('/api/ext/rp/encounter/' + enc.id + '/combatant', { method: 'POST', body: JSON.stringify({ cardId: this.value, team: 'foe' }) })); };
      on('rpc-start', function () { act(rpApi('/api/ext/rp/encounter/' + enc.id + '/start', { method: 'POST', body: '{}' })); });
      on('rpc-next', function () { act(rpApi('/api/ext/rp/encounter/' + enc.id + '/next', { method: 'POST', body: '{}' })); });
      on('rpc-end', function () { if (confirm('End this encounter for everyone?')) act(rpApi('/api/ext/rp/encounter/' + enc.id + '/end', { method: 'POST', body: '{}' })); });
      on('rpc-playbtn', function () { var card = host.querySelector('#rpc-card').value; if (!card) { alert('Pick a card.'); return; } var a = activeCb(); act(rpApi('/api/ext/rp/encounter/' + enc.id + '/play', { method: 'POST', body: JSON.stringify({ cardId: card, actorCombatantId: a ? a.id : 0, targetCombatantId: host.querySelector('#rpc-target').value }) })); });
    }

    rpApi('/api/ext/rp/cards', {}).then(function (res) { cards = (res.j && res.j.data) || []; render(); });
    refetch();
  }

  var rpScan;
  function scheduleScan() { cancelAnimationFrame(rpScan); rpScan = requestAnimationFrame(function () { markRpPosts(); setupTopicRp(); setupCombat(); }); }
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
    mount: function (el, ctx) {
      var props = (ctx && ctx.props) || {};
      // Only inside a topic/category composer — never DMs, profile or edit.
      if (!props.topicId && !props.categoryId) return;

      var wrap = document.createElement('div');
      wrap.className = 'rp-as';
      wrap.style.display = 'none'; // hidden until we confirm a role-play context
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

      function reflect() { sel.classList.toggle('rp-as-on', !!sel.value); }

      getRpContext(props.topicId, props.categoryId)
        .then(function (d) {
          var chars = (d && d.rp && d.characters) || [];
          if (!chars.length) return; // regular topic or no characters → stay hidden
          chars.forEach(function (ch) {
            var o = document.createElement('option');
            o.value = String(ch.id);
            o.textContent = ch.name;
            sel.appendChild(o);
          });
          var active = getActive();
          if (active && sel.querySelector('option[value="' + active + '"]')) {
            sel.value = active;
          } else if (active) {
            setActive('');
          }
          reflect();
          wrap.style.display = '';
        });

      sel.addEventListener('change', function () { setActive(sel.value); reflect(); });

      return function () { if (el.contains(wrap)) el.removeChild(wrap); };
    },
  });

  var DICE_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">' +
    '<rect x="3" y="3" width="18" height="18" rx="4"/>' +
    '<circle cx="8.5" cy="8.5" r="1.3" fill="currentColor" stroke="none"/>' +
    '<circle cx="15.5" cy="8.5" r="1.3" fill="currentColor" stroke="none"/>' +
    '<circle cx="12" cy="12" r="1.3" fill="currentColor" stroke="none"/>' +
    '<circle cx="8.5" cy="15.5" r="1.3" fill="currentColor" stroke="none"/>' +
    '<circle cx="15.5" cy="15.5" r="1.3" fill="currentColor" stroke="none"/></svg>';

  // Dice button — builds an [[XdY+N]] token and drops it at the cursor, so the
  // server-side roller evaluates it on post. No need to remember the syntax.
  c.registerSlot('composer:toolbar', {
    ext: 'convoro-roleplay',
    order: 45,
    mount: function (el, ctx) {
      var props = (ctx && ctx.props) || {};
      var insert = props.insertText;
      if (typeof insert !== 'function') return;
      if (!props.topicId && !props.categoryId) return; // topic composers only

      var box = document.createElement('div');
      box.className = 'rp-dice';
      box.style.display = 'none'; // shown only in a role-play context
      getRpContext(props.topicId, props.categoryId).then(function (d) {
        if (d && d.rp) box.style.display = '';
      });
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rp-dice-btn';
      btn.title = 'Insert a dice roll';
      btn.setAttribute('aria-label', 'Insert a dice roll');
      btn.innerHTML = DICE_SVG;
      box.appendChild(btn);

      var pop = document.createElement('div');
      pop.className = 'rp-dice-pop';
      pop.style.display = 'none';
      pop.innerHTML =
        '<div class="rp-dice-row">' +
          '<input class="rp-dice-n" type="number" min="1" max="100" value="1" aria-label="Number of dice">' +
          '<select class="rp-dice-d" aria-label="Die">' +
            '<option>d4</option><option>d6</option><option>d8</option><option>d10</option>' +
            '<option>d12</option><option selected>d20</option><option>d100</option>' +
          '</select>' +
          '<input class="rp-dice-m" type="number" value="0" aria-label="Modifier" title="Modifier">' +
        '</div>' +
        '<div class="rp-dice-foot"><span class="rp-dice-prev">1d20</span>' +
        '<button type="button" class="rp-dice-go">Insert</button></div>';
      box.appendChild(pop);
      el.appendChild(box);

      var nEl = pop.querySelector('.rp-dice-n');
      var dEl = pop.querySelector('.rp-dice-d');
      var mEl = pop.querySelector('.rp-dice-m');
      var prev = pop.querySelector('.rp-dice-prev');

      function expr() {
        var n = Math.max(1, Math.min(100, parseInt(nEl.value, 10) || 1));
        var m = parseInt(mEl.value, 10) || 0;
        var s = n + dEl.value;
        if (m > 0) s += '+' + m; else if (m < 0) s += m;
        return s;
      }
      function refresh() { prev.textContent = expr(); }
      [nEl, dEl, mEl].forEach(function (x) { x.addEventListener('input', refresh); x.addEventListener('change', refresh); });
      refresh();

      function onDoc(ev) { if (!box.contains(ev.target)) closePop(); }
      function closePop() { pop.style.display = 'none'; document.removeEventListener('click', onDoc, true); }
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (pop.style.display !== 'none') { closePop(); return; }
        pop.style.display = '';
        refresh();
        setTimeout(function () { document.addEventListener('click', onDoc, true); }, 0);
      });
      pop.querySelector('.rp-dice-go').addEventListener('click', function (ev) {
        ev.preventDefault();
        insert('[[' + expr() + ']] ');
        closePop();
      });

      return function () { closePop(); if (el.contains(box)) el.removeChild(box); };
    },
  });
})();
