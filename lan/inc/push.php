<?php
declare(strict_types=1);

/*
 * Notifications push (Web Push, RFC 8030 / 8291 / 8292) sans librairie :
 * clés VAPID (P-256) créées au premier usage et gardées dans la base (dossier privé),
 * contenu chiffré en aes128gcm, envoi avec curl.
 */

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/** Clé publique brute (point non compressé, 65 octets) → clé PEM utilisable par openssl */
function ec_public_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Point public brut (0x04 || X || Y) d'une clé EC */
function ec_public_raw(OpenSSLAsymmetricKey $key): string
{
    $ec = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
}

function ec_new_key(): OpenSSLAsymmetricKey
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** Clés VAPID du site : ['public' => base64url (pour le navigateur), 'private' => clé openssl] */
function vapid_keys(): array
{
    $pem = setting('vapid_private');
    if ($pem === '') {
        openssl_pkey_export(ec_new_key(), $pem);
        set_setting('vapid_private', $pem);
    }
    $key = openssl_pkey_get_private($pem);
    return ['public' => b64url(ec_public_raw($key)), 'private' => $key];
}

/** Signature ES256 (format JOSE r || s) d'un jeton VAPID */
function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $parts = parse_url($endpoint);
    $claims = [
        'aud' => $parts['scheme'] . '://' . $parts['host'],
        'exp' => time() + 12 * 3600,
        'sub' => (string) config('push_contact'),
    ];
    $input = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
    openssl_sign($input, $der, $keys['private'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $input . '.' . b64url(der_to_jose($der)) . ', k=' . $keys['public'];
}

/** Signature ECDSA DER (SEQUENCE de deux INTEGER) → 64 octets r || s */
function der_to_jose(string $der): string
{
    $offset = 2;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $int = substr($der, $offset + 2, $length);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $length;
    }
    return $out;
}

/** Chiffrement du contenu pour un abonnement (RFC 8291, aes128gcm, un seul bloc) */
function webpush_encrypt(string $payload, string $userPublic, string $userAuth): string
{
    $local = ec_new_key();
    $localPublic = ec_public_raw($local);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(ec_public_pem($userPublic)), $local, 32);
    $salt = random_bytes(16);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $userPublic . $localPublic, $userAuth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(strlen($localPublic)) . $localPublic . $cipher . $tag;
}

/**
 * Envoie une notification à un abonnement. Renvoie le code HTTP du service push
 * (201 = envoyé ; 404 / 410 = abonnement expiré, à supprimer ; 0 = pas de réponse).
 */
function webpush_send(array $subscription, array $message): int
{
    $body = webpush_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        b64url_decode($subscription['p256dh']), b64url_decode($subscription['auth']));
    $curl = curl_init($subscription['endpoint']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 900',
            'Urgency: high',
            'Authorization: ' . vapid_header($subscription['endpoint']),
        ],
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $status;
}

/** Envoie à tous les abonnés (ou à ceux d'un compte) ; supprime les abonnements expirés. Renvoie le nombre d'envois réussis. */
function push_to_all(array $message, ?int $userId = null): int
{
    $subscriptions = $userId === null
        ? db_rows('SELECT * FROM push_subscriptions')
        : db_rows('SELECT * FROM push_subscriptions WHERE user_id = ?', [$userId]);
    $sent = 0;
    foreach ($subscriptions as $subscription) {
        $status = webpush_send($subscription, $message);
        if ($status >= 200 && $status < 300) {
            $sent++;
            db_exec('UPDATE push_subscriptions SET last_ok_at = CURRENT_TIMESTAMP, failures = 0 WHERE id = ?', [$subscription['id']]);
        } elseif (in_array($status, [404, 410], true) || (int) $subscription['failures'] >= 20) {
            db_exec('DELETE FROM push_subscriptions WHERE id = ?', [$subscription['id']]);
        } else {
            db_exec('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?', [$subscription['id']]);
        }
    }
    return $sent;
}
