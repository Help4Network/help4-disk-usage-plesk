<?php
class Modules_Help4DiskUsage_Access
{
    public static function admin()
    {
        return pm_Session::getClient()->isAdmin() && !pm_Session::isImpersonated();
    }

    public static function authorize($client, $domain, $admin = false)
    {
        if (!($admin && $client->isAdmin()) && !$client->hasAccessToDomain($domain->getId())) {
            throw new RuntimeException('Subscription unavailable');
        }
    }

    public static function authorizeQueued($client, $domain, array $pending)
    {
        self::authorize($client, $domain, ($pending['admin'] ?? false) === true && $client->isAdmin());
    }

    public static function domain($id)
    {
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id)) {
            throw new RuntimeException('Subscription unavailable');
        }
        try {
            $domain = pm_Domain::getByDomainId((int)$id);
            self::authorize(pm_Session::getClient(), $domain, self::admin());
            return $domain;
        } catch (Throwable $e) {
            throw new RuntimeException('Subscription unavailable');
        }
    }

    public static function domains()
    {
        $client = pm_Session::getClient();
        $all = pm_Domain::getAllDomains(true);
        return array_values(array_filter($all, function ($d) use ($client) {
            return self::admin() || $client->hasAccessToDomain($d->getId());
        }));
    }

    public static function binding($domain)
    {
        return hash('sha256', implode('|', [$domain->getGuid(), $domain->getClient()->getId(),
            $domain->getName(), $domain->getHomePath()]));
    }

    public static function relative($path)
    {
        if (!is_string($path) || strlen($path) > 4096 || $path === '' ||
            preg_match('/[\x00-\x1f\\\\:]/', $path) || $path[0] === '/') {
            throw new RuntimeException('Path unavailable');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '..') {
                throw new RuntimeException('Path unavailable');
            }
        }
        return $path;
    }
}
