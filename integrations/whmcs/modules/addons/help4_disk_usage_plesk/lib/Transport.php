<?php
namespace Help4\DiskUsagePlesk;

final class Transport
{
    const MAX_BYTES = 524288;
    const DEADLINE = 20;

    public static function endpoint(array $server)
    {
        $host = $server['hostname'] ?? '';
        if ($host === '') { $host = $server['ipaddress'] ?? ''; }
        if (!is_string($host) || strlen($host) > 253 || $host === '') { throw new \RuntimeException('Invalid server endpoint'); }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $host = '[' . $host . ']'; }
        elseif (!filter_var($host, FILTER_VALIDATE_IP) &&
            (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/Di', $host) || strpos($host, '..') !== false)) {
            throw new \RuntimeException('Invalid server endpoint');
        }
        $port = $server['port'] ?? 8443;
        if ($port === '' || $port === 0 || $port === '0') { $port = 8443; }
        if ((!is_int($port) && !is_string($port)) || !preg_match('/^[1-9][0-9]{0,4}$/D', (string)$port) || (int)$port > 65535) {
            throw new \RuntimeException('Invalid server endpoint');
        }
        return 'https://' . strtolower($host) . ':' . (int)$port;
    }

    public static function binding(array $server, $installation)
    {
        self::digest($installation);
        return hash('sha256', json_encode([Scope::id($server['id']), self::endpoint($server), $installation], JSON_THROW_ON_ERROR));
    }

    public static function call(array $server, $installation, $operation, array $fields = [], ?callable $io = null)
    {
        if (!in_array($operation, ['hello', 'identity', 'report', 'refresh', 'health'], true)) { throw new \RuntimeException('Integration unavailable'); }
        if (!in_array($operation, ['hello', 'identity'], true)) { self::digest($installation); }
        $nonce = bin2hex(random_bytes(32));
        $request = ['schema' => 1, 'operation' => $operation, 'nonce' => $nonce];
        if ($installation !== null) { $request['installation_id'] = self::digest($installation); }
        $request += $fields;
        $json = json_encode($request, JSON_THROW_ON_ERROR, 8);
        if (strlen($json) > 8192) { throw new \RuntimeException('Integration unavailable'); }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><packet version="1.6.9.1"><extension><call>' .
            '<help4-disk-usage><audit><request>' . base64_encode($json) . '</request></audit></help4-disk-usage>' .
            '</call></extension></packet>';
        $url = self::endpoint($server) . '/enterprise/control/agent.php';
        $raw = $io ? $io($url, $xml) : self::https($server, $url, $xml);
        $response = self::decode($raw);
        if (($response['schema'] ?? null) !== 1 || ($response['nonce'] ?? null) !== $nonce ||
            !is_int($response['identity_checked_at'] ?? null) || $response['identity_checked_at'] < time() - 30 ||
            $response['identity_checked_at'] > time() + 5 || !is_array($response['payload'] ?? null)) {
            throw new \RuntimeException('Integration unavailable');
        }
        self::digest($response['installation_id'] ?? null);
        if ($installation !== null && $response['installation_id'] !== $installation) {
            throw new \RuntimeException('Integration unavailable');
        }
        return $response;
    }

    public static function scoped(array $server, $installation, array $scope, $operation = 'report', ?callable $io = null)
    {
        $expected = array_intersect_key($scope, array_flip(['subscription_guid', 'owner_guid', 'identity_binding', 'domain', 'username']));
        $response = self::call($server, $installation, $operation, ['scope' => $expected], $io);
        $identity = $response['identity'] ?? [];
        foreach ($expected as $key => $value) {
            if (($identity[$key] ?? null) !== $value) { throw new \RuntimeException('Service unavailable'); }
        }
        return array_intersect_key($scope, array_flip(['server_id', 'server_binding', 'subscription_guid',
            'owner_guid', 'identity_binding', 'revision'])) +
            ['identity_checked_at' => $response['identity_checked_at'], 'payload' => $response['payload']];
    }

    private static function https(array $server, $url, $xml)
    {
        $headers = ['Content-Type: text/xml; charset=UTF-8', 'Accept: text/xml'];
        $key = $server['accesshash'] ?? '';
        if ($key !== '') { $headers[] = 'KEY: ' . self::header($key); }
        else {
            $headers[] = 'HTTP_AUTH_LOGIN: ' . self::header($server['username'] ?? '');
            $headers[] = 'HTTP_AUTH_PASSWD: ' . self::header($server['password'] ?? '');
        }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => self::DEADLINE,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'Help4-Disk-Usage-Plesk/preview',
            CURLOPT_WRITEFUNCTION => function ($handle, $part) use (&$body) {
                if (strlen($body) + strlen($part) > self::MAX_BYTES) { return 0; }
                $body .= $part;
                return strlen($part);
            }]);
        try {
            if (!curl_exec($curl) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Integration unavailable');
            }
            return $body;
        } finally { curl_close($curl); }
    }

    private static function header($value)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \RuntimeException('Invalid server credentials');
        }
        return $value;
    }

    private static function digest($value)
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new \RuntimeException('Integration unavailable'); }
        return $value;
    }

    private static function decode($raw)
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES || strpos($raw, "\0") !== false ||
            preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $raw)) { throw new \RuntimeException('Integration unavailable'); }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($raw, LIBXML_NONET) || $dom->doctype || $dom->documentElement->tagName !== 'packet') {
                throw new \RuntimeException('Integration unavailable');
            }
            $query = new \DOMXPath($dom);
            $results = $query->query('/packet/extension/call/result');
            $values = $query->query('/packet/extension/call/result/help4-disk-usage/audit/response');
            $status = $query->query('/packet/extension/call/result/status');
            if ($results->length !== 1 || $values->length !== 1 || $status->length !== 1 ||
                $status->item(0)->textContent !== 'ok' || $values->item(0)->childElementCount !== 0) {
                throw new \RuntimeException('Integration unavailable');
            }
            $json = base64_decode($values->item(0)->textContent, true);
            if ($json === false || strlen($json) > 350000) { throw new \RuntimeException('Integration unavailable'); }
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new \RuntimeException('Integration unavailable'); }
            return $value;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
}
