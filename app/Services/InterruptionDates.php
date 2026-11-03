<?php
namespace App\Services;
final class InterruptionDates {
    public static function extract(string $text): array {
        $text=mb_substr(str_replace(["\r\n","\r"],"\n",$text),0,200000);
        $months='January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
        $datePattern='(?:(?:'.$months.')\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:\s*,\s*|\s+)\d{4}|\d{1,2}/\d{1,2}/\d{4})';
        $lines=preg_split('/\n/u',$text);$segments=[];$labelled=false;
        // Labels may themselves span OCR lines. Bound the following text to this field.
        preg_match_all('/\b(?:interruption\s*date|date\s*of\s*interruption)\s*[:\x{FF1A}\x{2013}\x{2014}\-]?\s*/iu',$text,$labels,PREG_OFFSET_CAPTURE);
        foreach($labels[0] as [$label,$offset]){
            $labelled=true;$segment=mb_substr(substr($text,$offset+strlen($label)),0,400);
            $segment=preg_split('/\b(?:upload(?:ed)?|posted|publish(?:ed|ication)?|issued|prepared|printed|advisory|time|duration|reason|affected\s*areas?)\s*(?:date)?\s*[:\x{FF1A}\-]|\n\s*[\p{L}][\p{L} ]{1,40}\s*[:\x{FF1A}]/iu',$segment)[0];
            $segments[]=$segment;
        }
        if(!$labelled){foreach($lines as $i=>$line){if(preg_match('/(?:upload|posted|publish|issued|prepared|printed|advisory\s*date)/iu',$line))continue;if(preg_match('/scheduled\s+(?:power\s+)?interruption/iu',$line))$segments[]=$line.' '.($lines[$i+1] ?? '');}}
        $candidates=[];$mismatch=false;
        foreach($segments as $segment){
            // Only the date directly associated with an interruption label/header is relevant.
            $segment=preg_split('/(?:upload(?:ed)?|posted|published|publication|issued|prepared|printed)\s*(?:date)?\s*:/iu',$segment)[0];
            preg_match_all('~'.$datePattern.'~iu',$segment,$matches,PREG_OFFSET_CAPTURE);
            foreach($matches[0] as [$raw,$offset]){
                $clean=preg_replace('/(\d)(?:st|nd|rd|th)\b/iu','$1',$raw);$clean=preg_replace('/\s+/u',' ',trim($clean));$clean=preg_replace('/\s*,\s*/u',', ',$clean);$clean=str_replace(['Sept.','Sept','sept.','sept'],['Sep','Sep','Sep','Sep'],$clean);
                $d=null;foreach(['!F j, Y','!F j Y','!M j, Y','!M j Y','!m/d/Y'] as $format){$try=\DateTimeImmutable::createFromFormat($format,str_replace('.','',$clean));$errors=\DateTimeImmutable::getLastErrors();if($try&&(!$errors||(!$errors['warning_count']&&!$errors['error_count']))){$d=$try;break;}}
                if(!$d || (int)$d->format('Y')<2000 || (int)$d->format('Y')>2199)continue;
                $window=substr($segment,max(0,$offset-15),strlen($raw)+35);
                if(preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Tues|Wed|Thu|Thur|Thurs|Fri|Sat|Sun)\b/iu',$window,$weekday)&&strcasecmp(substr($weekday[1],0,3),$d->format('D'))!==0)$mismatch=true;
                $candidates[$d->format('Y-m-d')]=true;
            }
        }
        $dates=array_keys($candidates);$status=$mismatch?'weekday_mismatch':(count($dates)>1?'conflict':(count($dates)===1?($labelled?'valid':'uncertain'):'missing'));
        return ['status'=>$status,'date'=>$status==='valid'?$dates[0]:null,'candidates'=>$dates,'confidence'=>$status==='valid'?0.98:($dates?0.5:0.0),'text'=>$text,'message'=>match($status){'valid'=>'Interruption date detected: '.(new \DateTimeImmutable($dates[0]))->format('F j, Y'),'conflict'=>'Different interruption dates were found.','weekday_mismatch'=>'The printed weekday does not match the date.','uncertain'=>'A possible interruption date needs manual review.',default=>'No labelled interruption date could be read.'}];
    }
}
