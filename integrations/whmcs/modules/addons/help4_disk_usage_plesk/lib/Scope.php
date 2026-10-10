<?php
namespace Help4\DiskUsagePlesk;

final class Scope
{
    const MAX_PAYLOAD_BYTES = 262144;
    const IDENTITY_TTL = 30;

    public static function read($serviceId, callable $actorReader, callable $entityReader, callable $read)
    {
        try {
            $serviceId = self::id($serviceId);
            $before = self::snapshot($serviceId, $actorReader, $entityReader);
            $request = $before;
            $envelope = $read($request);
            if (!is_array($envelope)) { throw new \RuntimeException(); }
            foreach (['server_id', 'server_binding', 'subscription_guid', 'owner_guid', 'identity_binding', 'revision'] as $key) {
                if (($envelope[$key] ?? null) !== $before[$key]) { throw new \RuntimeException(); }
            }
            $at = $envelope['identity_checked_at'] ?? null;
            if (!is_int($at) || $at < time() - self::IDENTITY_TTL || $at > time() + 5 ||
                !is_array($envelope['payload'] ?? null)) { throw new \RuntimeException(); }
            $encoded = json_encode($envelope['payload'], JSON_THROW_ON_ERROR, 16);
            if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) { throw new \RuntimeException(); }
            // Never return data after a transfer, account switch or mapping change during IO.
            $after = self::snapshot($serviceId, $actorReader, $entityReader);
            if (!hash_equals($before['fingerprint'], $after['fingerprint']) || $at < time() - self::IDENTITY_TTL) {
                throw new \RuntimeException();
            }
            return json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // Do not distinguish a foreign service from a missing service or a failed reader.
            throw new \RuntimeException('Service unavailable');
        }
    }

    private static function snapshot($serviceId, callable $actorReader, callable $entityReader)
    {
        $actor = $actorReader();
        if (!is_array($actor) || ($actor['authenticated'] ?? null) !== true ||
            ($actor['products_allowed'] ?? null) !== true || ($actor['manage_products_allowed'] ?? null) !== true ||
            ($actor['masquerading'] ?? null) !== false) {
            throw new \RuntimeException();
        }
        $userId = self::id($actor['user_id'] ?? null);
        $clientId = self::id($actor['client_id'] ?? null);
        $row = $entityReader($serviceId);
        if (!is_array($row) || !is_array($row['service'] ?? null) || !is_array($row['binding'] ?? null)) {
            throw new \RuntimeException();
        }
        $service = $row['service'];
        $binding = $row['binding'];
        $serverId = self::id($service['server_id'] ?? null);
        if (self::id($service['id'] ?? null) !== $serviceId || self::id($service['client_id'] ?? null) !== $clientId ||
            ($service['status'] ?? null) !== 'Active' || ($service['module'] ?? null) !== 'plesk' ||
            ($service['server_enabled'] ?? null) !== true ||
            self::id($binding['service_id'] ?? null) !== $serviceId ||
            self::id($binding['client_id'] ?? null) !== $clientId ||
            self::id($binding['server_id'] ?? null) !== $serverId) { throw new \RuntimeException(); }
        foreach (['username', 'domain'] as $key) {
            $value = $service[$key] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > 253 || preg_match('/[\x00-\x20\x7f]/', $value) ||
                ($binding[$key] ?? null) !== $value) { throw new \RuntimeException(); }
        }
        $serverBinding = self::digest($service['server_binding'] ?? null);
        if (($binding['server_binding'] ?? null) !== $serverBinding) { throw new \RuntimeException(); }
        $scope = ['schema' => 1, 'user_id' => $userId, 'client_id' => $clientId, 'service_id' => $serviceId,
            'server_id' => $serverId, 'server_binding' => $serverBinding,
            'subscription_guid' => self::guid($binding['subscription_guid'] ?? null),
            'owner_guid' => self::guid($binding['owner_guid'] ?? null),
            'identity_binding' => self::digest($binding['identity_binding'] ?? null),
            'revision' => self::digest($binding['revision'] ?? null),
            'username' => $service['username'], 'domain' => $service['domain']];
        $scope['fingerprint'] = hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
        return $scope;
    }

    public static function id($value)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) ||
            (int)$value > 2147483647) { throw new \RuntimeException('Invalid identity'); }
        return (int)$value;
    }

    private static function digest($value)
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new \RuntimeException(); }
        return $value;
    }

    private static function guid($value)
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $value) ||
            $value === '00000000-0000-0000-0000-000000000000') { throw new \RuntimeException(); }
        return $value;
    }
}
