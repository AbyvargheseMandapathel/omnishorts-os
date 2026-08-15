<?php

namespace App\Console\Commands;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Wire the recommended AI stack — Groq (text), Pollinations (image), Edge TTS
 * (voice) — into every content type for one or more users. Reuses existing
 * connections when present (a Pollinations text connection with a key is
 * converted to the image provider since both share the same key), creates
 * missing ones, assigns them to all content types, and sets each as the
 * primary provider. Existing fallbacks are left untouched.
 *
 *   php artisan ai:setup-defaults                 # first user
 *   php artisan ai:setup-defaults --user=edxfr3q@gmail.com
 */
class SetupAiDefaults extends Command
{
    protected $signature = 'ai:setup-defaults {--user= : User ID or email — defaults to the first user}';

    protected $description = 'Set Groq (text) / Pollinations (image) / Edge TTS (voice) as the primary AI for every content type';

    public function handle(): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No user found'.($this->option('user') ? " for '{$this->option('user')}'" : ' in the database').'.');

            return self::FAILURE;
        }

        $contentTypes = (array) config('ai.content_types', []);

        $groq = $this->resolveConnection($user, 'groq', 'text', 'Groq');
        $pollinations = $this->resolveConnection($user, 'pollinations_image', 'image', 'Pollinations Image');
        $edgeTts = $this->resolveConnection($user, 'edge_tts', 'voice', 'Edge TTS');

        if (! $groq || ! $pollinations || ! $edgeTts) {
            $this->error('Could not resolve all three connections.');

            return self::FAILURE;
        }

        foreach ([$groq, $pollinations, $edgeTts] as $connection) {
            $connection->syncContentTypes($contentTypes);
            $this->line("  assigned #{$connection->id} '{$connection->name}' ({$connection->provider}) to all content types");
        }

        $roles = [
            'text_primary' => $groq,
            'image_primary' => $pollinations,
            'voice_primary' => $edgeTts,
        ];

        foreach ($contentTypes as $contentType) {
            foreach ($roles as $role => $connection) {
                AiContentTypeConfig::updateOrCreate(
                    ['user_id' => $user->id, 'content_type' => $contentType, 'role' => $role],
                    ['ai_connection_id' => $connection->id]
                );
            }
        }

        $this->info("Configured {$user->email}: every content type now uses {$groq->name} (text), {$pollinations->name} (image), {$edgeTts->name} (voice).");
        $this->warn('Connections created without an API key: open Settings → AI Connections and paste the key (Edge TTS needs none).');

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $option = $this->option('user');

        if ($option) {
            $user = is_numeric($option)
                ? User::find((int) $option)
                : User::where('email', $option)->first();

            return $user ?: null;
        }

        return User::query()->orderBy('id')->first() ?: null;
    }

    /**
     * Find an active connection for the provider, or create one. A Pollinations
     * text connection with a key doubles as the image provider (same key), so
     * it is converted rather than duplicated.
     */
    private function resolveConnection(User $user, string $provider, string $type, string $name): ?AiConnection
    {
        $existing = AiConnection::where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('type', $type)
            ->orderBy('id')
            ->first();

        if ($existing) {
            $this->line("  using existing {$provider} connection #{$existing->id} '{$existing->name}'");

            return $existing;
        }

        // Pollinations shares one key across text/image/voice — a text
        // connection with a key is the user's Pollinations key, so convert it.
        if ($provider === 'pollinations_image') {
            $convert = AiConnection::where('user_id', $user->id)
                ->where('provider', 'pollinations')
                ->where('type', 'text')
                ->orderBy('id')
                ->first();

            if ($convert) {
                $convert->update(['provider' => 'pollinations_image', 'type' => 'image']);
                $this->line("  converted '{$convert->name}' (pollinations text) to pollinations_image — same key works for image");

                return $convert;
            }
        }

        $created = AiConnection::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'provider' => $provider,
            'api_key' => null,
            'model' => null,
            'base_url' => null,
            'config' => null,
            'is_active' => true,
        ]);

        $this->line("  created {$provider} connection #{$created->id} '{$name}' (no API key — set it in Settings)");

        return $created;
    }
}
