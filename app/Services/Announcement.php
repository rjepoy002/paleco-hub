<?php
namespace App\Services;
final class Announcement {
    public static function title(string $text): string {
        return self::titleDetails($text)['title'];
    }
    public static function titleDetails(string $text): array {
        if (!mb_check_encoding($text,'UTF-8')) throw new \RuntimeException('Use valid UTF-8 advisory text.');
        $lines=preg_split('/\R/u',$text);
        foreach ($lines as $index=>$line) {
            // Keep the existing advisory patterns; ignore decorative emoji only for recognition.
            if (mb_strlen($line)<=255 && preg_match('/^[\s\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]*(?:Power\s+(?:Service\s+)?(?:Advisory|Interruption)|Partial\s+Power\s+Interruption)/iu',$line)) return ['title'=>$line,'line'=>$index];
        }
        $first=''; foreach($lines as $line) if(self::hasText($line)) { $first=trim($line); break; }
        if ($first==='') return ['title'=>'','line'=>null];
        $summary=preg_replace('/\s+/u',' ',$first);
        if (mb_strlen($summary)>100) $summary=mb_substr($summary,0,97).'…';
        return ['title'=>'Partial Power Interruption Update — '.$summary,'line'=>null];
    }
    public static function publicationBody(string $text): string {
        $detected=self::titleDetails($text); $lines=preg_split('/\R/u',$text);
        if ($detected['line']!==null) { unset($lines[$detected['line']]); return ltrim(implode("\n",$lines),"\r\n"); }
        return str_replace(["\r\n","\r"],"\n",$text);
    }
    public static function partialInput(array $input): array {
        foreach(['body','title'] as $field) if(isset($input[$field]) && !is_string($input[$field])) throw new \RuntimeException('Enter valid announcement text and title.');
        $body=$input['body'] ?? ''; $title=$input['title'] ?? '';
        if (!mb_check_encoding($body,'UTF-8') || !mb_check_encoding($title,'UTF-8')) throw new \RuntimeException('Use valid UTF-8 advisory text.');
        if (!self::hasText($body) || mb_strlen($body)>100000) throw new \RuntimeException('Enter an announcement up to 100,000 characters.');
        if(preg_match('/[\x00-\x08\x0E-\x1F\x7F]/u',$body.$title)) throw new \RuntimeException('Remove unsupported control characters from the advisory.');
        if (!self::hasText($title)) $title=self::title($body);
        if (!self::hasText($title) || mb_strlen($title)>255) throw new \RuntimeException('Enter a title up to 255 characters.');
        if (!self::hasText(self::publicationBody($body))) throw new \RuntimeException('Add advisory details below the title before publishing.');
        return ['body'=>$body,'title'=>$title];
    }
    private static function hasText(string $text): bool { return (bool)preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u',$text); }
    public static function dateTitle(string $date): string {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$d || $d->format('Y-m-d')!==$date) throw new \RuntimeException('Enter a valid interruption date.');
        return 'Scheduled Power Interruption '.$d->format('F j, Y');
    }
}
