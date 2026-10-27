<?php
namespace App\Services;
use App\Models\DB;
final class ScheduledMedia {
    public static function payload(WordPressClient $client,array $a,string $key,callable $pending): array {
        Announcement::dateTitle($a['interruption_date'] ?? '');$items=Attachments::all((int)$a['id']);
        if(!$items)throw new \RuntimeException('Select at least one interruption poster.');
        $figures=[];$ids=[];
        foreach($items as $item){
            $path=Uploads::path($item['storage_name']);$mime=Uploads::validate($path);$hash=hash_file('sha256',$path);
            if(!$item['wordpress_media_id'] || $item['content_hash']!==$hash){
                $slug=$key.'-a'.$item['id'].'-'.substr($hash,0,16);$marker='paleco-hub-media:'.$key.':'.$hash;
                $pending('media',['slug'=>$slug,'marker'=>$marker,'hash'=>$hash,'attachment_id'=>(int)$item['id']]);
                $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
                $result=$client->request('POST','/media',['slug'=>$slug,'title'=>e($a['title']),'description'=>$marker,'alt_text'=>$a['title']],['path'=>$path,'mime'=>$mime,'name'=>$slug.'.'.$extension]);
                $media=$client->media($result,$marker);Attachments::saveMedia((int)$a['id'],(int)$item['id'],$media,$hash);
                $item['wordpress_media_id']=$media['id'];$item['wordpress_media_url']=$media['url'];
            }
            $url=$client->safeLink($item['wordpress_media_url']);$mediaId=(int)$item['wordpress_media_id'];$ids[]=$mediaId;
            $figures[]='<figure class="wp-block-image"><img src="'.e($url).'" alt="'.e($a['title']).'" class="wp-image-'.$mediaId.'" /></figure>';
        }
        return ['featured_media'=>$ids[0],'content'=>implode("\n",$figures)];
    }
    public static function recover(WordPressClient $client,array $a,array $pending): void {
        $items=Attachments::all((int)$a['id']);$id=(int)($pending['attachment_id'] ?? ($items[0]['id'] ?? 0));
        if(!in_array($id,array_map('intval',array_column($items,'id')),true))throw new WordPressFailure('The pending poster identity needs administrator reconciliation.',true);
        $matches=$client->request('GET','/media?context=edit&slug='.rawurlencode($pending['slug']).'&per_page=100');
        if(count($matches)!==1)throw new WordPressFailure('The earlier poster upload is still uncertain. No new upload was made. Retry reconciliation later or ask an administrator to inspect WordPress Media.',true);
        Attachments::saveMedia((int)$a['id'],$id,$client->media($matches[0],$pending['marker']),$pending['hash']);
    }
    public static function ids(int $id): array { return array_map('intval',array_column(Attachments::all($id),'wordpress_media_id')); }
}
