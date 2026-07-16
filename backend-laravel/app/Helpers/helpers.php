<?php

/*
|--------------------------------------------------------------------------
| Global Helper Functions
|--------------------------------------------------------------------------
|
| This file is autoloaded via composer.json ("autoload.files"). Add small,
| reusable helper functions here that should be available application-wide.
|
*/

if (! function_exists('api_response')) {
    /**
     * Build a standardized API response array.
     *
     * @param  mixed  $data
     */
    function api_response(bool $success, string $message, $data = null): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ];
    }
}

if (! function_exists('format_rupiah')) {
    /**
     * Format a number as Indonesian Rupiah currency.
     */
    function format_rupiah($number): string
    {
        return 'Rp ' . number_format((float) $number, 0, ',', '.');
    }
}
