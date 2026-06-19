<?php

namespace Convoro\Ext\Roleplay;

use App\Models\Post;
use App\Support\ExtensionManager;
use App\Support\ExtPage;
use App\Support\PostIdentity;
use Convoro\Ext\Roleplay\Models\RpCharacter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Role-Play — first-party Convoro extension.
 *
 * Members create characters and post AS them: posts in in-character boards show
 * the character's name/avatar instead of the account, via the core PostIdentity
 * hook. This file is the spine (characters + post-as-character); applications,
 * claims, sheets/dice, trackers and the shoutbox build on top.
 */
class Extension extends ServiceProvider
{
    /** Request-scoped cache of post_id => character identity (avoids N+1). */
    private static array $identityCache = [];

    public function boot(): void
    {
        $this->registerIdentityResolver();
        $this->registerPostCapture();
        $this->registerRoutes();
    }

    /**
     * When a post is created while an "active character" is selected (the composer
     * sets an `rp_as` cookie), link the post to that character. Eloquent's created
     * event means no core change is needed; raw $_COOKIE read sidesteps Laravel's
     * cookie encryption for this non-sensitive, ownership-validated id.
     */
    private function registerPostCapture(): void
    {
        Post::created(function (Post $post) {
            $raw = $_COOKIE['rp_as'] ?? null;
            if (! $raw) {
                return;
            }
            if (! self::isIcPost($post)) {
                return; // out-of-character board — keep the real account
            }
            $c = RpCharacter::where('id', (int) $raw)
                ->where('user_id', $post->user_id)
                ->where('status', 'approved')
                ->first();
            if (! $c) {
                return;
            }
            DB::table('rp_post_character')->updateOrInsert(
                ['post_id' => $post->id],
                ['character_id' => $c->id, 'created_at' => now()]
            );
            $c->increment('post_count');
            $c->forceFill(['last_active_at' => now()])->save();
        });
    }

    /** The category ids flagged in-character (empty = every board is IC). */
    private static function icCategoryIds(): array
    {
        return DB::table('rp_ic_categories')->pluck('category_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Is this post in an in-character board? (No IC boards configured = all are.) */
    private static function isIcPost(Post $post): bool
    {
        $ic = self::icCategoryIds();
        if ($ic === []) {
            return true;
        }
        $catId = DB::table('topics')->where('id', $post->topic_id)->value('category_id');

        return $catId !== null && in_array((int) $catId, $ic, true);
    }

    /** Does the current user hold the role-play moderation permission? */
    private static function canModerate(): bool
    {
        return (bool) Auth::user()?->hasPermission('roleplay.moderate');
    }

    /** Render a post's author as its role-play character, when one is set. */
    private function registerIdentityResolver(): void
    {
        PostIdentity::extend(function (Post $post, array $author): ?array {
            if (array_key_exists($post->id, self::$identityCache)) {
                return self::$identityCache[$post->id];
            }

            $charId = DB::table('rp_post_character')->where('post_id', $post->id)->value('character_id');
            $identity = null;
            if ($charId && ($c = RpCharacter::find($charId))) {
                $identity = self::identity($c, (int) $post->user_id);
            }

            return self::$identityCache[$post->id] = $identity;
        });
    }

    /** The author shape Present::avatar produces, but for a character. */
    private static function identity(RpCharacter $c, int $ownerId): array
    {
        return [
            'id' => $ownerId,                       // post is still owned by the account
            'name' => $c->name,
            'handle' => $c->slug,
            'initials' => $c->initials(),
            'color' => $c->color ?: (($c->id % 6) + 1),
            'avatar' => $c->avatar_path ?: null,
            'url' => '/characters/'.$c->slug,
            'online' => false,
            'staff' => null,
            'fedi' => null,
            'fediUrl' => null,
            'character' => true,                    // marker for any character-specific styling
        ];
    }

    private function registerRoutes(): void
    {
        // The current user's characters — feeds the composer "post as" selector.
        Route::middleware(['web', 'auth'])->get('/api/ext/rp/me', function () {
            $rows = RpCharacter::where('user_id', Auth::id())
                ->where('status', 'approved')
                ->orderBy('name')
                ->get();

            return response()->json([
                'characters' => $rows->map(fn (RpCharacter $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'initials' => $c->initials(),
                    'color' => $c->color ?: (($c->id % 6) + 1),
                    'avatar' => $c->avatar_path,
                ])->all(),
            ]);
        });

        Route::middleware(['web', 'auth'])->group(function () {
            // Create a character. Auto-approved unless staff require review.
            Route::post('/api/ext/rp/characters', function (Request $request) {
                $data = $request->validate([
                    'name' => 'required|string|max:80',
                    'bio' => 'nullable|string|max:5000',
                    'claim' => 'nullable|string|max:120',
                ]);

                // Enforce the per-member cap (0 = unlimited).
                $max = (int) ExtensionManager::setting('convoro-roleplay', 'max_per_user', 3);
                if ($max > 0 && RpCharacter::where('user_id', Auth::id())->count() >= $max) {
                    return response()->json([
                        'message' => "You can only have {$max} characters.",
                    ], 422);
                }

                $auto = (bool) ExtensionManager::setting('convoro-roleplay', 'auto_approve', true);
                $slug = self::uniqueSlug($data['name']);
                $c = RpCharacter::create([
                    'user_id' => Auth::id(),
                    'name' => $data['name'],
                    'slug' => $slug,
                    'bio' => $data['bio'] ?? null,
                    'claim' => $data['claim'] ?? null,
                    'status' => $auto ? 'approved' : 'pending',
                ]);

                return response()->json([
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'status' => $c->status,
                    'pending' => $c->status === 'pending',
                ]);
            });

            // Set the character a post was authored as (the composer calls this
            // after a reply is created; only the post's own author may set it).
            Route::post('/api/ext/rp/post/{post}/character', function (Request $request, int $post) {
                $data = $request->validate(['character_id' => 'nullable|integer']);
                $p = DB::table('posts')->where('id', $post)->first(['id', 'user_id']);
                abort_unless($p && (int) $p->user_id === (int) Auth::id(), 403);

                if (empty($data['character_id'])) {
                    DB::table('rp_post_character')->where('post_id', $post)->delete();

                    return response()->json(['ok' => true, 'character_id' => null]);
                }

                $c = RpCharacter::where('id', $data['character_id'])->where('user_id', Auth::id())->first();
                abort_unless($c, 422);

                DB::table('rp_post_character')->updateOrInsert(
                    ['post_id' => $post],
                    ['character_id' => $c->id, 'created_at' => now()]
                );
                $c->increment('post_count');
                $c->update(['last_active_at' => now()]);

                return response()->json(['ok' => true, 'character_id' => $c->id]);
            });
        });

        // --- Staff: approval queue + in-character board flags ----------------
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/rp/staff', function () {
                abort_unless(self::canModerate(), 403);

                return self::staffPage();
            });

            Route::post('/api/ext/rp/staff/character/{character}/approve', function (int $character) {
                abort_unless(self::canModerate(), 403);
                RpCharacter::where('id', $character)->update(['status' => 'approved']);

                return response()->json(['ok' => true]);
            });

            Route::post('/api/ext/rp/staff/character/{character}/reject', function (int $character) {
                abort_unless(self::canModerate(), 403);
                RpCharacter::where('id', $character)->update(['status' => 'archived']);

                return response()->json(['ok' => true]);
            });

            Route::post('/api/ext/rp/staff/ic-categories', function (Request $request) {
                abort_unless(self::canModerate(), 403);
                $ids = collect($request->input('category_ids', []))
                    ->map(fn ($v) => (int) $v)
                    ->filter()
                    ->unique()
                    ->values();
                DB::table('rp_ic_categories')->truncate();
                if ($ids->isNotEmpty()) {
                    DB::table('rp_ic_categories')->insert($ids->map(fn ($id) => ['category_id' => $id])->all());
                }

                return response()->json(['ok' => true, 'count' => $ids->count()]);
            });
        });

        // Public character directory + claims (rendered in the real forum shell).
        Route::middleware('web')->get('/characters', fn () => self::directoryPage());

        // Public character profile page (rendered inside the real forum shell).
        Route::middleware('web')->get('/characters/{slug}', function (string $slug) {
            $c = RpCharacter::where('slug', $slug)->first();
            abort_unless($c, 404);

            return self::profilePage($c);
        });
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'character';
        $slug = $base;
        $n = 2;
        while (RpCharacter::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private static function profilePage(RpCharacter $c)
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $avatar = $c->avatar_path
            ? '<img src="'.$e($c->avatar_path).'" alt="" style="width:96px;height:96px;border-radius:var(--c-avatar-radius);object-fit:cover">'
            : '<div class="rp-mono av-g'.($c->color ?: (($c->id % 6) + 1)).'" style="width:96px;height:96px;border-radius:var(--c-avatar-radius);display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:600">'.$e($c->initials()).'</div>';

        $bio = $c->bio ? '<p class="rp-bio">'.nl2br($e($c->bio)).'</p>' : '<p class="rp-muted">No bio yet.</p>';

        $claimLine = $c->claim ? '<div class="rp-claim">'.$e($c->claim).'</div>' : '';

        // "Played by" — transparency line, toggled by the reveal_account setting.
        $playedBy = '';
        if (ExtensionManager::setting('convoro-roleplay', 'reveal_account', true)) {
            $owner = \App\Models\User::find($c->user_id);
            if ($owner) {
                $oname = \App\Support\Username::display($owner->name, $owner->id);
                $playedBy = '<div class="rp-muted rp-played">Played by <a href="/u/'.$owner->id.'">'.$e($oname).'</a></div>';
            }
        }

        // Thread tracker — topics this character has appeared in, most recent first.
        $threads = DB::table('rp_post_character as rpc')
            ->join('posts as p', 'p.id', '=', 'rpc.post_id')
            ->join('topics as t', 't.id', '=', 'p.topic_id')
            ->where('rpc.character_id', $c->id)
            ->where('t.hidden', false)
            ->groupBy('t.id', 't.title', 't.slug')
            ->orderByRaw('MAX(p.created_at) DESC')
            ->limit(20)
            ->get(['t.id', 't.title', 't.slug', DB::raw('COUNT(*) as posts')]);

        $tracker = '';
        if ($threads->isNotEmpty()) {
            $items = '';
            foreach ($threads as $t) {
                $items .= '<a class="rp-thread" href="/t/'.$e($t->slug).'">'
                    .'<span class="rp-thread-title">'.$e($t->title).'</span>'
                    .'<span class="rp-thread-count">'.(int) $t->posts.'</span></a>';
            }
            $tracker = '<section class="rp-tracker"><h2 class="rp-tr-h">Threads</h2>'.$items.'</section>';
        }

        $body = <<<HTML
        <div class="rp-profile">
          <div class="rp-head">
            {$avatar}
            <div>
              <h1 class="rp-name">{$e($c->name)}</h1>
              <div class="rp-muted">{$c->post_count} posts</div>
              {$claimLine}
              {$playedBy}
            </div>
          </div>
          {$bio}
          {$tracker}
        </div>
        HTML;

        $css = <<<CSS
        .rp-profile{max-width:760px;margin:0 auto;padding:24px 16px}
        .rp-head{display:flex;gap:18px;align-items:center;margin-bottom:18px}
        .rp-name{font-size:26px;font-weight:700;margin:0;color:rgb(var(--c-text))}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-bio{color:rgb(var(--c-text-2));line-height:1.7;margin:0}
        .rp-claim{margin-top:4px;font-size:13px;color:rgb(var(--c-text-2))}
        .rp-claim::before{content:"Claim: ";color:rgb(var(--c-muted))}
        .rp-played{margin-top:3px}
        .rp-played a{color:rgb(var(--c-primary));text-decoration:none}
        .rp-played a:hover{text-decoration:underline}
        .rp-tracker{margin-top:26px}
        .rp-tr-h{font-size:15px;font-weight:700;margin:0 0 10px;color:rgb(var(--c-text))}
        .rp-thread{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;
          border:1px solid rgb(var(--c-border));border-radius:var(--c-radius,8px);background:rgb(var(--c-surface));
          text-decoration:none;margin-bottom:6px}
        .rp-thread:hover{border-color:rgb(var(--c-primary))}
        .rp-thread-title{color:rgb(var(--c-text));font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .rp-thread-count{flex-shrink:0;font-size:12px;color:rgb(var(--c-muted));background:rgb(var(--c-surface-2));
          padding:2px 8px;border-radius:999px}
        .rp-mono{color:#fff}
        CSS;

        return ExtPage::render($c->name, $body, $css);
    }

    /**
     * Public directory of approved characters with their claims. Duplicate claims
     * (two characters sharing a face/canon) are flagged so staff and members can
     * spot collisions.
     */
    private static function directoryPage()
    {
        $reveal = (bool) ExtensionManager::setting('convoro-roleplay', 'reveal_account', true);
        $chars = RpCharacter::where('status', 'approved')->orderBy('name')->get();

        // Which normalised claims are used by more than one character?
        $dupes = $chars->filter(fn ($c) => filled($c->claim))
            ->groupBy(fn ($c) => Str::lower(trim($c->claim)))
            ->filter(fn ($g) => $g->count() > 1)
            ->keys()->all();
        $dupes = array_flip($dupes);

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $owners = $reveal ? \App\Models\User::whereIn('id', $chars->pluck('user_id')->unique())->get()->keyBy('id') : collect();

        $cards = '';
        foreach ($chars as $c) {
            $av = $c->avatar_path
                ? '<img src="'.$e($c->avatar_path).'" alt="">'
                : '<span class="rp-mono av-g'.($c->color ?: (($c->id % 6) + 1)).'">'.$e($c->initials()).'</span>';

            $claim = '';
            if (filled($c->claim)) {
                $dup = isset($dupes[Str::lower(trim($c->claim))]) ? ' rp-dup' : '';
                $title = $dup ? ' title="This claim is used by more than one character"' : '';
                $claim = '<div class="rp-d-claim'.$dup.'"'.$title.'>'.$e($c->claim).($dup ? ' ⚠' : '').'</div>';
            }

            $by = '';
            if ($reveal && ($o = $owners->get($c->user_id))) {
                $by = '<div class="rp-d-by">'.$e(\App\Support\Username::display($o->name, $o->id)).'</div>';
            }

            $active = $c->last_active_at ? $c->last_active_at->getTimestamp() : 0;
            $activeLabel = $c->last_active_at ? 'active '.$c->last_active_at->diffForHumans() : 'no posts yet';
            $cards .= '<a class="rp-card" href="/characters/'.$e($c->slug).'"'
                .' data-name="'.$e(Str::lower($c->name)).'"'
                .' data-claim="'.$e(Str::lower((string) $c->claim)).'"'
                .' data-posts="'.(int) $c->post_count.'" data-active="'.$active.'">'
                .'<div class="rp-d-av">'.$av.'</div>'
                .'<div class="rp-d-meta"><div class="rp-d-name">'.$e($c->name).'</div>'.$claim.$by
                .'<div class="rp-d-stat">'.(int) $c->post_count.' posts · '.$e($activeLabel).'</div>'
                .'</div></a>';
        }

        if ($cards === '') {
            $cards = '<p class="rp-muted">No characters yet.</p>';
        }

        $manage = self::canModerate()
            ? '<a class="rp-btn rp-ok rp-manage" href="/rp/staff">Manage role-play</a>'
            : '';

        $count = $chars->count();
        $body = <<<HTML
        <div class="rp-dir">
          <div class="rp-d-bar">
            <div>
              <h1 class="rp-d-title">Characters</h1>
              <div class="rp-muted rp-d-sub">{$count} character(s)</div>
            </div>
            {$manage}
          </div>
          <div class="rp-d-tools">
            <input id="rp-search" class="rp-d-search" type="search" placeholder="Search name or claim…" autocomplete="off">
            <select id="rp-sort" class="rp-d-sort">
              <option value="name">Name (A–Z)</option>
              <option value="active">Recently active</option>
              <option value="posts">Most posts</option>
            </select>
          </div>
          <div class="rp-grid" id="rp-grid">{$cards}</div>
          <p class="rp-muted rp-empty" id="rp-empty" style="display:none">No characters match your search.</p>
        </div>
        HTML;

        $css = <<<CSS
        .rp-dir{max-width:900px;margin:0 auto;padding:24px 16px}
        .rp-d-bar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
        .rp-d-title{font-size:26px;font-weight:700;margin:0;color:rgb(var(--c-text))}
        .rp-d-sub{margin:2px 0 0}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-btn{font:inherit;font-size:13px;font-weight:600;padding:7px 13px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;text-decoration:none;white-space:nowrap}
        .rp-ok{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .rp-d-tools{display:flex;gap:8px;margin:16px 0}
        .rp-d-search{flex:1;font:inherit;font-size:14px;padding:8px 11px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text))}
        .rp-d-sort{font:inherit;font-size:13px;padding:8px 10px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .rp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
        .rp-card{display:flex;gap:12px;align-items:center;padding:12px;border:1px solid rgb(var(--c-border));
          border-radius:var(--c-radius,10px);background:rgb(var(--c-surface));text-decoration:none}
        .rp-card:hover{border-color:rgb(var(--c-primary))}
        .rp-d-av img,.rp-d-av .rp-mono{width:48px;height:48px;border-radius:var(--c-avatar-radius);object-fit:cover;
          display:flex;align-items:center;justify-content:center;font-weight:600;color:#fff}
        .rp-d-meta{min-width:0}
        .rp-d-name{font-weight:600;color:rgb(var(--c-text))}
        .rp-d-claim{font-size:12px;color:rgb(var(--c-text-2));margin-top:2px}
        .rp-d-claim.rp-dup{color:#d97706}
        .rp-d-by{font-size:12px;color:rgb(var(--c-muted));margin-top:2px}
        .rp-d-stat{font-size:11px;color:rgb(var(--c-muted));margin-top:3px}
        .rp-mono{color:#fff}
        CSS;

        $js = <<<'JS'
        var grid = document.getElementById('rp-grid');
        var search = document.getElementById('rp-search');
        var sort = document.getElementById('rp-sort');
        var empty = document.getElementById('rp-empty');
        if (grid && search && sort) {
          var cards = Array.prototype.slice.call(grid.querySelectorAll('.rp-card'));
          function apply() {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            cards.forEach(function (c) {
              var hit = !q || (c.getAttribute('data-name') || '').indexOf(q) >= 0 || (c.getAttribute('data-claim') || '').indexOf(q) >= 0;
              c.style.display = hit ? '' : 'none';
              if (hit) shown++;
            });
            if (empty) empty.style.display = shown ? 'none' : '';
          }
          function resort() {
            var by = sort.value;
            var arr = cards.slice().sort(function (a, b) {
              if (by === 'name') return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
              return (parseInt(b.getAttribute('data-' + by), 10) || 0) - (parseInt(a.getAttribute('data-' + by), 10) || 0);
            });
            arr.forEach(function (c) { grid.appendChild(c); });
          }
          search.addEventListener('input', apply);
          sort.addEventListener('change', resort);
        }
        JS;

        return ExtPage::render('Characters', $body, $css, $js);
    }

    /** Staff tool: approve/reject pending characters + pick in-character boards. */
    private static function staffPage()
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $pending = RpCharacter::where('status', 'pending')->orderBy('created_at')->get();

        $rows = '';
        foreach ($pending as $c) {
            $owner = \App\Models\User::find($c->user_id);
            $oname = $owner ? \App\Support\Username::display($owner->name, $owner->id) : '#'.$c->user_id;
            $claim = filled($c->claim) ? '<span class="rp-s-claim">'.$e($c->claim).'</span>' : '';
            $rows .= '<div class="rp-s-row" data-id="'.$c->id.'">'
                .'<div><div class="rp-s-name">'.$e($c->name).'</div>'
                .'<div class="rp-muted">by '.$e($oname).' '.$claim.'</div></div>'
                .'<div class="rp-s-actions">'
                .'<button class="rp-btn rp-ok" data-act="approve">Approve</button>'
                .'<button class="rp-btn rp-no" data-act="reject">Reject</button>'
                .'</div></div>';
        }
        if ($rows === '') {
            $rows = '<p class="rp-muted">No characters waiting for review.</p>';
        }

        $ic = self::icCategoryIds();
        $cats = \App\Models\Category::orderBy('name')->get(['id', 'name']);
        $checks = '';
        foreach ($cats as $cat) {
            $on = in_array((int) $cat->id, $ic, true) ? ' checked' : '';
            $checks .= '<label class="rp-chk"><input type="checkbox" value="'.$cat->id.'"'.$on.'> '.$e($cat->name).'</label>';
        }
        $icNote = $ic === []
            ? 'No boards selected — every board is treated as in-character.'
            : 'Posting as a character only applies in the selected boards.';

        $body = <<<HTML
        <div class="rp-staff">
          <h1 class="rp-d-title">Manage role-play</h1>

          <section class="rp-sec">
            <h2 class="rp-h2">Pending characters</h2>
            <div id="rp-queue">{$rows}</div>
          </section>

          <section class="rp-sec">
            <h2 class="rp-h2">In-character boards</h2>
            <p class="rp-muted rp-ic-note">{$icNote}</p>
            <div class="rp-checks">{$checks}</div>
            <button id="rp-save-ic" class="rp-btn rp-ok">Save boards</button>
            <span id="rp-ic-status" class="rp-muted"></span>
          </section>
        </div>
        HTML;

        $css = <<<CSS
        .rp-staff{max-width:760px;margin:0 auto;padding:24px 16px}
        .rp-d-title{font-size:26px;font-weight:700;margin:0 0 18px;color:rgb(var(--c-text))}
        .rp-sec{margin-bottom:28px}
        .rp-h2{font-size:16px;font-weight:700;margin:0 0 10px;color:rgb(var(--c-text))}
        .rp-muted{color:rgb(var(--c-muted));font-size:13px}
        .rp-ic-note{margin:0 0 10px}
        .rp-s-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;
          border:1px solid rgb(var(--c-border));border-radius:var(--c-radius,10px);background:rgb(var(--c-surface));margin-bottom:8px}
        .rp-s-name{font-weight:600;color:rgb(var(--c-text))}
        .rp-s-claim{color:rgb(var(--c-text-2))}
        .rp-s-actions{display:flex;gap:8px;flex-shrink:0}
        .rp-btn{font:inherit;font-size:13px;font-weight:600;padding:6px 12px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .rp-ok{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .rp-no:hover{border-color:#dc2626;color:#dc2626}
        .rp-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;margin-bottom:12px}
        .rp-chk{display:flex;align-items:center;gap:7px;color:rgb(var(--c-text-2));font-size:14px}
        CSS;

        $js = <<<'JS'
        var queue = document.getElementById('rp-queue');
        queue && queue.addEventListener('click', function (ev) {
          var btn = ev.target.closest('.rp-btn'); if (!btn) return;
          var row = btn.closest('.rp-s-row'); var id = row.getAttribute('data-id');
          var act = btn.getAttribute('data-act');
          btn.disabled = true;
          fetch('/api/ext/rp/staff/character/' + id + '/' + act, { method: 'POST', headers: H, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw 0; row.remove();
              if (!queue.querySelector('.rp-s-row')) queue.innerHTML = '<p class="rp-muted">No characters waiting for review.</p>'; })
            .catch(function () { btn.disabled = false; });
        });
        var saveIc = document.getElementById('rp-save-ic');
        saveIc && saveIc.addEventListener('click', function () {
          var ids = Array.prototype.slice.call(document.querySelectorAll('.rp-chk input:checked')).map(function (i) { return i.value; });
          var status = document.getElementById('rp-ic-status'); status.textContent = 'Saving…';
          fetch('/api/ext/rp/staff/ic-categories', { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({ category_ids: ids }) })
            .then(function (r) { return r.json(); })
            .then(function () { status.textContent = 'Saved.'; })
            .catch(function () { status.textContent = 'Could not save.'; });
        });
        JS;

        return ExtPage::render('Manage role-play', $body, $css, $js);
    }
}
