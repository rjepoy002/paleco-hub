<?php
use App\Services\{WordPressTransport,WordPressFailure};
/** In-memory/file-backed fake. No HTTP client exists in this test transport. */
final class FakeWordPressTransport implements WordPressTransport {
    public array $state=['posts'=>[],'media'=>[],'calls'=>[],'next'=>100,'mode'=>'ok','capabilities'=>['edit_posts'=>true,'publish_posts'=>true,'upload_files'=>true]];
    public function __construct(private ?string $file=null) {}
    public function request(array $connection,string $method,string $path,array $data=[],?array $file=null): array {
        if (($connection['rest_url'] ?? '')!=='https://wordpress.example.invalid/wp-json/wp/v2') throw new LogicException('Mock tests may only use the reserved .invalid destination.');
        $handle=null;
        if ($this->file) { $handle=fopen($this->file,'c+'); flock($handle,LOCK_EX); $raw=stream_get_contents($handle); if ($raw) $this->state=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
        try { return $this->respond($method,$path,$data,$file); }
        finally { if($handle){rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($this->state,JSON_THROW_ON_ERROR));flock($handle,LOCK_UN);fclose($handle);} }
    }
    private function result(array $data,int $status=200): array { return ['status'=>$status,'body'=>json_encode($data,JSON_THROW_ON_ERROR)]; }
    private function respond(string $method,string $path,array $data,?array $file): array {
        $this->state['calls'][]=['method'=>$method,'path'=>$path,'data'=>$data,'file'=>$file?['mime'=>$file['mime'],'name'=>$file['name']]:null];
        $mode=$this->state['mode'];
        if($mode==='auth')return $this->result(['message'=>'raw secret must never appear'],401);
        if($mode==='offline')throw new WordPressFailure('WordPress could not be reached or timed out. Retry to reconcile the result before another write.',$method==='POST');
        $route=parse_url($path,PHP_URL_PATH);parse_str(parse_url($path,PHP_URL_QUERY) ?? '',$query);
        if($route==='/users/me')return $this->result(['id'=>7,'capabilities'=>$this->state['capabilities']]);
        if(str_starts_with($route,'/categories')) {
            if($mode==='category-missing')return $this->result([],404);
            $items=[['id'=>58,'slug'=>'power_advisory','name'=>'POWER_ADVISORY'],['id'=>9,'slug'=>'interruptions','name'=>'INTERRUPTIONS']];
            if($mode==='category-wrong')$items[0]=['id'=>58,'slug'=>'other','name'=>'Unrelated category'];
            if(preg_match('~/categories/(\d+)~',$route,$m)){foreach($items as $item)if($item['id']==$m[1])return $this->result($item);return $this->result([],404);}
            return $this->result(array_values(array_filter($items,fn($i)=>$i['slug']===($query['slug'] ?? ''))));
        }
        if($method==='GET' && preg_match('~^/(posts|media)/(\d+)$~',$route,$m))return isset($this->state[$m[1]][$m[2]])?$this->result($this->state[$m[1]][$m[2]]):$this->result([],404);
        if($method==='GET' && in_array($route,['/posts','/media'],true))return $this->result(array_values(array_filter($this->state[substr($route,1)],fn($p)=>$p['slug']===($query['slug'] ?? ''))));
        if($method==='POST' && $route==='/media') {
            $this->state['media_upload_calls']=($this->state['media_upload_calls'] ?? 0)+1;
            if(($this->state['reject_media_number'] ?? 0)===$this->state['media_upload_calls'])return $this->result([],415);
            if($mode==='media-rejected')return $this->result([],415);
            if($mode==='media-timeout-before')throw new WordPressFailure('The media result is uncertain.',true);
            if(!$file || !is_file($file['path']))throw new LogicException('Missing test poster.');
            $id=++$this->state['next'];$item=['id'=>$id,'slug'=>$data['slug'],'mime_type'=>$file['mime'],'description'=>['raw'=>$data['description']],'source_url'=>'https://wordpress.example.invalid/wp-content/uploads/'.$file['name']];
            $this->state['media'][$id]=$item;
            if(($this->state['timeout_media_number'] ?? 0)===$this->state['media_upload_calls'])throw new WordPressFailure('A selected poster may have been accepted before timeout.',true);
            if($mode==='media-timeout'){$this->state['mode']='ok';throw new WordPressFailure('WordPress timed out after the media may have been accepted.',true);}
            return $this->result($item,201);
        }
        if($method==='POST' && preg_match('~^/media/(\d+)$~',$route,$m)) {
            if(!isset($this->state['media'][$m[1]]))return $this->result([],404);
            $this->state['media'][$m[1]]['post']=$data['post'];
            if($mode==='attachment-timeout'){$this->state['mode']='ok';throw new WordPressFailure('Poster attachment result is uncertain.',true);}
            return $this->result($this->state['media'][$m[1]]);
        }
        if($method==='POST' && preg_match('~^/posts(?:/(\d+))?$~',$route,$m)) {
            if($mode==='post-rejected')return $this->result([],400);
            if($mode==='timeout-before-post')throw new WordPressFailure('The post result is uncertain. Retry reconciliation.',true);
            $id=isset($m[1])?(int)$m[1]:++$this->state['next'];
            $item=['id'=>$id,'slug'=>$data['slug'],'status'=>$data['status'],'title'=>['raw'=>$data['title']],'content'=>['raw'=>$data['content']],'categories'=>$data['categories'],'featured_media'=>$data['featured_media'] ?? 0,'link'=>'https://wordpress.example.invalid/?p='.$id];
            $this->state['posts'][$id]=$item;
            if($mode==='post-timeout'){$this->state['mode']='ok';throw new WordPressFailure('WordPress timed out after the post may have been accepted.',true);}
            if($mode==='invalid-json')return ['status'=>201,'body'=>'not JSON'];
            return $this->result($item,isset($m[1])?200:201);
        }
        throw new LogicException('Unexpected mock request: '.$method.' '.$path);
    }
}
