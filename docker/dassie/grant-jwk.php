<?php

// Grant (with a `jwk` client), create an incoming payment and read it back,
// against one wallet address. Used to test the library against Dassie nodes,
// which cannot fetch the keys of a wallet address on this machine.
//
// Usage: php docker/dassie/grant-jwk.php <wallet address URL>
// Env:   PRIVATE_KEY (base64 PEM), KEY_ID

require __DIR__.'/../../vendor/autoload.php';

use OpenPayments\AuthClient;
use OpenPayments\Config\Config;

use function OpenPayments\Utils\construct64BytePrivateKey;
use function OpenPayments\Utils\extractSeedFromPem;
use function OpenPayments\Utils\generateJwk;

$walletUrl = $argv[1] ?? null;
$privateKey = getenv('PRIVATE_KEY');
$keyId = getenv('KEY_ID');
if (! $walletUrl || ! $privateKey || ! $keyId) {
    fwrite(STDERR, "usage: PRIVATE_KEY=... KEY_ID=... php grant-jwk.php <wallet address URL>\n");
    exit(2);
}

$config = new Config($walletUrl, $privateKey, $keyId);
$client = new AuthClient($config);
$jwk = generateJwk($keyId, construct64BytePrivateKey(extractSeedFromPem(base64_decode($privateKey))));

function step(string $name, callable $fn): mixed
{
    try {
        $result = $fn();
        echo "OK    $name\n";

        return $result;
    } catch (\Throwable $e) {
        $msg = preg_replace('/\s+/', ' ', $e->getMessage());
        echo "FAIL  $name: ".substr($msg, 0, 300)."\n";
        exit(1);
    }
}

$wallet = step('wallet address', fn () => $client->walletAddress()->get(['url' => $walletUrl]));
echo "      authServer = {$wallet->authServer}\n";

$grant = step('grant request', function () use ($client, $wallet, $jwk) {
    $grant = $client->grant()->request(
        ['url' => $wallet->authServer],
        [
            'access_token' => ['access' => [['type' => 'incoming-payment', 'actions' => ['create', 'read']]]],
            'client' => ['jwk' => $jwk],
        ]
    );
    if (! $grant instanceof \OpenPayments\Models\Grant || $grant->access_token === null) {
        throw new \RuntimeException('expected a non-interactive grant with an access token');
    }

    return $grant;
});

$payment = step('incoming payment create', fn () => $client->incomingPayment()->create(
    ['url' => $wallet->resourceServer, 'access_token' => $grant->access_token->value],
    [
        'walletAddress' => $walletUrl,
        'incomingAmount' => ['value' => '130', 'assetCode' => $wallet->assetCode, 'assetScale' => $wallet->assetScale],
        'expiresAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->add(new \DateInterval('PT59M'))->format('Y-m-d\TH:i:s.v\Z'),
    ]
));
echo "      id = {$payment->id}\n";

step('incoming payment get', fn () => $client->incomingPayment()->get(
    ['url' => $payment->id, 'access_token' => $grant->access_token->value]
));
