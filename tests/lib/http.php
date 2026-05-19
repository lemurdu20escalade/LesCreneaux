<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Client HTTP minimaliste pour le harnais — basé sur stream_context_create
// pour éviter une dépendance à ext-curl en CLI. Helpers de signature CSRF
// alignés sur app/src/Csrf.php.

/**
 * @param  array<string,string|int> $form
 * @param  array<string,string>     $cookies
 * @param  array<string,string>     $headers
 * @return array{code:int,headers:array<string,string>,body:string,setCookies:array<string,string>}
 */
function http(
    string $method,
    string $path,
    array $form    = [],
    array $cookies = [],
    array $headers = []
): array {
    global $baseUrl;

    $bodyContent = $form !== [] ? http_build_query($form) : '';

    $reqHeaders = $headers;
    if ($cookies !== []) {
        $reqHeaders['Cookie'] = implode('; ', array_map(
            fn($k, $v) => "$k=$v",
            array_keys($cookies),
            $cookies
        ));
    }
    if ($bodyContent !== '') {
        $reqHeaders['Content-Type']   = 'application/x-www-form-urlencoded';
        $reqHeaders['Content-Length'] = (string)strlen($bodyContent);
    }

    $headerLines = array_map(
        fn($k, $v) => "$k: $v",
        array_keys($reqHeaders),
        $reqHeaders
    );

    $opts = [
        'http' => [
            'method'          => strtoupper($method),
            'header'          => implode("\r\n", $headerLines),
            'content'         => $bodyContent,
            'ignore_errors'   => true,
            'follow_location' => 0,
        ],
    ];

    $ctx          = stream_context_create($opts);
    $responseBody = file_get_contents($baseUrl . $path, false, $ctx);

    return parseHttpResponse(
        $http_response_header ?? [],
        $responseBody !== false ? $responseBody : ''
    );
}

/**
 * @param  string[]  $rawHeaders
 * @return array{code:int,headers:array<string,string>,body:string,setCookies:array<string,string>}
 */
function parseHttpResponse(array $rawHeaders, string $body): array
{
    $code       = 0;
    $headers    = [];
    $setCookies = [];

    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#i', $line, $m)) {
            $code = (int)$m[1];
            continue;
        }
        $sep = strpos($line, ':');
        if ($sep === false) {
            continue;
        }
        $name  = strtolower(trim(substr($line, 0, $sep)));
        $value = trim(substr($line, $sep + 1));

        if ($name === 'set-cookie') {
            $pair = explode(';', $value, 2)[0];
            $eq   = strpos($pair, '=');
            if ($eq !== false) {
                $setCookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        } else {
            $headers[$name] = $value;
        }
    }

    return ['code' => $code, 'headers' => $headers, 'body' => $body, 'setCookies' => $setCookies];
}

/**
 * Signe les 4 champs CSRF attendus par app/src/Csrf.php. tsOffset négatif
 * simule un formulaire ouvert il y a quelques secondes (la borne basse
 * du serveur exige age >= 2 s pour repousser les bots).
 *
 * @return array{_csrf:string,_ts:int,_ts_sig:string,website:string}
 */
function csrfTokens(string $cookie, int $tsOffset = -3): array
{
    global $secretCsrf;

    return [
        '_csrf'   => hash_hmac('sha256', $cookie, $secretCsrf),
        '_ts'     => $ts = time() + $tsOffset,
        '_ts_sig' => hash_hmac('sha256', (string)$ts, $secretCsrf),
        'website' => '',
    ];
}
