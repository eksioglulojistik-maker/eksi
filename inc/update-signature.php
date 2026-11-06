<?php
/**
 * Verify the exact manifest bytes before trusting any ZIP metadata.
 * The release public key is provisioned separately from update archives.
 */
function ekVerifyUpdateManifest(string $manifest, string $signature, string $publicKey): bool {
    if ($manifest === '' || strlen($manifest) > 65536 ||
        $signature === '' || strlen($signature) > 2048 || $publicKey === '' ||
        !function_exists('openssl_verify')) {
        return false;
    }
    $decoded = base64_decode(trim($signature), true);
    if ($decoded === false || $decoded === '') {
        return false;
    }
    $key = openssl_pkey_get_public($publicKey);
    return $key !== false && openssl_verify($manifest, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
}

function ekUpdatePublicKey(): string {
    return <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAv8FIo4wZHPd76pjgGxGd
XYiNAO0Y1Zny/oxxftmfnXOvuC9mopbRXYzL1WfHK8N8gzUqENjo9Q8jpg8dNTVP
k4fQHRZ/OyRUGqZUyLmfGpF7FzK+xfqSeLFSjVhZf72hxh/tgbVBn2YmMDPeAtA9
BtVVt3yGHJzb0EbphGXbBr3H+m7Uqt196681f0zui14Jc5UrR6pKwKUQkNWseygr
XWmcMjoHoSdbUyLVt5CDVqqKXmJ/SShS1RB9QCWDNbhV67T8YMrJ9f+4ateR95wD
4gsnsgMKyydvaJPXus6NOwkkUguTtkqLUKxDbyBK9TgVXiIqIuACHuM3OWHqxmgb
n/PXFMpsEYb/QqcsMHvyOEkOOD/6g/2NDCdsBemy0cRg8QYzDz4st6oHZZZfNh9b
2woDNYF8klTlNUSTUvOP42khJqsIrzSz1m2C9sxIXiyfr7ikkmaDhaIhVehEZk7k
6kNrxDtrZTSnwpfiTz44sn+mmOEKaOII3/xk8xT4YBnDAgMBAAE=
-----END PUBLIC KEY-----
PEM;
}
