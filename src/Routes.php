<?php

    $forbidden = function() {
        http_response_code(403);
        echo 'Forbidden';
    };

    $return_asset_files = include(MIMETYPES_FILE);

    /*** DO NOT MODIFY - See Bootstrap.php. Put custom routes in CUSTOM_ROUTES_FILE **/
    return [
        // Asset Files - FastRoute compatible patterns with non-capturing groups
        ['GET', '/assets/{file:.+\.(?:css|eot|js|json|less|jpg|bmp|png|svg|ttf|woff|woff2|md)}', $return_asset_files],
        ['GET', '/public/{file:.+\.(?:css|eot|js|json|less|jpg|bmp|png|svg|ttf|woff|woff2|md)}', $return_asset_files],
        
        // OPTIONS handler
        ['OPTIONS', '/{path:.*}', $forbidden],
        
        // Catchall for unmatched routes (excluding root)
        // Commented out to prevent route shadowing - FastRoute handles 404s automatically
        // ['GET', '/{catchall:.+}', function($catchall) { 
        //     http_response_code(404);
        //     return 'Not Found'; 
        // }],
    ];
