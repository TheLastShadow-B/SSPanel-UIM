<?php

declare(strict_types=1);

namespace App\Services\Config;

final class ClientConfig
{
    private static ?array $config = null;

    public static function getClients(string $sub, string $name, bool $r2): array
    {
        if (self::$config === null) {
            $file = BASE_PATH . '/config/client_display.json';
            if (! is_readable($file)) {
                throw new \RuntimeException("Client config file not found: {$file}");
            }

            $content = file_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException("Failed to read client config file: {$file}");
            }

            try {
                self::$config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Invalid JSON in client config file: ' . $e->getMessage());
            }
        }

        $result = [];
        foreach (self::$config['clients'] as $client) {
            foreach ($client['platforms'] as $platform => $data) {
                $result[$platform][] = [
                    'name' => $client['name'],
                    'description' => $data['desc'] ?? $client['description'],
                    'format' => $client['format'],
                    // {sub} 处于 scheme URL 的 url= 参数内,必须百分号编码:
                    // Surge 对未编码的嵌套 URL 会静默失败,Clash/Stash 两种形式均接受。
                    // 模板中紧随其后的 /<format> 保持原样,客户端解码后仍还原为完整 URL。
                    'importUrl' => str_replace(
                        ['{sub}', '{name}'],
                        [rawurlencode($sub), rawurlencode($name)],
                        $data['importUrl'] ?? $client['importUrl']
                    ),
                    'downloadUrl' => $data['storeUrl'] ??
                        (isset($data['ext']) ? ($r2 ? '/user' : '') . '/clients/' . ($data['file'] ?? str_replace(' ', '.', $client['name'])) . ".{$data['ext']}" : ''),
                    'isAppStore' => isset($data['storeUrl']),
                ];
            }
        }

        return ['clients' => $result, 'icons' => self::$config['icons']];
    }
}
