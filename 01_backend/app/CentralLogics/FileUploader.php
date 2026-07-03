<?php

if (!function_exists('readableUploadMaxFileSize')) {
    function readableUploadMaxFileSize($fileType)
    {
        $uploadMaxFileSize = uploadMaxFileSize($fileType);

        return convertToReadableSize($uploadMaxFileSize);

    }
}

if (!function_exists('convertToReadableSize')) {
    function convertToReadableSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824) . 'GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }
}

if (!function_exists('uploadMaxFileSize')) {
    function uploadMaxFileSize($fileType) {
        $uploadMaxFileSize = convertToBytes(ini_get('upload_max_filesize'));
        $uploadMaxFileSize = $uploadMaxFileSize > convertToBytes(($fileType == 'image') ? '20M' : '50M') ? convertToBytes(($fileType == 'image') ? '20M' : '50M') : $uploadMaxFileSize;

        return $uploadMaxFileSize;
    }
}

if (!function_exists('convertToBytes')) {
    function convertToBytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        switch ($last) {
            case 'g':
                $num *= 1024;
            case 'm':
                $num *= 1024;
            case 'k':
                $num *= 1024;
        }

        return $num;
    }
}
