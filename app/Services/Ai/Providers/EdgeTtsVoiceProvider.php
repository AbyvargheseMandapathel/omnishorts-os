<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Contracts\VoiceResult;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Support\TimingEstimator;
use App\Services\Ai\Support\WebSocketClient;
use Illuminate\Support\Str;

/**
 * Microsoft Edge TTS — the same free neural voices edge-tts exposes, spoken
 * in pure PHP over a WebSocket (no Python/Rust binary needed on the server).
 *
 * Protocol mirrors the reference edge-tts implementation exactly: the
 * Sec-MS-GEC anti-abuse token (SHA-256 of FILETIME ticks + trusted client
 * token, uppercased) goes in the WebSocket URL query, the muid cookie is
 * sent, speech.config + SSML are framed with X-Timestamp/X-RequestId header
 * blocks, binary frames carry a 2-byte header-length prefix, and word
 * boundaries arrive as text frames under Path:audio.metadata. A 403 retries
 * once with the server's Date header used to correct clock skew.
 *
 * WordBoundary events carry per-word offsets/durations which we turn into
 * sentence-level timing for scene sync + captions. No API key required.
 */
class EdgeTtsVoiceProvider extends BaseAiProvider implements VoiceProvider
{
    private const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';

    private const SEC_MS_GEC_VERSION = '1-143.0.3650.75';

    // Seconds between the Windows FILETIME epoch (1601-01-01) and Unix epoch.
    private const WIN_EPOCH = 11644473600;

    private const WSS_HOST = 'speech.platform.bing.com';

    private const WSS_PATH = '/consumer/speech/synthesize/readaloud/edge/v1';

    public function synthesize(string $text, string $voice, string $outputPath, array $config = []): VoiceResult
    {
        $skewSeconds = 0.0;
        $lastError = null;

        // Microsoft occasionally rejects the token when the server clock runs
        // ahead of ours — the reference fixes this by correcting clock skew
        // from the response's Date header and retrying once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $unixTime = (int) (time() + $skewSeconds);
            $client = $this->newClient($unixTime);

            try {
                return $this->synthesizeOnce($client, $text, $voice, $outputPath, $unixTime, $attempt);
            } catch (AiProviderException $e) {
                if (! str_contains($e->getMessage(), '403')) {
                    throw $e;
                }
                $lastError = $e;
                $skewSeconds = $this->clockSkewFromHandshake($client);
            }
        }

        throw new AiProviderException('Edge TTS rejected the request twice (HTTP 403): '.($lastError?->getMessage() ?? 'unknown'));
    }

    private function newClient(int $unixTime): WebSocketClient
    {
        $connectionId = str_replace('-', '', (string) Str::uuid());

        return new WebSocketClient(
            self::WSS_HOST,
            443,
            self::WSS_PATH
                .'?TrustedClientToken='.self::TRUSTED_CLIENT_TOKEN
                .'&ConnectionId='.$connectionId
                .'&Sec-MS-GEC='.$this->secMsGec($unixTime)
                .'&Sec-MS-GEC-Version='.self::SEC_MS_GEC_VERSION,
            [
                'Pragma' => 'no-cache',
                'Cache-Control' => 'no-cache',
                'Origin' => 'chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',
                'Accept-Encoding' => 'gzip, deflate, br, zstd',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cookie' => 'muid='.strtoupper(bin2hex(random_bytes(16))).';',
            ],
            (int) $this->option('timeout', 60)
        );
    }

    private function synthesizeOnce(WebSocketClient $client, string $text, string $voice, string $outputPath, int $unixTime, int $attempt): VoiceResult
    {
        try {
            $client->connect();

            $timestamp = $this->utcDate($unixTime);

            // speech.config — framed like the reference: header block + JSON.
            $configMessage = "X-Timestamp:{$timestamp}\r\n"
                ."Content-Type:application/json; charset=utf-8\r\n"
                ."Path:speech.config\r\n\r\n"
                .json_encode([
                    'context' => [
                        'synthesis' => [
                            'audio' => [
                                'metadataoptions' => [
                                    'sentenceBoundaryEnabled' => 'true',
                                    'wordBoundaryEnabled' => 'false',
                                ],
                                'outputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES);

            $client->sendText($configMessage);

            $escaped = htmlspecialchars($this->removeIncompatibleCharacters($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $lang = $this->languageForVoice($voice);
            $ssml = "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='{$lang}'>"
                ."<voice name='{$voice}'><prosody pitch='+0Hz' rate='+0%' volume='+0%'>{$escaped}</prosody></voice>"
                .'</speak>';

            // SSML — note the trailing Z on X-Timestamp (a known Edge bug the
            // reference deliberately reproduces).
            $requestId = str_replace('-', '', (string) Str::uuid());
            $ssmlMessage = "X-RequestId:{$requestId}\r\n"
                ."Content-Type:application/ssml+xml\r\n"
                ."X-Timestamp:{$timestamp}Z\r\n"
                ."Path:ssml\r\n\r\n"
                .$ssml;

            $client->sendText($ssmlMessage);

            $audio = '';
            $sentences = [];
            $turnEnded = false;

            while (! $turnEnded) {
                $frame = $client->receiveFrame();

                if ($frame['opcode'] === 0x8) {
                    break; // server closed
                }

                if ($frame['opcode'] === 0x2) {
                    // Binary frames: [2-byte header length][headers ending
                    // with \r\n][audio]. No blank line — the audio starts at
                    // headerLength + 2 (the +2 skips the final \r\n).
                    $payload = $frame['payload'];
                    if (strlen($payload) < 4) {
                        continue;
                    }
                    $headerLength = unpack('n', substr($payload, 0, 2))[1];
                    if ($headerLength + 2 > strlen($payload)) {
                        continue;
                    }
                    $headers = substr($payload, 2, $headerLength - 2);
                    if (str_contains($headers, 'Path:audio')) {
                        $audio .= substr($payload, $headerLength + 2);
                    }
                } elseif ($frame['opcode'] === 0x1) {
                    // Text frames: [header block]\r\n\r\n[body].
                    $payload = $frame['payload'];
                    $separator = strpos($payload, "\r\n\r\n");
                    if ($separator === false) {
                        continue;
                    }
                    $headers = substr($payload, 0, $separator);
                    $body = substr($payload, $separator + 4);

                    if (str_contains($headers, 'Path:turn.end')) {
                        $turnEnded = true;
                    } elseif (str_contains($headers, 'Path:audio.metadata')) {
                        $data = json_decode($body, true);
                        foreach ((array) ($data['Metadata'] ?? []) as $meta) {
                            // SentenceBoundary carries the full punctuated
                            // sentence — exactly what scene sync + captions
                            // need. (WordBoundary words arrive unpunctuated.)
                            if (($meta['Type'] ?? '') !== 'SentenceBoundary' || ! isset($meta['Data'])) {
                                continue;
                            }
                            $textValue = $meta['Data']['text']['Text'] ?? null;
                            if (! is_string($textValue) || trim($textValue) === '') {
                                continue;
                            }
                            // Offsets arrive in 100ns units → milliseconds.
                            $sentences[] = [
                                'text' => trim($textValue),
                                'offset_ms' => (int) round(((int) ($meta['Data']['Offset'] ?? 0)) / 10000),
                                'duration_ms' => (int) round(((int) ($meta['Data']['Duration'] ?? 0)) / 10000),
                            ];
                        }
                    }
                }
            }
        } catch (AiProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiProviderException('Edge TTS failed: '.$e->getMessage());
        } finally {
            $client->close();
        }

        if ($audio === '') {
            throw new AiProviderException('Edge TTS returned no audio — check the voice name and network access.');
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outputPath, $audio);

        // Fall back to estimated timing only if no boundaries came through.
        if ($sentences === []) {
            $sentences = TimingEstimator::estimate($text);
        }
        $duration = $sentences === []
            ? max(1.0, (float) strlen($audio) / 6000)
            : (float) (end($sentences)['offset_ms'] + end($sentences)['duration_ms']) / 1000;

        return new VoiceResult($outputPath, max(0.1, $duration), $sentences);
    }

    /**
     * Sec-MS-GEC anti-abuse token — exact port of the reference
     * (edge-tts drm.py): Windows FILETIME seconds rounded down to the nearest
     * 5 minutes, converted to 100ns ticks, concatenated with the trusted
     * client token, SHA-256, uppercased.
     */
    private function secMsGec(int $unixTime): string
    {
        $ticks = $unixTime + self::WIN_EPOCH;
        $ticks -= $ticks % 300;
        $ticks *= 10_000_000;

        return strtoupper(hash('sha256', sprintf('%.0f', $ticks).self::TRUSTED_CLIENT_TOKEN));
    }

    /**
     * On a 403, use the server's Date header to estimate clock skew so the
     * retry mints a token the server accepts.
     */
    private function clockSkewFromHandshake(WebSocketClient $client): float
    {
        if (preg_match('/^Date:\s*(.+)$/mi', $client->handshakeResponse(), $m)) {
            $serverTime = strtotime(trim($m[1]));
            if ($serverTime !== false) {
                return (float) ($serverTime - time());
            }
        }

        return 0.0;
    }

    private function utcDate(int $unixTime): string
    {
        return gmdate('D M d Y H:i:s', $unixTime).' GMT+0000 (Coordinated Universal Time)';
    }

    private function languageForVoice(string $voice): string
    {
        if (preg_match('/^([a-z]{2})-[A-Z]{2}/', $voice, $m)) {
            return $m[1];
        }

        return 'en';
    }

    /**
     * The service rejects a couple of control-character ranges (most notably
     * the vertical tab found in OCR-ed text).
     */
    private function removeIncompatibleCharacters(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);
            $out .= (($code >= 0 && $code <= 8) || ($code >= 11 && $code <= 12) || ($code >= 14 && $code <= 31)) ? ' ' : $char;
        }

        return $out;
    }
}
