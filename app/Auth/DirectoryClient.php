<?php

namespace App\Auth;

use RuntimeException;

class DirectoryClient
{
    public function verify(array $config, string $username, string $password): ?string
    {
        if ($password === '' || ! $config['uri'] || ! $config['base_dn'] || ! $config['bind_dn'] || ! $config['bind_password']) {
            throw new RuntimeException('Directory configuration is incomplete.');
        }
        if (! str_starts_with($config['uri'], 'ldaps://') && ! $config['start_tls']) {
            throw new RuntimeException('An encrypted LDAP connection is required.');
        }
        $connection = ldap_connect($config['uri']);
        if (! $connection) {
            throw new RuntimeException('Directory connection failed.');
        }
        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);
            if (! str_starts_with($config['uri'], 'ldaps://') && ! @ldap_start_tls($connection)) {
                throw new RuntimeException('Directory TLS failed.');
            }
            if (! @ldap_bind($connection, $config['bind_dn'], $config['bind_password'])) {
                throw new RuntimeException('Directory service bind failed.');
            }
            $filter = '('.$config['login_attribute'].'='.ldap_escape($username, '', LDAP_ESCAPE_FILTER).')';
            $search = @ldap_search($connection, $config['base_dn'], $filter, ['entryUUID', 'objectGUID'], 0, 2, 5);
            if ($search === false) {
                throw new RuntimeException('Directory search failed.');
            }
            $entries = ldap_get_entries($connection, $search);
            if ($entries['count'] !== 1 || ! @ldap_bind($connection, $entries[0]['dn'], $password)) {
                return null;
            }

            return isset($entries[0]['objectguid'][0])
                ? bin2hex($entries[0]['objectguid'][0])
                : ($entries[0]['entryuuid'][0] ?? null);
        } finally {
            ldap_unbind($connection);
        }
    }
}
