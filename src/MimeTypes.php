<?php

    $handle_mimetypes = function ($file) {
        // Get file extension
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // Construct file path relative to project root
        // The route strips 'assets/' from the URL, so we need to add it back
        $filepath = __DIR__ . '/../public/assets/' . $file;
        
        // If file doesn't exist, try without the assets prefix (for files directly in public)
        if (!is_file($filepath)) {
            $filepath = __DIR__ . '/../public/' . $file;
        }
        
        // Normalize the path only if file exists
        if (is_file($filepath)) {
            $filepath = realpath($filepath);
        }
        
        // Determine mime type based on extension
        $mimetypes = [
            'css' => 'text/css',
            'eot' => 'application/vnd.ms-fontobject',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'less' => 'text/plain',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'bmp' => 'image/bmp',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'ttf' => 'application/octet-stream',
            'woff' => 'application/font-woff',
            'woff2' => 'application/font-woff2',
            'md' => 'text/plain',
        ];
        
        $mimetype = $mimetypes[$extension] ?? 'application/octet-stream';
        
        // Serve the file if it exists
        if (is_file($filepath)) {
            header('Content-Type: ' . $mimetype);
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            http_response_code(404);
            echo "File not found: " . htmlspecialchars($file);
            exit;
        }
    };

    return $handle_mimetypes;
