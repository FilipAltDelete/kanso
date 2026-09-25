<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// A throwaway JWT keypair for the run, unless the environment brings one.
if ('' === (string) ($_SERVER['JWT_SECRET_KEY'] ?? getenv('JWT_SECRET_KEY'))) {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    if (false === $key || !openssl_pkey_export($key, $private) || false === ($details = openssl_pkey_get_details($key))) {
        throw new RuntimeException('Could not generate a JWT keypair for the test run.');
    }

    foreach (['JWT_SECRET_KEY' => $private, 'JWT_PUBLIC_KEY' => $details['key']] as $name => $pem) {
        $value = base64_encode((string) $pem);
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }
}

// The functional suite needs the schema; migrating is a no-op when it is current.
passthru(sprintf(
    '%s %s/bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet',
    PHP_BINARY,
    dirname(__DIR__),
), $exitCode);

if (0 !== $exitCode) {
    throw new RuntimeException('Migrating the test database failed; is MySQL up (make up)?');
}
