<?php
namespace App\Services;
final class Uploads {
    public static function validate(string $path): string {
        if (!is_file($path) || filesize($path)>8*1024*1024 || filesize($path)<1) throw new \RuntimeException('Select a poster up to 8 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path); $dimensions=@getimagesize($path);
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !$dimensions || $dimensions[0]*$dimensions[1]>40000000 || ($dimensions['mime'] ?? '')!==$mime) throw new \RuntimeException('Use a valid JPG, PNG, or WebP poster up to 40 megapixels.');
        return $mime;
    }
    public static function path(string $name): string {
        if (!preg_match('/^[a-f0-9]{48}$/D',$name)) throw new \RuntimeException('The saved poster is unavailable. Upload a valid poster.');
        return ROOT.'/storage/uploads/'.$name;
    }
}
