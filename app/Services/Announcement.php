<?php
namespace App\Services;
final class Announcement {
    public static function title(string $text): string {
        foreach (preg_split('/\R/u',$text) as $line) {
            if (preg_match('/^\s*(?:[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]\s*)*(?:Power\s+(?:Service\s+)?(?:Advisory|Interruption)|Partial\s+Power\s+Interruption)/iu',$line)) return $line;
        }
        return 'Partial Power Interruption Update '.date('m/d/y g:i A');
    }
    public static function dateTitle(string $date): string {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$d || $d->format('Y-m-d')!==$date) throw new \RuntimeException('Enter a valid interruption date.');
        return 'Scheduled Power Interruption '.$d->format('F j, Y');
    }
}
