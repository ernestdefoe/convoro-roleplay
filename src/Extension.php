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
        $this->registerDiceRoller();
        $this->registerRoutes();
    }

    /**
     * Evaluate inline dice once, at post creation. A token like [[2d6+3]] is
     * rolled server-side with random_int, logged immutably to rp_rolls, and
     * replaced in the stored body with a result badge — so it renders identically
     * everywhere and can never be re-rolled by editing.
     */
    private function registerDiceRoller(): void
    {
        Post::created(function (Post $post) {
            $html = (string) $post->body_html;
            if (! str_contains($html, '[[')) {
                return;
            }
            if (DB::table('rp_rolls')->where('post_id', $post->id)->exists()) {
                return; // already rolled (defensive against a double event)
            }

            $idx = 0;
            $rolls = [];
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
            $new = preg_replace_callback('/\[\[\s*([0-9dD]+(?:\s*[+-]\s*\d+)?)\s*\]\]/', function ($m) use (&$idx, &$rolls, $post, $e) {
                $expr = strtolower(preg_replace('/\s+/', '', $m[1]));
                if (! preg_match('/^(\d*)d(\d+)([+-]\d+)?$/', $expr, $p)) {
                    return $m[0]; // not a dice expression — leave the text untouched
                }
                $count = $p[1] === '' ? 1 : (int) $p[1];
                $sides = (int) $p[2];
                $mod = ($p[3] ?? '') !== '' ? (int) $p[3] : 0;
                if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000 || $idx >= 30) {
                    return $m[0]; // out of sane bounds — leave as typed
                }
                $dice = [];
                for ($i = 0; $i < $count; $i++) {
                    $dice[] = random_int(1, $sides);
                }
                $total = array_sum($dice) + $mod;
                $rolls[] = [
                    'post_id' => $post->id,
                    'idx' => $idx++,
                    'expr' => $expr,
                    'total' => $total,
                    'detail' => json_encode($dice),
                    'created_at' => now(),
                ];
                $modText = $mod ? ($mod > 0 ? ' +'.$mod : ' '.$mod) : '';
                $title = $expr.' → ['.implode(', ', $dice).']'.$modText;

                return '<span class="rp-roll" title="'.$e($title).'">🎲 '.$e($expr).' = <b>'.$total.'</b></span>';
            }, $html);

            if (! $rolls) {
                return;
            }
            DB::table('rp_rolls')->insert($rolls);
            DB::table('posts')->where('id', $post->id)->update(['body_html' => $new]);
            // Sync the in-memory model so the post-create broadcast shows the rolled body.
            $post->body_html = $new;
            $post->syncOriginal();
        });
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
            if (! self::isRpTopic((int) $post->topic_id)) {
                return; // regular topic — keep the real account
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

    /** Category ids designated as role-play boards (topics there are role-play). */
    private static function rpCategoryIds(): array
    {
        return DB::table('rp_ic_categories')->pluck('category_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Is a category a role-play category? */
    private static function categoryIsRp(?int $catId): bool
    {
        return $catId !== null && in_array($catId, self::rpCategoryIds(), true);
    }

    /** Request-cached per-topic role-play status. */
    private static array $rpTopicCache = [];

    /**
     * Is this a role-play topic? An explicit rp_topics row wins; otherwise the
     * topic follows its category (role-play categories make their topics RP).
     */
    private static function isRpTopic(int $topicId): bool
    {
        if (isset(self::$rpTopicCache[$topicId])) {
            return self::$rpTopicCache[$topicId];
        }
        $override = DB::table('rp_topics')->where('topic_id', $topicId)->value('is_rp');
        if ($override !== null) {
            return self::$rpTopicCache[$topicId] = (bool) $override;
        }
        $catId = DB::table('topics')->where('id', $topicId)->value('category_id');

        return self::$rpTopicCache[$topicId] = self::categoryIsRp($catId !== null ? (int) $catId : null);
    }

    /** Does the current user hold the role-play moderation permission? */
    private static function canModerate(): bool
    {
        return (bool) Auth::user()?->hasPermission('roleplay.moderate');
    }

    /** Accept an image URL only if it's same-origin (our uploader's output). */
    private static function safeImageUrl(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $origin = rtrim(url('/'), '/');
        if (str_starts_with($v, '/') || str_starts_with($v, $origin)) {
            return mb_substr($v, 0, 500);
        }

        return null; // reject external URLs (no hotlinking foreign images)
    }

    /** Staff-defined character-sheet schema: ordered list of {key,label,type,options}. */
    private static function sheetSchema(): array
    {
        $raw = ExtensionManager::setting('convoro-roleplay', 'sheet_schema', null);
        $arr = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (! is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $f) {
            if (! is_array($f) || empty($f['key']) || empty($f['label'])) {
                continue;
            }
            $type = in_array($f['type'] ?? 'text', ['text', 'number', 'textarea', 'select'], true) ? $f['type'] : 'text';
            $out[] = [
                'key' => Str::slug((string) $f['key']) ?: 'field',
                'label' => (string) $f['label'],
                'type' => $type,
                'options' => array_values(array_filter(array_map('trim', array_map('strval', (array) ($f['options'] ?? []))))),
            ];
        }

        return $out;
    }

    /** Keep only schema keys from submitted sheet values, coerced to their type. */
    private static function filterSheetValues(array $values): array
    {
        $out = [];
        foreach (self::sheetSchema() as $f) {
            if (! array_key_exists($f['key'], $values)) {
                continue;
            }
            $v = $values[$f['key']];
            if (! is_scalar($v)) {
                continue;
            }
            if ($f['type'] === 'number') {
                $v = is_numeric($v) ? $v + 0 : null;
            } elseif ($f['type'] === 'select') {
                $v = in_array((string) $v, $f['options'], true) ? (string) $v : null;
            } else {
                $v = mb_substr((string) $v, 0, 2000);
            }
            if ($v !== null && $v !== '') {
                $out[$f['key']] = $v;
            }
        }

        return $out;
    }

    /** Render a post's author as its role-play character, when one is set. */
    private function registerIdentityResolver(): void
    {
        PostIdentity::extend(function (Post $post, array $author): ?array {
            if (array_key_exists($post->id, self::$identityCache)) {
                return self::$identityCache[$post->id];
            }

            $identity = null;
            // Only a role-play topic shows characters — regular topics stay clean.
            if (self::isRpTopic((int) $post->topic_id)) {
                $charId = DB::table('rp_post_character')->where('post_id', $post->id)->value('character_id');
                if ($charId && ($c = RpCharacter::find($charId))) {
                    $identity = self::identity($c, (int) $post->user_id);
                }
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

        // Role-play context for the composer + topic page: is this a role-play
        // topic/category, may the viewer toggle it, and their characters.
        Route::middleware(['web', 'auth'])->get('/api/ext/rp/context', function (Request $request) {
            $topicId = (int) $request->query('topic', 0);
            $slug = (string) $request->query('slug', '');
            if (! $topicId && $slug !== '') {
                $topicId = (int) DB::table('topics')->where('slug', $slug)->value('id');
            }
            $catRaw = $request->query('category');
            $catId = ($catRaw !== null && $catRaw !== '') ? (int) $catRaw : null;

            $canToggle = false;
            if ($topicId) {
                $rp = self::isRpTopic($topicId);
                $owner = DB::table('topics')->where('id', $topicId)->value('user_id');
                $canToggle = $owner !== null && (self::canModerate() || (int) $owner === (int) Auth::id());
            } else {
                $rp = self::categoryIsRp($catId);
            }

            $characters = [];
            if ($rp) {
                $characters = RpCharacter::where('user_id', Auth::id())->where('status', 'approved')
                    ->orderBy('name')->get()
                    ->map(fn (RpCharacter $c) => [
                        'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                        'initials' => $c->initials(), 'color' => $c->color ?: (($c->id % 6) + 1), 'avatar' => $c->avatar_path,
                    ])->all();
            }

            return response()->json(['rp' => $rp, 'canToggle' => $canToggle, 'topicId' => $topicId ?: null, 'characters' => $characters]);
        });

        // Author or staff flip a topic between role-play and regular (explicit override).
        Route::middleware(['web', 'auth'])->post('/api/ext/rp/topic/{topic}/rp', function (Request $request, int $topic) {
            $owner = DB::table('topics')->where('id', $topic)->value('user_id');
            abort_unless($owner !== null && (self::canModerate() || (int) $owner === (int) Auth::id()), 403);
            $rp = $request->boolean('rp');
            DB::table('rp_topics')->updateOrInsert(['topic_id' => $topic], ['is_rp' => $rp ? 1 : 0]);

            return response()->json(['ok' => true, 'rp' => $rp]);
        });

        Route::middleware(['web', 'auth'])->group(function () {
            // Create a character. Auto-approved unless staff require review.
            Route::post('/api/ext/rp/characters', function (Request $request) {
                $data = $request->validate([
                    'name' => 'required|string|max:80',
                    'bio' => 'nullable|string|max:5000',
                    'claim' => 'nullable|string|max:120',
                    'fields' => 'nullable|array',
                    'avatar' => 'nullable|string|max:500',
                    'hero' => 'nullable|string|max:500',
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
                    'fields' => self::filterSheetValues($data['fields'] ?? []),
                    'avatar_path' => self::safeImageUrl($data['avatar'] ?? null),
                    'hero_path' => self::safeImageUrl($data['hero'] ?? null),
                    'status' => $auto ? 'approved' : 'pending',
                ]);

                return response()->json([
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'status' => $c->status,
                    'pending' => $c->status === 'pending',
                ]);
            });

            // Update one of my own characters (name/claim/bio/sheet fields).
            Route::post('/api/ext/rp/characters/{character}', function (Request $request, int $character) {
                $c = RpCharacter::where('id', $character)->where('user_id', Auth::id())->first();
                abort_unless($c, 404);
                $data = $request->validate([
                    'name' => 'required|string|max:80',
                    'bio' => 'nullable|string|max:5000',
                    'claim' => 'nullable|string|max:120',
                    'fields' => 'nullable|array',
                    'avatar' => 'nullable|string|max:500',
                    'hero' => 'nullable|string|max:500',
                ]);
                $c->update([
                    'name' => $data['name'],
                    'bio' => $data['bio'] ?? null,
                    'claim' => $data['claim'] ?? null,
                    'fields' => self::filterSheetValues($data['fields'] ?? []),
                    'avatar_path' => self::safeImageUrl($data['avatar'] ?? null),
                    'hero_path' => self::safeImageUrl($data['hero'] ?? null),
                ]);

                return response()->json(['ok' => true, 'slug' => $c->slug]);
            });

            // "My characters" — create + edit your own characters and sheets.
            Route::get('/rp/me', fn () => self::myCharactersPage());

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

            Route::post('/api/ext/rp/staff/sheet-schema', function (Request $request) {
                abort_unless(self::canModerate(), 403);
                $seen = [];
                $fields = [];
                foreach ((array) $request->input('fields', []) as $f) {
                    if (! is_array($f) || ! filled($f['label'] ?? null)) {
                        continue;
                    }
                    $key = Str::slug((string) ($f['key'] ?? $f['label'])) ?: 'field';
                    while (in_array($key, $seen, true)) {
                        $key .= '-2';
                    }
                    $seen[] = $key;
                    $fields[] = [
                        'key' => $key,
                        'label' => mb_substr((string) $f['label'], 0, 60),
                        'type' => in_array($f['type'] ?? 'text', ['text', 'number', 'textarea', 'select'], true) ? $f['type'] : 'text',
                        'options' => array_values(array_filter(array_map('trim', explode(',', (string) ($f['options'] ?? ''))))),
                    ];
                }
                \App\Support\Settings::set(ExtensionManager::settingKey('convoro-roleplay', 'sheet_schema'), json_encode($fields));

                return response()->json(['ok' => true, 'count' => count($fields)]);
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
            : '<div class="rp-mono rp-g'.($c->color ?: (($c->id % 6) + 1)).'" style="width:96px;height:96px;border-radius:var(--c-avatar-radius);display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:600">'.$e($c->initials()).'</div>';

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

        // Character sheet — the staff-defined fields this character has filled in.
        $sheet = '';
        $vals = is_array($c->fields) ? $c->fields : [];
        $sheetItems = '';
        foreach (self::sheetSchema() as $f) {
            $v = $vals[$f['key']] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $sheetItems .= '<tr><th>'.$e($f['label']).'</th><td>'.nl2br($e($v)).'</td></tr>';
        }
        if ($sheetItems !== '') {
            $sheet = '<section class="rp-sheet"><h2 class="rp-tr-h">Character sheet</h2>'
                .'<table class="rp-sheet-table"><tbody>'.$sheetItems.'</tbody></table></section>';
        }

        $color = $c->color ?: (($c->id % 6) + 1);
        $banner = $c->hero_path
            ? '<div class="rp-banner" style="background-image:url('.$e($c->hero_path).');background-size:cover;background-position:center"></div>'
            : '<div class="rp-banner rp-g'.$color.'"></div>';
        $body = <<<HTML
        <div class="rp-profile">
          <div class="rp-card2">
            {$banner}
            <div class="rp-head">
              {$avatar}
              <div class="rp-head-meta">
                <h1 class="rp-name">{$e($c->name)}</h1>
                <div class="rp-muted">{$c->post_count} posts</div>
                {$claimLine}
                {$playedBy}
              </div>
            </div>
          </div>
          {$bio}
          {$sheet}
          {$tracker}
        </div>
        HTML;

        $css = <<<CSS
        .rp-profile{max-width:760px;margin:0 auto;padding:24px 16px 48px}
        .rp-card2{border:1px solid rgb(var(--c-border));border-radius:20px;overflow:hidden;background:rgb(var(--c-surface));margin-bottom:24px}
        .rp-banner{height:120px}
        .rp-head{display:flex;gap:20px;align-items:flex-end;padding:0 26px 22px;margin-top:-46px}
        .rp-head>img,.rp-head>.rp-mono{flex-shrink:0;border:4px solid rgb(var(--c-surface));box-shadow:0 6px 18px -6px rgba(0,0,0,.45)}
        .rp-head-meta{padding-bottom:2px;min-width:0}
        .rp-name{font-size:28px;font-weight:800;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-bio{color:rgb(var(--c-text-2));line-height:1.7;margin:0}
        .rp-claim{margin-top:4px;font-size:13px;color:rgb(var(--c-text-2))}
        .rp-claim::before{content:"Claim: ";color:rgb(var(--c-muted))}
        .rp-played{margin-top:3px}
        .rp-played a{color:rgb(var(--c-primary));text-decoration:none}
        .rp-played a:hover{text-decoration:underline}
        .rp-tracker{margin-top:26px}
        .rp-sheet{margin-top:26px}
        .rp-sheet-table{width:100%;border-collapse:separate;border-spacing:0;
          border:1px solid rgb(var(--c-border));border-radius:12px;overflow:hidden}
        .rp-sheet-table th,.rp-sheet-table td{padding:11px 16px;text-align:left;font-size:14px;
          border-top:1px solid rgb(var(--c-border));vertical-align:top}
        .rp-sheet-table tr:first-child th,.rp-sheet-table tr:first-child td{border-top:none}
        .rp-sheet-table th{width:34%;font-weight:600;color:rgb(var(--c-text-2));
          background:rgb(var(--c-surface-2));white-space:nowrap}
        .rp-sheet-table td{color:rgb(var(--c-text));line-height:1.5}
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
            $color = $c->color ?: (($c->id % 6) + 1);
            $av = $c->avatar_path
                ? '<img src="'.$e($c->avatar_path).'" alt="">'
                : '<span class="rp-mono rp-g'.$color.'">'.$e($c->initials()).'</span>';

            $claim = '';
            if (filled($c->claim)) {
                $dup = isset($dupes[Str::lower(trim($c->claim))]) ? ' rp-dup' : '';
                $title = $dup ? ' title="This claim is used by more than one character"' : '';
                $claim = '<span class="rp-chip'.$dup.'"'.$title.'>'.$e($c->claim).($dup ? ' ⚠' : '').'</span>';
            }

            $by = '';
            if ($reveal && ($o = $owners->get($c->user_id))) {
                $by = '<div class="rp-card-by">played by '.$e(\App\Support\Username::display($o->name, $o->id)).'</div>';
            }

            $active = $c->last_active_at ? $c->last_active_at->getTimestamp() : 0;
            $activeLabel = $c->last_active_at ? $c->last_active_at->diffForHumans() : 'new';
            $cards .= '<a class="rp-card" href="/characters/'.$e($c->slug).'"'
                .' data-name="'.$e(Str::lower($c->name)).'"'
                .' data-claim="'.$e(Str::lower((string) $c->claim)).'"'
                .' data-posts="'.(int) $c->post_count.'" data-active="'.$active.'">'
                .($c->hero_path
                    ? '<div class="rp-card-top" style="background-image:url('.$e($c->hero_path).');background-size:cover;background-position:center"></div>'
                    : '<div class="rp-card-top rp-g'.$color.'"></div>')
                .'<div class="rp-card-av">'.$av.'</div>'
                .'<div class="rp-card-body">'
                .'<div class="rp-card-name">'.$e($c->name).'</div>'
                .($claim !== '' ? '<div class="rp-card-claim">'.$claim.'</div>' : '')
                .$by
                .'<div class="rp-card-stats"><span class="rp-stat">'.(int) $c->post_count.' posts</span>'
                .'<span class="rp-stat">'.$e($activeLabel).'</span></div>'
                .'</div></a>';
        }

        if ($cards === '') {
            $cards = '<div class="rp-blank"><div class="rp-blank-mask">🎭</div>'
                .'<p class="rp-muted">No characters yet — be the first to create one.</p></div>';
        }

        $actions = '';
        if (Auth::check()) {
            $actions .= '<a class="rp-btn rp-mine" href="/rp/me">My characters</a>';
        }
        if (self::canModerate()) {
            $actions .= '<a class="rp-btn rp-ok rp-manage" href="/rp/staff">Manage role-play</a>';
        }

        $count = $chars->count();
        $body = <<<HTML
        <div class="rp-dir">
          <div class="rp-hero">
            <div class="rp-hero-text">
              <div class="rp-hero-eyebrow">🎭 The cast</div>
              <h1 class="rp-d-title">Characters</h1>
              <div class="rp-hero-sub">{$count} character(s) bringing the story to life.</div>
            </div>
            <div class="rp-d-actions">{$actions}</div>
          </div>
          <div class="rp-d-tools">
            <input id="rp-search" class="rp-d-search" type="search" placeholder="Search by name or claim…" autocomplete="off">
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
        .rp-dir{max-width:960px;margin:0 auto;padding:24px 16px 48px}
        .rp-hero{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;
          padding:26px 26px 24px;margin-bottom:18px;border-radius:20px;overflow:hidden;
          border:1px solid rgb(var(--c-border));
          background:
            radial-gradient(120% 140% at 0% 0%, rgba(91,91,214,.16), transparent 55%),
            radial-gradient(120% 160% at 100% 0%, rgba(168,85,247,.14), transparent 50%),
            rgb(var(--c-surface))}
        .rp-hero-eyebrow{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:rgb(var(--c-primary));margin-bottom:6px}
        .rp-d-title{font-size:32px;font-weight:800;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .rp-hero-sub{margin-top:6px;font-size:14px;color:rgb(var(--c-muted))}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-btn{font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:999px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;text-decoration:none;white-space:nowrap;transition:border-color .15s,transform .15s}
        .rp-btn:hover{border-color:rgb(var(--c-primary));transform:translateY(-1px)}
        .rp-ok{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .rp-d-actions{display:flex;gap:8px;flex-shrink:0}
        .rp-d-tools{display:flex;gap:8px;margin:0 0 18px}
        .rp-d-search{flex:1;font:inherit;font-size:14px;padding:10px 14px;border-radius:999px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text))}
        .rp-d-search:focus{outline:none;border-color:rgb(var(--c-primary))}
        .rp-d-sort{font:inherit;font-size:13px;padding:10px 14px;border-radius:999px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .rp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(216px,1fr));gap:16px}
        .rp-card{position:relative;display:block;border:1px solid rgb(var(--c-border));border-radius:16px;
          background:rgb(var(--c-surface));overflow:hidden;text-decoration:none;
          transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
        .rp-card:hover{transform:translateY(-4px);box-shadow:0 16px 34px -16px rgba(0,0,0,.45);border-color:rgb(var(--c-primary))}
        .rp-card-top{height:60px}
        .rp-card-top::after{content:"";position:absolute;top:0;left:0;right:0;height:60px;
          background:linear-gradient(180deg,transparent,rgba(0,0,0,.12))}
        .rp-card-av{position:relative;margin:-30px 0 0 18px;width:60px;height:60px;border-radius:var(--c-avatar-radius,50%);
          border:3px solid rgb(var(--c-surface));overflow:hidden;z-index:1}
        .rp-card-av img,.rp-card-av .rp-mono{width:100%;height:100%;object-fit:cover;
          display:flex;align-items:center;justify-content:center;font-weight:700;font-size:21px;color:#fff}
        .rp-card-body{padding:10px 18px 18px}
        .rp-card-name{font-weight:700;font-size:15.5px;color:rgb(var(--c-text));line-height:1.25}
        .rp-card-claim{margin-top:7px}
        .rp-chip{display:inline-block;font-size:11px;font-weight:600;color:rgb(var(--c-text-2));
          background:rgb(var(--c-surface-2));padding:3px 9px;border-radius:999px}
        .rp-chip.rp-dup{color:#d97706;background:rgba(217,119,6,.13)}
        .rp-card-by{margin-top:7px;font-size:12px;color:rgb(var(--c-muted))}
        .rp-card-stats{margin-top:12px;display:flex;gap:6px;flex-wrap:wrap}
        .rp-stat{font-size:11px;font-weight:500;color:rgb(var(--c-muted));background:rgb(var(--c-surface-2));padding:3px 9px;border-radius:7px}
        .rp-blank{grid-column:1/-1;text-align:center;padding:48px 16px}
        .rp-blank-mask{font-size:44px;opacity:.5;margin-bottom:8px}
        .rp-mono{color:#fff}
        @media(max-width:560px){.rp-hero{flex-direction:column;align-items:flex-start}}
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

        $rpCats = self::rpCategoryIds();
        $cats = \App\Models\Category::orderBy('name')->get(['id', 'name']);
        $checks = '';
        foreach ($cats as $cat) {
            $on = in_array((int) $cat->id, $rpCats, true) ? ' checked' : '';
            $checks .= '<label class="rp-chk"><input type="checkbox" value="'.$cat->id.'"'.$on.'> '.$e($cat->name).'</label>';
        }
        $icNote = $rpCats === []
            ? 'Tick the categories that are role-play boards. Every topic in them becomes a role-play thread (members can post as a character there). Authors can also flag an individual topic.'
            : 'Topics in the ticked categories are role-play threads. Authors can override per topic.';

        // Character-sheet field builder.
        $typeOpts = ['text' => 'Text', 'number' => 'Number', 'textarea' => 'Paragraph', 'select' => 'Choice'];
        $sheetRows = '';
        foreach (self::sheetSchema() as $f) {
            $opts = '';
            foreach ($typeOpts as $tv => $tl) {
                $opts .= '<option value="'.$tv.'"'.($f['type'] === $tv ? ' selected' : '').'>'.$tl.'</option>';
            }
            $sheetRows .= '<div class="rp-srow">'
                .'<input class="rp-sf-label" placeholder="Field label" value="'.$e($f['label']).'">'
                .'<select class="rp-sf-type">'.$opts.'</select>'
                .'<input class="rp-sf-opts" placeholder="Choices (comma-separated)" value="'.$e(implode(', ', $f['options'])).'">'
                .'<button type="button" class="rp-sf-del" title="Remove field">×</button>'
                .'</div>';
        }

        $body = <<<HTML
        <div class="rp-staff">
          <h1 class="rp-d-title">Manage role-play</h1>

          <section class="rp-sec">
            <h2 class="rp-h2">Pending characters</h2>
            <div id="rp-queue">{$rows}</div>
          </section>

          <section class="rp-sec">
            <h2 class="rp-h2">Role-play categories</h2>
            <p class="rp-muted rp-ic-note">{$icNote}</p>
            <div class="rp-checks">{$checks}</div>
            <button id="rp-save-ic" class="rp-btn rp-ok">Save categories</button>
            <span id="rp-ic-status" class="rp-muted"></span>
          </section>

          <section class="rp-sec">
            <h2 class="rp-h2">Character sheet</h2>
            <p class="rp-muted rp-ic-note">Fields every member fills in for each character. “Choice” fields use the comma-separated options.</p>
            <div id="rp-sheet-rows">{$sheetRows}</div>
            <div class="rp-sheet-foot">
              <button id="rp-add-field" type="button" class="rp-btn">+ Add field</button>
              <button id="rp-save-sheet" type="button" class="rp-btn rp-ok">Save sheet</button>
              <span id="rp-sheet-status" class="rp-muted"></span>
            </div>
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
        .rp-srow{display:flex;gap:8px;align-items:center;margin-bottom:8px}
        .rp-srow input,.rp-srow select{font:inherit;font-size:13px;padding:7px 9px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text))}
        .rp-sf-label{flex:1;min-width:0}
        .rp-sf-opts{flex:1.4;min-width:0}
        .rp-sf-del{flex-shrink:0;width:30px;height:30px;border-radius:8px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface));color:rgb(var(--c-muted));cursor:pointer;font-size:16px;line-height:1}
        .rp-sf-del:hover{border-color:#dc2626;color:#dc2626}
        .rp-sheet-foot{display:flex;align-items:center;gap:10px;margin-top:6px}
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

        // --- character-sheet field builder ---
        var sheetRows = document.getElementById('rp-sheet-rows');
        function newFieldRow() {
          var row = document.createElement('div');
          row.className = 'rp-srow';
          row.innerHTML = '<input class="rp-sf-label" placeholder="Field label">' +
            '<select class="rp-sf-type"><option value="text">Text</option><option value="number">Number</option>' +
            '<option value="textarea">Paragraph</option><option value="select">Choice</option></select>' +
            '<input class="rp-sf-opts" placeholder="Choices (comma-separated)">' +
            '<button type="button" class="rp-sf-del" title="Remove field">×</button>';
          sheetRows.appendChild(row);
        }
        var addField = document.getElementById('rp-add-field');
        addField && addField.addEventListener('click', newFieldRow);
        sheetRows && sheetRows.addEventListener('click', function (ev) {
          var del = ev.target.closest('.rp-sf-del'); if (del) del.closest('.rp-srow').remove();
        });
        var saveSheet = document.getElementById('rp-save-sheet');
        saveSheet && saveSheet.addEventListener('click', function () {
          var status = document.getElementById('rp-sheet-status'); status.textContent = 'Saving…';
          var fields = Array.prototype.slice.call(sheetRows.querySelectorAll('.rp-srow')).map(function (r) {
            return { label: r.querySelector('.rp-sf-label').value, type: r.querySelector('.rp-sf-type').value, options: r.querySelector('.rp-sf-opts').value };
          }).filter(function (f) { return f.label.trim(); });
          fetch('/api/ext/rp/staff/sheet-schema', { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({ fields: fields }) })
            .then(function (r) { return r.json(); })
            .then(function (d) { status.textContent = 'Saved ' + d.count + ' field(s).'; })
            .catch(function () { status.textContent = 'Could not save.'; });
        });
        JS;

        return ExtPage::render('Manage role-play', $body, $css, $js);
    }

    /** Render the sheet-field inputs for a character form (named field_<key>). */
    private static function sheetInputs(array $schema, array $values, callable $e): string
    {
        if ($schema === []) {
            return '';
        }
        $h = '<div class="rp-sheet-fields"><div class="rp-cf-shead">Character sheet</div>';
        foreach ($schema as $f) {
            $val = $values[$f['key']] ?? '';
            $name = 'field_'.$f['key'];
            $h .= '<label class="rp-cf-l">'.$e($f['label']);
            if ($f['type'] === 'textarea') {
                $h .= '<textarea name="'.$name.'" rows="2">'.$e($val).'</textarea>';
            } elseif ($f['type'] === 'select') {
                $h .= '<select name="'.$name.'"><option value="">—</option>';
                foreach ($f['options'] as $o) {
                    $sel = ((string) $val === (string) $o) ? ' selected' : '';
                    $h .= '<option value="'.$e($o).'"'.$sel.'>'.$e($o).'</option>';
                }
                $h .= '</select>';
            } else {
                $type = $f['type'] === 'number' ? 'number' : 'text';
                $h .= '<input type="'.$type.'" name="'.$name.'" value="'.$e($val).'">';
            }
            $h .= '</label>';
        }

        return $h.'</div>';
    }

    /** An avatar/hero image picker (uploads via the core image endpoint). */
    private static function imageField(string $kind, string $label, ?string $current, callable $e): string
    {
        $cls = $kind === 'hero' ? 'rp-up-hero' : 'rp-up-avatar';
        $bg = $current ? ' style="background-image:url('.$e($current).')"' : '';
        $plus = $current ? '' : '<span class="rp-up-plus">+</span>';
        $clear = ' style="'.($current ? '' : 'display:none').'"';

        return '<div class="rp-up '.$cls.'">'
            .'<div class="rp-up-label">'.$e($label).'</div>'
            .'<button type="button" class="rp-up-prev" data-target="'.$kind.'"'.$bg.'>'.$plus.'</button>'
            .'<button type="button" class="rp-up-clear" data-target="'.$kind.'"'.$clear.'>Remove</button>'
            .'<input type="hidden" name="'.$kind.'" value="'.$e($current).'">'
            .'</div>';
    }

    /** Both image pickers + the shared file input, for one character form. */
    private static function uploadsBlock(?string $avatar, ?string $hero, callable $e): string
    {
        return '<div class="rp-uploads">'
            .self::imageField('avatar', 'Avatar', $avatar, $e)
            .self::imageField('hero', 'Hero image', $hero, $e)
            .'</div>'
            .'<input type="file" class="rp-up-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>';
    }

    /** Member page: create + edit your own characters (and fill their sheets). */
    private static function myCharactersPage()
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $schema = self::sheetSchema();
        $mine = RpCharacter::where('user_id', Auth::id())->orderBy('name')->get();
        $auto = (bool) ExtensionManager::setting('convoro-roleplay', 'auto_approve', true);
        $max = (int) ExtensionManager::setting('convoro-roleplay', 'max_per_user', 3);

        $forms = '';
        foreach ($mine as $c) {
            $vals = is_array($c->fields) ? $c->fields : [];
            $badge = $c->status !== 'approved' ? '<span class="rp-badge">'.$e(ucfirst($c->status)).'</span>' : '';
            $view = $c->status === 'approved' ? '<a class="rp-cf-view" href="/characters/'.$e($c->slug).'">View profile →</a>' : '';
            $forms .= '<form class="rp-cform" data-id="'.$c->id.'">'
                .'<div class="rp-cf-head"><h2 class="rp-cf-title">'.$e($c->name).'</h2>'.$badge.'</div>'
                .self::uploadsBlock($c->avatar_path, $c->hero_path, $e)
                .'<label class="rp-cf-l">Name<input name="name" required value="'.$e($c->name).'"></label>'
                .'<label class="rp-cf-l">Face / canon claim<input name="claim" value="'.$e($c->claim).'"></label>'
                .'<label class="rp-cf-l">About<textarea name="bio" rows="3">'.$e($c->bio).'</textarea></label>'
                .self::sheetInputs($schema, $vals, $e)
                .'<div class="rp-cf-foot"><button type="submit" class="rp-btn rp-ok">Save</button><span class="rp-cf-msg"></span>'.$view.'</div>'
                .'</form>';
        }
        if ($forms === '') {
            $forms = '<p class="rp-muted">You don’t have any characters yet — create your first below.</p>';
        }

        $newForm = '';
        if ($max === 0 || $mine->count() < $max) {
            $note = $auto ? '' : '<p class="rp-muted rp-cf-note">New characters are reviewed by staff before they can post.</p>';
            $newForm = '<form class="rp-cform rp-cnew" data-id="">'
                .'<div class="rp-cf-head"><h2 class="rp-cf-title">New character</h2></div>'.$note
                .self::uploadsBlock(null, null, $e)
                .'<label class="rp-cf-l">Name<input name="name" required placeholder="Character name"></label>'
                .'<label class="rp-cf-l">Face / canon claim<input name="claim" placeholder="e.g. Zendaya"></label>'
                .'<label class="rp-cf-l">About<textarea name="bio" rows="3"></textarea></label>'
                .self::sheetInputs($schema, [], $e)
                .'<div class="rp-cf-foot"><button type="submit" class="rp-btn rp-ok">Create character</button><span class="rp-cf-msg"></span></div>'
                .'</form>';
        } else {
            $newForm = '<p class="rp-muted">You’ve reached your character limit ('.$max.').</p>';
        }

        $body = <<<HTML
        <div class="rp-me">
          <h1 class="rp-d-title">My characters</h1>
          {$forms}
          {$newForm}
        </div>
        HTML;

        $css = <<<CSS
        .rp-me{max-width:680px;margin:0 auto;padding:24px 16px}
        .rp-d-title{font-size:26px;font-weight:700;margin:0 0 18px;color:rgb(var(--c-text))}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-cform{border:1px solid rgb(var(--c-border));border-radius:var(--c-radius,12px);background:rgb(var(--c-surface));
          padding:18px;margin-bottom:16px}
        .rp-cf-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
        .rp-cf-title{font-size:17px;font-weight:700;margin:0;color:rgb(var(--c-text))}
        .rp-badge{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#d97706;
          background:rgba(217,119,6,.12);padding:2px 8px;border-radius:999px}
        .rp-cf-note{margin:-4px 0 12px}
        .rp-cf-l{display:block;font-size:13px;font-weight:600;color:rgb(var(--c-text-2));margin-bottom:11px}
        .rp-cf-l input,.rp-cf-l textarea,.rp-cf-l select{display:block;width:100%;margin-top:5px;font:inherit;font-size:14px;
          font-weight:400;padding:8px 10px;border-radius:var(--c-radius,8px);border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-appbg,var(--c-surface)));color:rgb(var(--c-text))}
        .rp-sheet-fields{margin:6px 0 4px;padding-top:8px;border-top:1px dashed rgb(var(--c-border))}
        .rp-cf-shead{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:rgb(var(--c-muted));margin-bottom:10px}
        .rp-cf-foot{display:flex;align-items:center;gap:12px;margin-top:6px}
        .rp-btn{font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:var(--c-radius,8px);
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .rp-ok{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .rp-cf-msg{font-size:13px;color:rgb(var(--c-muted))}
        .rp-cf-view{margin-left:auto;font-size:13px;color:rgb(var(--c-primary));text-decoration:none}
        .rp-uploads{display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap}
        .rp-up{display:flex;flex-direction:column;gap:6px}
        .rp-up-hero{flex:1;min-width:200px}
        .rp-up-label{font-size:13px;font-weight:600;color:rgb(var(--c-text-2))}
        .rp-up-prev{padding:0;border:1px dashed rgb(var(--c-border));border-radius:12px;cursor:pointer;
          background-color:rgb(var(--c-surface-2));background-size:cover;background-position:center;
          color:rgb(var(--c-muted));font-size:26px;display:flex;align-items:center;justify-content:center;overflow:hidden}
        .rp-up-prev:hover{border-color:rgb(var(--c-primary))}
        .rp-up-avatar .rp-up-prev{width:88px;height:88px;border-radius:var(--c-avatar-radius,12px)}
        .rp-up-hero .rp-up-prev{width:100%;height:88px}
        .rp-up-prev.rp-up-busy{opacity:.5}
        .rp-up-clear{align-self:flex-start;border:none;background:none;color:rgb(var(--c-muted));font-size:12px;cursor:pointer;padding:0;text-decoration:underline}
        .rp-up-clear:hover{color:#dc2626}
        CSS;

        $js = <<<'JS'
        document.querySelectorAll('.rp-cform').forEach(function (form) {
          var file = form.querySelector('.rp-up-file');
          form.querySelectorAll('.rp-up-prev').forEach(function (prev) {
            prev.addEventListener('click', function () { form.dataset.upTarget = prev.getAttribute('data-target'); file.click(); });
          });
          form.querySelectorAll('.rp-up-clear').forEach(function (cl) {
            cl.addEventListener('click', function () {
              var t = cl.getAttribute('data-target');
              form.querySelector('input[name="' + t + '"]').value = '';
              var prev = form.querySelector('.rp-up-prev[data-target="' + t + '"]');
              prev.style.backgroundImage = ''; prev.innerHTML = '<span class="rp-up-plus">+</span>';
              cl.style.display = 'none';
            });
          });
          file && file.addEventListener('change', function () {
            var f = file.files && file.files[0]; if (!f) return;
            var t = form.dataset.upTarget || 'avatar';
            var prev = form.querySelector('.rp-up-prev[data-target="' + t + '"]');
            prev.classList.add('rp-up-busy');
            var fd = new FormData(); fd.append('file', f);
            fetch('/uploads/image', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin', body: fd })
              .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
              .then(function (d) {
                form.querySelector('input[name="' + t + '"]').value = d.url;
                prev.style.backgroundImage = 'url(' + d.url + ')'; prev.innerHTML = '';
                var cl = form.querySelector('.rp-up-clear[data-target="' + t + '"]'); if (cl) cl.style.display = '';
              })
              .catch(function () { alert('Upload failed — try a smaller image (max 8MB).'); })
              .finally(function () { prev.classList.remove('rp-up-busy'); file.value = ''; });
          });

          form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var id = form.getAttribute('data-id');
            var msg = form.querySelector('.rp-cf-msg');
            function v(sel) { var el = form.querySelector(sel); return el ? el.value : ''; }
            var payload = { name: v('[name="name"]'), claim: v('[name="claim"]'), bio: v('[name="bio"]'),
              avatar: v('input[name="avatar"]'), hero: v('input[name="hero"]'), fields: {} };
            if (!payload.name.trim()) { msg.textContent = 'Name is required.'; return; }
            form.querySelectorAll('[name^="field_"]').forEach(function (el) { payload.fields[el.name.slice(6)] = el.value; });
            msg.textContent = 'Saving…';
            var url = id ? '/api/ext/rp/characters/' + id : '/api/ext/rp/characters';
            fetch(url, { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify(payload) })
              .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
              .then(function (res) {
                if (!res.ok) { msg.textContent = (res.d && res.d.message) || 'Could not save.'; return; }
                msg.textContent = 'Saved.';
                if (!id) { window.location.reload(); }
              })
              .catch(function () { msg.textContent = 'Could not save.'; });
          });
        });
        JS;

        return ExtPage::render('My characters', $body, $css, $js);
    }
}
