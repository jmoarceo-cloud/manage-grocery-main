<?php

namespace App\Services;

class PublicProductImage
{
    public static function path(?string $image): ?string
    {
        if (! $image || $image === 'default.png') {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', $image), '/');
        foreach ([base_path('project'), public_path()] as $root) {
            $candidates = [$root.'/'.$relative, $root.'/assets/images/'.$relative, $root.'/uploads/products/'.basename($relative)];
            foreach ($candidates as $path) {
                $candidate = realpath($path);
                if (! $candidate || ! is_file($candidate)) {
                    continue;
                }
                foreach (['assets/images', 'uploads/products', 'uploads/product_images'] as $folder) {
                    $allowed = realpath($root.'/'.$folder);
                    if ($allowed && str_starts_with(strtolower($candidate), strtolower($allowed.DIRECTORY_SEPARATOR)) && in_array((new \finfo(FILEINFO_MIME_TYPE))->file($candidate), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }
}
