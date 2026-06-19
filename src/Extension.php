<?php

namespace Convoro\Ext\Roleplay;

use App\Models\Post;
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
        $this->registerRoutes();
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
            // Create a character (spine: auto-approved; applications come later).
            Route::post('/api/ext/rp/characters', function (Request $request) {
                $data = $request->validate([
                    'name' => 'required|string|max:80',
                    'bio' => 'nullable|string|max:5000',
                ]);
                $slug = self::uniqueSlug($data['name']);
                $c = RpCharacter::create([
                    'user_id' => Auth::id(),
                    'name' => $data['name'],
                    'slug' => $slug,
                    'bio' => $data['bio'] ?? null,
                    'status' => 'approved',
                ]);

                return response()->json(['id' => $c->id, 'slug' => $c->slug]);
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

        $body = <<<HTML
        <div class="rp-profile">
          <div class="rp-head">
            {$avatar}
            <div>
              <h1 class="rp-name">{$e($c->name)}</h1>
              <div class="rp-muted">{$c->post_count} posts</div>
            </div>
          </div>
          {$bio}
        </div>
        HTML;

        $css = <<<CSS
        .rp-profile{max-width:760px;margin:0 auto;padding:24px 16px}
        .rp-head{display:flex;gap:18px;align-items:center;margin-bottom:18px}
        .rp-name{font-size:26px;font-weight:700;margin:0;color:rgb(var(--c-text))}
        .rp-muted{color:rgb(var(--c-muted));font-size:14px}
        .rp-bio{color:rgb(var(--c-text-2));line-height:1.7;margin:0}
        .rp-mono{color:#fff}
        CSS;

        return ExtPage::render($c->name, $body, $css);
    }
}
