<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | go2rtc (RTSP -> WebRTC low-latency streamer for the live CCTV feeds)
    |--------------------------------------------------------------------------
    |
    | api_url    : base URL of the go2rtc REST API (default port 1984). Used to
    |             push camera RTSP sources live (PUT /api/streams).
    | stream_url : base URL the browser uses for the WebSocket signalling. The
    |             player connects to {stream_url}/api/ws?src={path} and then
    |             negotiates WebRTC (falling back to MSE/HLS if needed).
    |
    */
    'go2rtc' => [
        'api_url'    => env('GO2RTC_API_URL', 'http://127.0.0.1:1984'),
        'stream_url' => env('GO2RTC_STREAM_URL', 'http://localhost:1984'),
    ],

];
