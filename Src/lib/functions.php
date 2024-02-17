<?php

function extractHeaders($header)
{
    $headers = array();
    foreach (explode("\r\n", $header) as $i => $line) {
        if ($i === 0) {
            $headers['http_code'] = $line;
        } else {
            $explode = explode(": ", $line);
            if (count($explode) == 2) {
                list($key, $value) = $explode;
                $headers[$key] = $value;
            }
        }
    }

    return $headers;
}

function sendHealthCheck($token, $type = null)
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        return;
    }

    doRequest("https://hc-ping.com/" . $token . ($type == null ? "" : $type));
}

function toCamelCase($inputString)
{
    return preg_replace_callback(
        '/(?:^|_| )(\w)/',
        function ($matches) {
            return strtoupper($matches[1]);
        },
        $inputString
    );
}
