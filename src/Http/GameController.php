<?php

namespace Convoro\Ext\Roleplay\Http;

use App\Models\Topic;
use App\Models\User;
use App\Support\ReplyPoster;
use Convoro\Ext\Roleplay\Events\EncounterTouched;
use Convoro\Ext\Roleplay\Game;
use Convoro\Ext\Roleplay\Models\RpCard;
use Convoro\Ext\Roleplay\Models\RpCharacter;
use Convoro\Ext\Roleplay\Models\RpCombatant;
use Convoro\Ext\Roleplay\Models\RpEncounter;
use Convoro\Ext\Roleplay\Models\RpSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The tactical card game: card CRUD, combat sheets, and turn-based encounters run
 * inside a role-play topic. Every encounter action runs as the logged-in member
 * and is permission-gated (GM = encounter creator; play gated to the active turn).
 * Each change broadcasts EncounterTouched so trackers update live.
 */
class GameController
{
    private const TYPES = ['ability', 'item', 'spell', 'enemy'];

    // ── Cards ────────────────────────────────────────────────────────────
    public function listCards(): JsonResponse
    {
        $actor = Auth::user();
        $cards = RpCard::query()
            ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('is_public', true))
            ->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $cards->map(fn ($c) => $this->card($c, (int) $actor->id))->all()]);
    }

    public function saveCard(Request $request, ?int $card = null): JsonResponse
    {
        $actor = Auth::user();
        $model = $card ? RpCard::where('user_id', $actor->id)->find($card) : new RpCard;
        if ($card && ! $model) {
            return response()->json(['error' => 'Card not found.'], 404);
        }

        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['error' => 'A name is required.'], 422);
        }
        foreach (['attackExpr', 'damageExpr'] as $f) {
            $v = trim((string) $request->input($f));
            if ($v !== '' && Game::roll($v) === null) {
                return response()->json(['error' => 'Invalid dice formula: ' . $v . ' (try e.g. 1d20+3).'], 422);
            }
        }

        $type = (string) $request->input('type', 'ability');
        $model->fill([
            'user_id' => $actor->id,
            'name' => mb_substr($name, 0, 80),
            'icon' => trim((string) $request->input('icon')) ?: null,
            'type' => in_array($type, self::TYPES, true) ? $type : 'ability',
            'description' => trim((string) $request->input('description')) ?: null,
            'attack_expr' => trim((string) $request->input('attackExpr')) ?: null,
            'damage_expr' => trim((string) $request->input('damageExpr')) ?: null,
            'defense' => $request->filled('defense') ? max(0, (int) $request->input('defense')) : null,
            'hp' => $request->filled('hp') ? max(0, (int) $request->input('hp')) : null,
            'cost' => max(0, min(99, (int) $request->input('cost', 0))),
            'is_public' => $request->boolean('isPublic'),
        ]);
        $model->save();

        return response()->json(['data' => $this->card($model, (int) $actor->id)]);
    }

    public function deleteCard(int $card): JsonResponse
    {
        $actor = Auth::user();
        RpCard::where('user_id', $actor->id)->where('id', $card)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Combat sheets ────────────────────────────────────────────────────
    public function getSheet(int $character): JsonResponse
    {
        $actor = Auth::user();
        $char = RpCharacter::where('user_id', $actor->id)->find($character);
        if (! $char) {
            return response()->json(['error' => 'Character not found.'], 404);
        }
        $sheet = RpSheet::firstWhere('character_id', $character);

        return response()->json(['data' => $this->sheet($character, $sheet)]);
    }

    public function saveSheet(Request $request, int $character): JsonResponse
    {
        $actor = Auth::user();
        $char = RpCharacter::where('user_id', $actor->id)->find($character);
        if (! $char) {
            return response()->json(['error' => 'Character not found.'], 404);
        }
        $maxHp = max(1, min(9999, (int) $request->input('maxHp', 20)));
        $attrs = [];
        foreach (['might', 'agility', 'wits', 'heart'] as $a) {
            $attrs[$a] = max(0, min(99, (int) $request->input($a, 0)));
        }
        $sheet = RpSheet::firstOrNew(['character_id' => $character]);
        $sheet->max_hp = $maxHp;
        if (! $sheet->exists || $sheet->hp > $maxHp) {
            $sheet->hp = $maxHp;
        }
        $sheet->attributes = $attrs;
        $sheet->save();

        return response()->json(['data' => $this->sheet($character, $sheet)]);
    }

    // ── Encounters ───────────────────────────────────────────────────────
    public function showEncounter(Request $request): JsonResponse
    {
        $topicId = (int) $request->query('topic');
        $enc = RpEncounter::where('topic_id', $topicId)->where('status', '!=', 'ended')->latest('id')->first();

        return response()->json(['data' => $enc ? $this->encounter($enc, $this->actorId()) : null]);
    }

    public function createEncounter(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $topicId = (int) $request->input('topic');
        $topic = Topic::find($topicId);
        if (! $topic) {
            return response()->json(['error' => 'Topic not found.'], 404);
        }
        if (! $this->isRpTopic($topicId)) {
            return response()->json(['error' => 'Encounters can only run in role-play topics.'], 403);
        }

        $existing = RpEncounter::where('topic_id', $topicId)->where('status', '!=', 'ended')->first();
        if ($existing) {
            return response()->json(['data' => $this->encounter($existing, (int) $actor->id)]);
        }

        $enc = new RpEncounter;
        $enc->topic_id = $topicId;
        $enc->gm_user_id = $actor->id;
        $enc->name = mb_substr(trim((string) $request->input('name')), 0, 80) ?: null;
        $enc->status = 'setup';
        $enc->save();

        return $this->respond($enc, (int) $actor->id);
    }

    public function addCombatant(Request $request, int $enc): JsonResponse
    {
        [$encounter, $error] = $this->gmEncounter($enc);
        if ($error) {
            return $error;
        }

        $cardId = (int) $request->input('cardId');
        $card = $cardId ? RpCard::find($cardId) : null;
        $maxHp = $request->filled('maxHp') ? (int) $request->input('maxHp') : ($card && $card->hp ? (int) $card->hp : 10);
        $maxHp = max(1, min(9999, $maxHp));
        $defense = $request->filled('defense') ? (int) $request->input('defense') : ($card ? (int) $card->defense : 0);

        $c = new RpCombatant;
        $c->encounter_id = $encounter->id;
        $c->card_id = $cardId ?: null;
        $c->name = mb_substr(trim((string) $request->input('name')) ?: ($card->name ?? 'Foe'), 0, 80);
        $c->team = $request->input('team') === 'party' ? 'party' : 'foe';
        $c->max_hp = $maxHp;
        $c->hp = $maxHp;
        $c->meta = ['defense' => max(0, $defense), 'agility' => max(0, (int) $request->input('agility', 0))];
        $c->save();

        return $this->respond($encounter, (int) Auth::id());
    }

    public function removeCombatant(int $combatant): JsonResponse
    {
        $c = RpCombatant::find($combatant);
        if (! $c) {
            return response()->json(['ok' => true]);
        }
        [$encounter, $error] = $this->gmEncounter((int) $c->encounter_id);
        if ($error) {
            return $error;
        }
        $c->delete();

        return $this->respond($encounter, (int) Auth::id());
    }

    public function join(Request $request, int $enc): JsonResponse
    {
        $actor = Auth::user();
        $encounter = RpEncounter::find($enc);
        if (! $encounter) {
            return response()->json(['error' => 'Encounter not found.'], 404);
        }
        if ($encounter->status !== 'setup') {
            return response()->json(['error' => 'This encounter has already started.'], 422);
        }
        $character = RpCharacter::where('user_id', $actor->id)->where('status', 'approved')->find((int) $request->input('characterId'));
        if (! $character) {
            return response()->json(['error' => 'Pick one of your characters.'], 422);
        }

        $existing = RpCombatant::where('encounter_id', $enc)->where('character_id', $character->id)->first();
        if ($existing) {
            return $this->respond($encounter, (int) $actor->id);
        }

        $sheet = RpSheet::firstWhere('character_id', $character->id);
        $maxHp = $sheet ? (int) $sheet->max_hp : max(1, (int) $request->input('maxHp', 20));
        $agility = $sheet ? (int) ($sheet->attributes['agility'] ?? 0) : 0;

        $c = new RpCombatant;
        $c->encounter_id = $enc;
        $c->character_id = $character->id;
        $c->name = $character->name;
        $c->team = 'party';
        $c->max_hp = $maxHp;
        $c->hp = $maxHp;
        $c->meta = ['defense' => 0, 'agility' => $agility];
        $c->save();

        return $this->respond($encounter, (int) $actor->id);
    }

    public function play(Request $request, int $enc): JsonResponse
    {
        $actor = Auth::user();
        $encounter = RpEncounter::find($enc);
        if (! $encounter || $encounter->status !== 'active') {
            return response()->json(['error' => 'No active encounter.'], 422);
        }

        $actorC = RpCombatant::where('encounter_id', $enc)->find((int) $request->input('actorCombatantId'));
        if (! $actorC) {
            return response()->json(['error' => 'Unknown combatant.'], 422);
        }

        // Must be the GM, or control the active combatant on its turn.
        $isGm = (int) $encounter->gm_user_id === (int) $actor->id;
        $ownsActor = $actorC->character_id && RpCharacter::where('id', $actorC->character_id)->where('user_id', $actor->id)->exists();
        if (! $isGm && ! ($ownsActor && (int) $actorC->id === (int) Game::activeId($encounter))) {
            return response()->json(['error' => 'It is not your turn.'], 403);
        }

        $card = RpCard::where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('is_public', true))->find((int) $request->input('cardId'));
        if (! $card) {
            return response()->json(['error' => 'Card not found.'], 404);
        }
        $target = $request->filled('targetCombatantId') ? RpCombatant::where('encounter_id', $enc)->find((int) $request->input('targetCombatantId')) : null;

        $result = Game::play($actorC, $card, $target);
        $result = array_merge([
            'actor' => $actorC->name,
            'card' => $card->name,
            'icon' => $card->icon,
            'type' => $card->type,
            'target' => $target?->name,
        ], $result);

        $payload = $this->encounter($encounter, null);
        try {
            broadcast(new EncounterTouched((int) $encounter->topic_id, $payload));
        } catch (\Throwable) {
        }

        return response()->json(['data' => ['result' => $result, 'encounter' => $this->encounter($encounter, (int) $actor->id)]]);
    }

    public function start(int $enc): JsonResponse
    {
        [$encounter, $error] = $this->gmEncounter($enc);
        if ($error) {
            return $error;
        }
        if ($encounter->combatants()->count() < 1) {
            return response()->json(['error' => 'Add at least one combatant first.'], 422);
        }
        Game::start($encounter);

        return $this->respond($encounter, (int) Auth::id());
    }

    public function next(int $enc): JsonResponse
    {
        [$encounter, $error] = $this->gmEncounter($enc);
        if ($error) {
            return $error;
        }
        if ($encounter->status !== 'active') {
            return response()->json(['error' => 'The encounter is not active.'], 422);
        }
        Game::nextTurn($encounter);

        return $this->respond($encounter, (int) Auth::id());
    }

    public function end(int $enc): JsonResponse
    {
        [$encounter, $error] = $this->gmEncounter($enc);
        if ($error) {
            return $error;
        }
        $encounter->status = 'ended';
        $encounter->save();
        $this->postRecap($encounter, Auth::user());

        return $this->respond($encounter, (int) Auth::id());
    }

    // ── Deck builder page (server-rendered in the forum shell) ────────────
    public function deckPage()
    {
        $body = <<<'HTML'
<div class="rpd-wrap">
  <h1 class="rpd-h1"><i class="fas fa-layer-group"></i> My deck</h1>
  <p class="rpd-sub">Build the cards your characters use in tactical encounters — abilities, items, spells and enemies, each with dice formulas the game engine rolls.</p>

  <div class="rpd-card rpd-form">
    <h3 id="rpd-form-title">New card</h3>
    <div class="rpd-row">
      <input id="f-name" class="rpd-in rpd-grow" placeholder="Card name" maxlength="80">
      <select id="f-type" class="rpd-in">
        <option value="ability">Ability</option><option value="item">Item</option>
        <option value="spell">Spell</option><option value="enemy">Enemy</option>
      </select>
    </div>
    <div class="rpd-row">
      <label class="rpd-field">Icon (Font Awesome class)
        <span class="rpd-iconrow"><span class="rpd-iconprev"><i id="f-iconprev" class="fas fa-bolt"></i></span><input id="f-icon" class="rpd-in" placeholder="fas fa-bolt" value="fas fa-bolt"></span>
      </label>
      <label class="rpd-field">Cost<input id="f-cost" class="rpd-in" type="number" min="0" max="99" value="0"></label>
    </div>
    <div class="rpd-row">
      <label class="rpd-field">Attack roll<input id="f-attack" class="rpd-in" placeholder="1d20+3"></label>
      <label class="rpd-field">Damage roll<input id="f-damage" class="rpd-in" placeholder="2d6+1"></label>
    </div>
    <div class="rpd-row">
      <label class="rpd-field">Defense<input id="f-defense" class="rpd-in" type="number" min="0"></label>
      <label class="rpd-field">HP<input id="f-hp" class="rpd-in" type="number" min="0"></label>
    </div>
    <textarea id="f-desc" class="rpd-in" rows="2" placeholder="What does this card do? (optional)"></textarea>
    <label class="rpd-check"><input type="checkbox" id="f-public"> Share this card into everyone&rsquo;s deck</label>
    <div class="rpd-actions">
      <button id="f-save" class="rpd-btn rpd-btn-primary">Create card</button>
      <button id="f-cancel" class="rpd-btn" style="display:none">Cancel</button>
    </div>
  </div>

  <div id="rpd-cards"></div>

  <div class="rpd-card">
    <h3><i class="fas fa-shield-halved"></i> Combat sheets</h3>
    <p class="rpd-sub">Set a character&rsquo;s HP and attributes for encounters.</p>
    <div class="rpd-row"><select id="s-char" class="rpd-in rpd-grow"><option value="">Choose a character&hellip;</option></select></div>
    <div id="s-fields" style="display:none">
      <div class="rpd-row">
        <label class="rpd-field">Max HP<input id="s-hp" class="rpd-in" type="number" min="1" value="20"></label>
        <label class="rpd-field">Might<input id="s-might" class="rpd-in" type="number" min="0" value="0"></label>
      </div>
      <div class="rpd-row">
        <label class="rpd-field">Agility<input id="s-agility" class="rpd-in" type="number" min="0" value="0"></label>
        <label class="rpd-field">Wits<input id="s-wits" class="rpd-in" type="number" min="0" value="0"></label>
      </div>
      <div class="rpd-row">
        <label class="rpd-field">Heart<input id="s-heart" class="rpd-in" type="number" min="0" value="0"></label>
        <span class="rpd-field"></span>
      </div>
      <div class="rpd-actions"><button id="s-save" class="rpd-btn rpd-btn-primary">Save sheet</button></div>
    </div>
  </div>
</div>
HTML;

        $css = <<<'CSS'
.rpd-wrap { max-width: 880px; margin: 0 auto; }
.rpd-h1 { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
.rpd-h1 .fas { color: #7c3aed; }
.rpd-sub { color: var(--ink-muted, #6b7280); margin: 0 0 18px; }
.rpd-card { background: var(--surface, #fff); border: 1px solid var(--line, #e6e8f0); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; }
.rpd-card h3 { margin: 0 0 12px; font-size: 16px; font-weight: 700; }
.rpd-row { display: flex; gap: 12px; margin-bottom: 12px; }
.rpd-field { flex: 1; display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; }
.rpd-grow { flex: 1; }
.rpd-in { width: 100%; padding: 9px 11px; border: 1px solid var(--line, #e6e8f0); border-radius: 9px; font-size: 14px; background: var(--appbg, #f7f8fb); color: inherit; }
textarea.rpd-in { resize: vertical; }
.rpd-iconrow { display: flex; gap: 8px; align-items: center; }
.rpd-iconprev { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: rgba(124,58,237,0.1); color: #7c3aed; font-size: 18px; }
.rpd-check { display: flex; align-items: center; gap: 8px; font-size: 14px; margin: 4px 0 12px; }
.rpd-actions { display: flex; gap: 10px; }
.rpd-btn { border: 1px solid var(--line, #e6e8f0); background: transparent; border-radius: 9px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; color: inherit; }
.rpd-btn-primary { background: #7c3aed; color: #fff; border-color: #7c3aed; }
.rpd-heading { margin: 18px 0 10px; font-size: 14px; font-weight: 700; color: var(--ink-muted, #6b7280); }
.rpd-empty { color: var(--ink-muted, #6b7280); }
.rpd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.rpd-tile { background: var(--surface, #fff); border: 1px solid var(--line, #e6e8f0); border-top: 3px solid #7c3aed; border-radius: 12px; padding: 12px; }
.rpd-tile-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.rpd-tile-icon { font-size: 20px; }
.rpd-cost { font-size: 12px; font-weight: 800; background: rgba(0,0,0,0.06); border-radius: 999px; padding: 1px 8px; }
.rpd-scope { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; padding: 2px 7px; border-radius: 999px; }
.rpd-scope .fas { font-size: 9px; }
.rpd-shared { background: rgba(37,99,235,0.14); color: #2563eb; }
.rpd-private { background: rgba(0,0,0,0.07); color: var(--ink-muted, #6b7280); }
.rpd-tile-name { font-weight: 700; margin-top: 8px; }
.rpd-tile-type { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; font-weight: 700; }
.rpd-tile-desc { font-size: 12px; color: var(--ink-muted, #6b7280); margin-top: 6px; line-height: 1.4; }
.rpd-tile-stats { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; font-size: 12px; }
.rpd-stat { color: var(--ink-muted, #6b7280); }
.rpd-tile-actions { display: flex; gap: 6px; margin-top: 10px; }
.rpd-ic { border: 1px solid var(--line, #e6e8f0); background: transparent; border-radius: 8px; padding: 5px 9px; cursor: pointer; color: inherit; }
CSS;

        $js = <<<'JS'
var TYPE = { ability: '#7c3aed', item: '#2563eb', spell: '#db2777', enemy: '#dc2626' };
var editId = 0;
function el(id) { return document.getElementById(id); }
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function api(url, opts) { return fetch(url, Object.assign({ headers: H, credentials: 'same-origin' }, opts || {})).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }); }

el('f-icon').addEventListener('input', function () { el('f-iconprev').className = (this.value.trim() || 'fas fa-bolt'); });

function stat(icon, v) { return (v !== null && v !== '' && v !== undefined) ? '<span class="rpd-stat"><i class="' + icon + '"></i> ' + esc(v) + '</span>' : ''; }
function tile(c) {
  var color = TYPE[c.type] || '#7c3aed';
  var scope = c.isPublic ? '<span class="rpd-scope rpd-shared"><i class="fas fa-globe"></i> Shared</span>' : '<span class="rpd-scope rpd-private"><i class="fas fa-lock"></i> My deck</span>';
  var actions = c.mine ? '<div class="rpd-tile-actions"><button class="rpd-ic" data-edit="' + c.id + '"><i class="fas fa-pen"></i></button><button class="rpd-ic" data-del="' + c.id + '"><i class="fas fa-trash"></i></button></div>' : '';
  return '<div class="rpd-tile" style="border-top-color:' + color + '"><div class="rpd-tile-head"><i class="rpd-tile-icon ' + (c.icon || 'fas fa-bolt') + '" style="color:' + color + '"></i>' + scope + (c.cost ? '<span class="rpd-cost">' + c.cost + '</span>' : '') + '</div><div class="rpd-tile-name">' + esc(c.name) + '</div><div class="rpd-tile-type" style="color:' + color + '">' + esc(c.type) + '</div>' + (c.description ? '<div class="rpd-tile-desc">' + esc(c.description) + '</div>' : '') + '<div class="rpd-tile-stats">' + stat('fas fa-dice-d20', c.attackExpr) + stat('fas fa-burst', c.damageExpr) + stat('fas fa-shield', c.defense) + stat('fas fa-heart', c.hp) + '</div>' + actions + '</div>';
}
var ALL = [];
function renderCards(cards) {
  ALL = cards;
  var mine = cards.filter(function (c) { return c.mine; });
  var shared = cards.filter(function (c) { return !c.mine; });
  var html = mine.length ? '<div class="rpd-grid">' + mine.map(tile).join('') + '</div>' : '<p class="rpd-empty">You haven’t built any cards yet.</p>';
  if (shared.length) html += '<h3 class="rpd-heading">Shared cards</h3><div class="rpd-grid">' + shared.map(tile).join('') + '</div>';
  var box = el('rpd-cards'); box.innerHTML = html;
  box.querySelectorAll('[data-edit]').forEach(function (b) { b.onclick = function () { editCard(ALL.find(function (c) { return c.id == b.getAttribute('data-edit'); })); }; });
  box.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { delCard(b.getAttribute('data-del')); }; });
}
function loadCards() { fetch('/api/ext/rp/cards', { headers: H }).then(function (r) { return r.json(); }).then(function (res) { renderCards(res.data || []); }); }
function editCard(c) {
  if (!c) return; editId = c.id;
  el('rpd-form-title').textContent = 'Edit card'; el('f-name').value = c.name; el('f-type').value = c.type;
  el('f-icon').value = c.icon || 'fas fa-bolt'; el('f-iconprev').className = c.icon || 'fas fa-bolt';
  el('f-cost').value = c.cost || 0; el('f-attack').value = c.attackExpr || ''; el('f-damage').value = c.damageExpr || '';
  el('f-defense').value = c.defense != null ? c.defense : ''; el('f-hp').value = c.hp != null ? c.hp : '';
  el('f-desc').value = c.description || ''; el('f-public').checked = !!c.isPublic;
  el('f-save').textContent = 'Save'; el('f-cancel').style.display = ''; window.scrollTo({ top: 0, behavior: 'smooth' });
}
function resetForm() {
  editId = 0; el('rpd-form-title').textContent = 'New card';
  ['f-name', 'f-attack', 'f-damage', 'f-defense', 'f-hp', 'f-desc'].forEach(function (i) { el(i).value = ''; });
  el('f-type').value = 'ability'; el('f-icon').value = 'fas fa-bolt'; el('f-iconprev').className = 'fas fa-bolt';
  el('f-cost').value = '0'; el('f-public').checked = false; el('f-save').textContent = 'Create card'; el('f-cancel').style.display = 'none';
}
el('f-cancel').onclick = resetForm;
el('f-save').onclick = function () {
  var name = el('f-name').value.trim(); if (!name) { alert('A name is required.'); return; }
  var payload = { name: name, type: el('f-type').value, icon: el('f-icon').value.trim(), cost: el('f-cost').value || 0, attackExpr: el('f-attack').value.trim(), damageExpr: el('f-damage').value.trim(), defense: el('f-defense').value, hp: el('f-hp').value, description: el('f-desc').value, isPublic: el('f-public').checked };
  api('/api/ext/rp/cards' + (editId ? '/' + editId : ''), { method: 'POST', body: JSON.stringify(payload) }).then(function (res) { if (!res.ok) { alert((res.j && res.j.error) || 'Could not save the card.'); return; } resetForm(); loadCards(); });
};
function delCard(id) { if (!confirm('Delete this card? This can’t be undone.')) return; api('/api/ext/rp/cards/' + id + '/delete', { method: 'POST' }).then(loadCards); }

// Combat sheets
function loadChars() {
  fetch('/api/ext/rp/me', { headers: H }).then(function (r) { return r.json(); }).then(function (res) {
    (res.characters || []).forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; el('s-char').appendChild(o); });
  });
}
el('s-char').onchange = function () {
  var id = this.value; if (!id) { el('s-fields').style.display = 'none'; return; }
  fetch('/api/ext/rp/sheet/' + id, { headers: H }).then(function (r) { return r.json(); }).then(function (res) {
    var s = res.data || {}; var a = s.attributes || {};
    el('s-hp').value = s.maxHp || 20; el('s-might').value = a.might || 0; el('s-agility').value = a.agility || 0; el('s-wits').value = a.wits || 0; el('s-heart').value = a.heart || 0;
    el('s-fields').style.display = '';
  });
};
el('s-save').onclick = function () {
  var id = el('s-char').value; if (!id) return;
  var payload = { maxHp: el('s-hp').value, might: el('s-might').value, agility: el('s-agility').value, wits: el('s-wits').value, heart: el('s-heart').value };
  api('/api/ext/rp/sheet/' + id, { method: 'POST', body: JSON.stringify(payload) }).then(function (res) { if (res.ok) { el('s-save').textContent = 'Saved ✓'; setTimeout(function () { el('s-save').textContent = 'Save sheet'; }, 1500); } });
};

loadCards(); loadChars();
JS;

        return \App\Support\ExtPage::render('My deck', $body, $css, $js);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function actorId(): ?int
    {
        return Auth::id() ? (int) Auth::id() : null;
    }

    /** @return array{0: ?RpEncounter, 1: ?JsonResponse} */
    private function gmEncounter(int $enc): array
    {
        $encounter = RpEncounter::find($enc);
        if (! $encounter) {
            return [null, response()->json(['error' => 'Encounter not found.'], 404)];
        }
        if ((int) $encounter->gm_user_id !== (int) Auth::id()) {
            return [null, response()->json(['error' => 'Only the storyteller can do that.'], 403)];
        }

        return [$encounter, null];
    }

    private function respond(RpEncounter $enc, ?int $actorId): JsonResponse
    {
        try {
            broadcast(new EncounterTouched((int) $enc->topic_id, $this->encounter($enc, null)));
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->encounter($enc, $actorId)]);
    }

    private function isRpTopic(int $topicId): bool
    {
        $override = DB::table('rp_topics')->where('topic_id', $topicId)->value('is_rp');
        if ($override !== null) {
            return (bool) $override;
        }
        $catId = DB::table('topics')->where('id', $topicId)->value('category_id');
        if ($catId === null) {
            return false;
        }

        return DB::table('rp_ic_categories')->where('category_id', (int) $catId)->exists();
    }

    private function postRecap(RpEncounter $enc, User $actor): void
    {
        try {
            $combatants = $enc->combatants()->with('character')->orderByDesc('initiative')->get();
            $standing = [];
            $down = [];
            foreach ($combatants as $c) {
                $name = $c->character->name ?? $c->name;
                if ($c->is_down || (int) $c->hp <= 0) {
                    $down[] = $name;
                } else {
                    $standing[] = $name . ' (' . max(0, (int) $c->hp) . '/' . (int) $c->max_hp . ' HP)';
                }
            }
            $title = $enc->name ?: 'The encounter';
            $rounds = (int) $enc->round;
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
            $html = '<p>⚔️ ' . $e($title) . ' has ended' . ($rounds > 0 ? ' after ' . $rounds . ' round' . ($rounds === 1 ? '' : 's') : '') . '.</p>';
            if ($standing) {
                $html .= '<p><strong>Still standing:</strong> ' . $e(implode(', ', $standing)) . '</p>';
            }
            if ($down) {
                $html .= '<p><strong>Defeated:</strong> ' . $e(implode(', ', $down)) . '</p>';
            }

            $topic = Topic::find($enc->topic_id);
            if ($topic) {
                ReplyPoster::create($actor, $topic, $html);
            }
        } catch (\Throwable) {
            // non-fatal — ending the encounter must still succeed
        }
    }

    private function card(RpCard $c, int $actorId): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'icon' => $c->icon, 'type' => $c->type,
            'description' => $c->description, 'attackExpr' => $c->attack_expr, 'damageExpr' => $c->damage_expr,
            'defense' => $c->defense !== null ? (int) $c->defense : null,
            'hp' => $c->hp !== null ? (int) $c->hp : null,
            'cost' => (int) $c->cost, 'isPublic' => (bool) $c->is_public,
            'mine' => (int) $c->user_id === $actorId,
        ];
    }

    private function sheet(int $characterId, ?RpSheet $s): array
    {
        return [
            'characterId' => $characterId,
            'maxHp' => $s ? (int) $s->max_hp : 20,
            'hp' => $s ? (int) $s->hp : 20,
            'attributes' => $s && $s->attributes ? $s->attributes : ['might' => 0, 'agility' => 0, 'wits' => 0, 'heart' => 0],
        ];
    }

    private function combatant(RpCombatant $c): array
    {
        $char = $c->character;

        return [
            'id' => (int) $c->id, 'name' => $c->name, 'team' => $c->team,
            'maxHp' => (int) $c->max_hp, 'hp' => (int) $c->hp, 'initiative' => (int) $c->initiative,
            'isDown' => (bool) $c->is_down,
            'characterId' => $c->character_id ? (int) $c->character_id : null,
            'cardId' => $c->card_id ? (int) $c->card_id : null,
            'meta' => $c->meta ?: (object) [],
            'character' => $char ? ['name' => $char->name, 'color' => $char->color, 'avatarUrl' => $char->avatar_path, 'userId' => (int) $char->user_id] : null,
        ];
    }

    private function encounter(RpEncounter $enc, ?int $actorId): array
    {
        $combatants = $enc->combatants()->with('character')->orderByDesc('initiative')->orderBy('id')->get();

        return [
            'id' => (int) $enc->id, 'topicId' => (int) $enc->topic_id, 'gmUserId' => (int) $enc->gm_user_id,
            'isGm' => $actorId !== null && $actorId === (int) $enc->gm_user_id,
            'name' => $enc->name, 'status' => $enc->status,
            'round' => (int) $enc->round, 'turnIndex' => (int) $enc->turn_index,
            'order' => $enc->order ?: [], 'activeId' => Game::activeId($enc),
            'combatants' => $combatants->map(fn ($c) => $this->combatant($c))->values()->all(),
        ];
    }
}
