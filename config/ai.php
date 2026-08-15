<?php

use App\Services\Ai\Providers\EdgeTtsVoiceProvider;
use App\Services\Ai\Providers\ElevenLabsVoiceProvider;
use App\Services\Ai\Providers\GeminiTextProvider;
use App\Services\Ai\Providers\GroqTextProvider;
use App\Services\Ai\Providers\HuggingFaceImageProvider;
use App\Services\Ai\Providers\OpenAIImageProvider;
use App\Services\Ai\Providers\OpenAITextProvider;
use App\Services\Ai\Providers\PollinationsImageProvider;
use App\Services\Ai\Providers\PollinationsVoiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Content Types
    |--------------------------------------------------------------------------
    |
    | The content types an AI connection can be assigned to and a pipeline can
    | be generated for. Each gets its own primary/fallback provider config in
    | Settings → Content Type AI.
    |
    */

    'content_types' => ['video', 'shorts', 'story', 'news', 'anime', 'blog', 'podcast'],

    /*
    |--------------------------------------------------------------------------
    | Provider Roles
    |--------------------------------------------------------------------------
    |
    | Each content type configures a primary and a fallback for each of the
    | three AI kinds. The fallback is used when the primary times out, is rate
    | limited, or otherwise fails.
    |
    */

    'roles' => [
        'text_primary',
        'text_fallback',
        'image_primary',
        'image_fallback',
        'voice_primary',
        'voice_fallback',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Registry
    |--------------------------------------------------------------------------
    |
    | Every supported provider, its AI kind, and the class that talks to it.
    | Adding a provider = adding one entry here plus a class implementing the
    | matching contract. Nothing in the pipeline hard-codes a vendor.
    |
    */

    'providers' => [
        // Text
        'groq' => ['type' => 'text', 'class' => GroqTextProvider::class],
        'openai' => ['type' => 'text', 'class' => OpenAITextProvider::class],
        'gemini' => ['type' => 'text', 'class' => GeminiTextProvider::class],
        // Pollinations is OpenAI-compatible for chat (text) — same provider.
        'pollinations' => ['type' => 'text', 'class' => OpenAITextProvider::class],
        // Image
        'huggingface' => ['type' => 'image', 'class' => HuggingFaceImageProvider::class],
        'openai_image' => ['type' => 'image', 'class' => OpenAIImageProvider::class],
        'pollinations_image' => ['type' => 'image', 'class' => PollinationsImageProvider::class],
        // Voice
        'elevenlabs' => ['type' => 'voice', 'class' => ElevenLabsVoiceProvider::class],
        'edge_tts' => ['type' => 'voice', 'class' => EdgeTtsVoiceProvider::class],
        'pollinations_voice' => ['type' => 'voice', 'class' => PollinationsVoiceProvider::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Provider Defaults
    |--------------------------------------------------------------------------
    |
    | Default model / voice used when the connection doesn't set one. Voices
    | can also be selected per content type via the connection's config.
    |
    */

    'defaults' => [
        'groq' => ['model' => 'llama-3.3-70b-versatile'],
        'openai' => ['model' => 'gpt-4o-mini'],
        'gemini' => ['model' => 'gemini-2.0-flash'],
        'pollinations' => ['model' => 'openai', 'base_url' => 'https://gen.pollinations.ai/v1'],
        'huggingface' => ['model' => 'black-forest-labs/FLUX.1-schnell'],
        'openai_image' => ['model' => 'gpt-image-1'],
        'pollinations_image' => ['model' => 'flux', 'base_url' => 'https://gen.pollinations.ai'],
        'elevenlabs' => ['model' => 'eleven_multilingual_v2'],
        'edge_tts' => ['voice' => 'en-US-ChristopherNeural'],
        'pollinations_voice' => ['voice' => 'nova', 'model' => 'elevenlabs', 'base_url' => 'https://gen.pollinations.ai'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Voice per Language
    |--------------------------------------------------------------------------
    |
    | Used when a pipeline doesn't request a specific voice: pick the best
    | Edge TTS neural voice for the configured language.
    |
    */

    'voices' => [
        'en' => 'en-US-ChristopherNeural',
        'hi' => 'hi-IN-MadhurNeural',
        'es' => 'es-ES-AlvaroNeural',
        'fr' => 'fr-FR-HenriNeural',
        'de' => 'de-DE-ConradNeural',
        'pt' => 'pt-BR-AntonioNeural',
        'ar' => 'ar-SA-HamedNeural',
        'bn' => 'bn-IN-BashkarNeural',
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation Defaults
    |--------------------------------------------------------------------------
    */

    'scenes_count' => 5,
    'scenes_count_options' => [3, 5, 6, 8, 10],
    'words_per_caption_line' => 5,
    'languages' => ['en', 'hi', 'es', 'fr', 'de', 'pt', 'ar', 'bn'],
    'tones' => ['engaging', 'informative', 'funny', 'dramatic', 'inspirational', 'educational', 'casual'],

    /*
    |--------------------------------------------------------------------------
    | Default Image Style
    |--------------------------------------------------------------------------
    |
    | Appended to every image prompt unless the content type overrides it.
    | Intentionally forbids text/watermarks/logos on screen.
    |
    */

    'image_style' => 'Photorealistic documentary photography, cinematic lighting, detailed scene, professional camera, vertical composition, no text, no captions, no logos, no watermarks',

    /*
    |--------------------------------------------------------------------------
    | FFmpeg
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Daily Auto-Generation
    |--------------------------------------------------------------------------
    |
    | Defaults for the hands-free daily flow (ai:generate-daily). The topic
    | list rotates one per day; an empty list makes the text AI propose a
    | topic instead. Background = a configured path on the video disk, or a
    | generated black video when none is set. All of this is editable in
    | Settings → Daily Auto-Generation.
    |
    */

    'daily' => [
        'time' => env('AI_DAILY_TIME', '06:00'),
        'content_type' => env('AI_DAILY_CONTENT_TYPE', 'video'),
        'topics' => [
            'The strange intelligence of octopuses',
            'How bees communicate through dance',
            'Why the sky is blue',
            'The science of why we dream',
            '3 surprising facts about the deep sea',
            'How your brain creates memories',
        ],
    ],

    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'fps' => (int) env('AI_FPS', 30),
    'crf' => (int) env('AI_CRF', 23),
    'preset' => env('AI_PRESET', 'veryfast'),
    'video_codec' => env('AI_VIDEO_CODEC', 'libx264'),
    'audio_codec' => env('AI_AUDIO_CODEC', 'aac'),
    'render_timeout' => (int) env('AI_RENDER_TIMEOUT', 900),

];
