<?php

namespace App\Services\Ai\Support;

use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Minimal RFC 6455 WebSocket client (no extensions). Used by the Edge TTS
 * voice provider so TTS works on plain shared hosting without shelling out
 * to a Python/Rust binary. Handles masking, ping/pong, and continuation.
 */
final class WebSocketClient
{
    private $socket = null;

    private bool $connected = false;

    private string $handshakeResponse = '';

    /**
     * @param  array<string, string>  $headers  Extra headers for the upgrade request
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 443,
        private readonly string $path = '/',
        private readonly array $headers = [],
        private readonly int $timeout = 30,
    ) {}

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            "tls://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            throw new AiProviderException("Could not connect to {$this->host}: {$errstr}");
        }

        stream_set_timeout($this->socket, $this->timeout);

        $key = base64_encode(random_bytes(16));
        $request = "GET {$this->path} HTTP/1.1\r\n"
            ."Host: {$this->host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n";

        foreach ($this->headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        $request .= "\r\n";

        fwrite($this->socket, $request);

        // Read the upgrade response (ends at the blank line).
        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = @fread($this->socket, 4096);
            if ($chunk === false || $chunk === '') {
                throw new AiProviderException('WebSocket handshake failed — the server closed the connection.');
            }
            $response .= $chunk;
            if (strlen($response) > 16384) {
                throw new AiProviderException('WebSocket handshake response too large.');
            }
        }

        $this->handshakeResponse = $response;

        if (! preg_match('#^HTTP/1\\.[01] 101#', $response)) {
            throw new AiProviderException('WebSocket handshake rejected: '.substr(trim(explode("\r\n", $response)[0] ?? ''), 0, 120));
        }

        $this->connected = true;
    }

    /**
     * Raw HTTP upgrade response (status line + headers) of the last connect
     * attempt — lets callers read the server Date header for clock-skew
     * correction when a handshake is rejected.
     */
    public function handshakeResponse(): string
    {
        return $this->handshakeResponse;
    }

    public function sendText(string $payload): void
    {
        $this->sendFrame(0x1, $payload);
    }

    public function sendBinary(string $payload): void
    {
        $this->sendFrame(0x2, $payload);
    }

    /**
     * Read the next data frame (auto-answers pings, assembles continuations).
     *
     * @return array{opcode: int, payload: string}
     */
    public function receiveFrame(): array
    {
        while (true) {
            [$fin, $opcode, $payload] = $this->readSingleFrame();

            // Control frames may interleave with data: answer pings, ignore pongs.
            if ($opcode === 0x9) {
                $this->sendRawFrame(0xA, $payload);

                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }
            if ($opcode === 0x8) {
                $this->connected = false;

                return ['opcode' => 0x8, 'payload' => $payload];
            }

            if ($fin) {
                return ['opcode' => $opcode, 'payload' => $payload];
            }

            // Assemble continuation fragments.
            $buffer = $payload;
            while (true) {
                [$fin2, $op2, $payload2] = $this->readSingleFrame();
                if ($op2 === 0x9) {
                    $this->sendRawFrame(0xA, $payload2);

                    continue;
                }
                if ($op2 !== 0x0) {
                    return ['opcode' => $op2, 'payload' => $payload2];
                }
                $buffer .= $payload2;
                if ($fin2) {
                    break;
                }
            }

            return ['opcode' => $opcode, 'payload' => $buffer];
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return array{0: bool, 1: int, 2: string}
     */
    private function readSingleFrame(): array
    {
        $header = $this->readBytes(2);
        $byte0 = ord($header[0]);
        $byte1 = ord($header[1]);

        $fin = ($byte0 & 0x80) !== 0;
        $opcode = $byte0 & 0x0F;
        $masked = ($byte1 & 0x80) !== 0;
        $length = $byte1 & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->readBytes(2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->readBytes(8))[1];
        }

        $maskKey = $masked ? $this->readBytes(4) : '';
        $payload = $this->readBytes($length);

        if ($masked) {
            $payload = $this->applyMask($payload, $maskKey);
        }

        return [$fin, $opcode, $payload];
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $this->sendRawFrame($opcode, $payload);
    }

    private function sendRawFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $maskKey = random_bytes(4);

        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        fwrite($this->socket, $header.$maskKey.$this->applyMask($payload, $maskKey));
    }

    private function readBytes(int $count): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $chunk = @fread($this->socket, $count - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                $reason = ($meta['timed_out'] ?? false) ? 'timed out' : 'closed';
                throw new AiProviderException("WebSocket connection {$reason} while reading.");
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function applyMask(string $payload, string $maskKey): string
    {
        $masked = '';
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return $masked;
    }
}
