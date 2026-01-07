<?php
declare(strict_types=1);

namespace HBIDamian\wsRCON;

class FrameHandler {
    
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    // WebSocket opcodes
    private const OPCODE_CONTINUATION = 0;
    private const OPCODE_TEXT = 1;
    private const OPCODE_BINARY = 2;
    private const OPCODE_CLOSE = 8;
    private const OPCODE_PING = 9;
    private const OPCODE_PONG = 10;
    
    // Special markers for control frames
    public const MARKER_CLOSE = "\x00__CLOSE__\x00";
    public const MARKER_PING = "\x00__PING__\x00";
    
    public function decodeFrame(string &$data): ?string {
        if (strlen($data) < 2) return null;
        
        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);
        
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte >> 7) & 1;
        $payloadLen = $secondByte & 0x7F;
        
        $offset = 2;
        
        if ($payloadLen === 126) {
            if (strlen($data) < 4) return null;
            $payloadLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            if (strlen($data) < 10) return null;
            $payloadLen = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }
        
        if ($masked) {
            if (strlen($data) < $offset + 4) return null;
            $maskKey = substr($data, $offset, 4);
            $offset += 4;
        }
        
        if (strlen($data) < $offset + $payloadLen) return null;
        
        $payload = substr($data, $offset, $payloadLen);
        
        if ($masked) {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
        }
        
        // Remove processed frame from buffer
        $data = substr($data, $offset + $payloadLen);
        
        // Handle different opcodes
        switch ($opcode) {
            case self::OPCODE_TEXT:
                $this->plugin->debugLog("Decoded WebSocket text frame: " . substr($payload, 0, 100));
                return $payload;
                
            case self::OPCODE_CLOSE:
                $this->plugin->debugLog("Received WebSocket close frame");
                return self::MARKER_CLOSE;
                
            case self::OPCODE_PING:
                $this->plugin->debugLog("Received WebSocket ping frame");
                return self::MARKER_PING . $payload;
                
            case self::OPCODE_PONG:
                $this->plugin->debugLog("Received WebSocket pong frame");
                return null; // Ignore pong frames
                
            case self::OPCODE_BINARY:
                $this->plugin->debugLog("Received binary frame (not supported), ignoring");
                return null;
                
            case self::OPCODE_CONTINUATION:
                $this->plugin->debugLog("Received continuation frame (not supported), ignoring");
                return null;
                
            default:
                $this->plugin->debugLog("Received unknown opcode: " . $opcode);
                return null;
        }
    }
    
    public function encodeFrame(string $message): string {
        $length = strlen($message);
        $frame = chr(0x81); // Text frame with FIN bit
        
        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }
        
        return $frame . $message;
    }
    
    public function sendMessage(mixed $socket, string $message): void {
        if (!SocketUtils::isValidSocket($socket)) {
            $this->plugin->debugLog("Invalid socket type: " . gettype($socket));
            return;
        }
        
        $frame = $this->encodeFrame($message);
        $result = @socket_write($socket, $frame);
        
        if ($result === false) {
            $this->plugin->debugLog("Failed to write WebSocket frame to socket");
        } else {
            $this->plugin->debugLog("Successfully sent {$result} bytes to socket");
        }
    }
    
    public function sendRawMessage(mixed $socket, string $message): void {
        if (!SocketUtils::isValidSocket($socket)) {
            return;
        }
        @socket_write($socket, $message);
    }
    
    /**
     * Send a pong frame in response to a ping
     */
    public function sendPong(mixed $socket, string $payload = ''): void {
        if (!SocketUtils::isValidSocket($socket)) {
            return;
        }
        
        $length = strlen($payload);
        $frame = chr(0x8A); // Pong frame with FIN bit (0x80 | 0x0A)
        
        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }
        
        $frame .= $payload;
        @socket_write($socket, $frame);
        $this->plugin->debugLog("Sent pong frame");
    }
    
    /**
     * Send a close frame
     */
    public function sendClose(mixed $socket, int $code = 1000, string $reason = ''): void {
        if (!SocketUtils::isValidSocket($socket)) {
            return;
        }
        
        $payload = pack('n', $code) . $reason;
        $length = strlen($payload);
        $frame = chr(0x88); // Close frame with FIN bit (0x80 | 0x08)
        
        if ($length < 126) {
            $frame .= chr($length);
        } else {
            $frame .= chr(126) . pack('n', $length);
        }
        
        $frame .= $payload;
        @socket_write($socket, $frame);
        $this->plugin->debugLog("Sent close frame with code: " . $code);
    }
    
    /**
     * Check if message is a control frame marker
     */
    public function isCloseMarker(?string $message): bool {
        return $message === self::MARKER_CLOSE;
    }
    
    /**
     * Check if message is a ping marker and extract payload
     */
    public function isPingMarker(?string $message): bool {
        return $message !== null && str_starts_with($message, self::MARKER_PING);
    }
    
    /**
     * Extract ping payload from marker
     */
    public function getPingPayload(string $message): string {
        return substr($message, strlen(self::MARKER_PING));
    }
}
