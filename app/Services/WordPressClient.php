<?php
namespace App\Services;
final class WordPressClient {
    public function __construct(public readonly array $connection,private readonly WordPressTransport $transport=new CurlWordPressTransport()) {}
    public function request(string $method,string $path,array $data=[],?array $file=null): array {
        $response=$this->transport->request($this->connection,$method,$path,$data,$file); $status=$response['status'];
        if ($status<200 || $status>=300) {
            $message=match($status) {
                401=>'WordPress authentication failed. Ask an administrator to check the username and Application Password.',
                403=>'WordPress denied this action. Check the account publishing/media permissions.',
                404=>'The WordPress post, media, category, or API endpoint was not found. Check connection and mapping settings.',
                400,422=>'WordPress rejected the announcement. Check its category, title, content, and poster.',
                413,415=>'WordPress rejected the poster size or format.',
                429=>'WordPress is limiting requests. Wait before retrying.',
                default=>'WordPress returned an unexpected response. Retry to reconcile before another write.'
            };
            throw new WordPressFailure($message,$method==='POST' && !in_array($status,[400,401,403,404,405,413,415,422],true));
        }
        try { $json=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR); } catch (\JsonException $ex) { throw new WordPressFailure('WordPress returned invalid JSON. Reconcile before retrying a write.',$method==='POST'); }
        if (!is_array($json)) throw new WordPressFailure('WordPress returned an invalid response.',$method==='POST');
        return $json;
    }
    public function permissions(string $action,bool $poster): void {
        $me=$this->request('GET','/users/me?context=edit');
        if (!is_int($me['id'] ?? null) || $me['id']<1 || ($me['capabilities']['edit_posts'] ?? false)!==true) throw new WordPressFailure('The WordPress account needs edit_posts permission.');
        if ($action==='publish' && ($me['capabilities']['publish_posts'] ?? false)!==true) throw new WordPressFailure('The WordPress account needs publish_posts permission before publication.');
        if ($poster && ($me['capabilities']['upload_files'] ?? false)!==true) throw new WordPressFailure('The WordPress account needs upload_files permission for interruption posters.');
    }
    public function category(array $mapping): int {
        // A previously verified identifier must still resolve to the configured exact name or slug.
        if ($mapping['category_id']) {
            $item=$this->request('GET','/categories/'.(int)$mapping['category_id']);
            if (!$this->matchesCategory($item,$mapping)) throw new WordPressFailure('The mapped category changed. Re-test the exact category mapping in WordPress settings.');
            return (int)$item['id'];
        }
        $slug=strtolower(str_replace(' ','-',trim($mapping['category_name'])));
        $items=$this->request('GET','/categories?slug='.rawurlencode($slug).'&per_page=100');
        $matches=array_values(array_filter($items,fn($item)=>is_array($item)&&$this->matchesCategory($item,$mapping)));
        if (count($matches)!==1) throw new WordPressFailure('The exact mapped category was not found. An administrator must resolve its slug or verify its ID in WordPress settings.');
        return (int)$matches[0]['id'];
    }
    private function matchesCategory(array $item,array $mapping): bool {
        return isset($item['id']) && is_int($item['id']) && $item['id']>0 && (strcasecmp($item['slug'] ?? '',$mapping['category_name'])===0 || ($mapping['category_id'] && ($item['name'] ?? '')===$mapping['category_name']));
    }
    public function safeLink(mixed $link): string {
        if (!is_string($link) || strlen($link)>1000 || preg_match('/[\x00-\x20\x7f]/',$link)) throw new WordPressFailure('WordPress returned an unsafe post or media URL.',true);
        $p=parse_url($link); $site=parse_url($this->connection['website_url']);
        if (!$p || ($p['scheme'] ?? '')!=='https' || strcasecmp($p['host'] ?? '',$site['host'] ?? '')!==0 || isset($p['user']) || isset($p['pass']) || (isset($p['port'])&&$p['port']!==443)) throw new WordPressFailure('WordPress returned a URL outside the configured HTTPS website.',true);
        return $link;
    }
    public function media(array $result,string $marker): array {
        if (!is_int($result['id'] ?? null) || $result['id']<1 || ($result['description']['raw'] ?? '')!==$marker || !in_array($result['mime_type'] ?? '',['image/jpeg','image/png','image/webp'],true)) throw new WordPressFailure('The uploaded media could not be verified. Reconcile before uploading another poster.',true);
        return ['id'=>$result['id'],'url'=>$this->safeLink($result['source_url'] ?? null)];
    }
    public function post(array $result,array $payload,?int $expectedId=null): array {
        if (!is_int($result['id'] ?? null) || $result['id']<1 || ($expectedId && $result['id']!==$expectedId)) throw new WordPressFailure('WordPress returned an invalid post ID. Reconcile before retrying.',true);
        foreach (['status','slug'] as $key) if (($result[$key] ?? null)!==$payload[$key]) throw new WordPressFailure('WordPress did not retain the requested post status or identifier. Reconcile the result.',true);
        foreach (['title','content'] as $key) if (($result[$key]['raw'] ?? null)!==$payload[$key]) throw new WordPressFailure('WordPress changed the submitted title or content. Review the remote result before retrying.',true);
        $categories=$result['categories'] ?? []; if (!in_array($payload['categories'][0],$categories,true)) throw new WordPressFailure('WordPress did not retain the required category. Review the category mapping.',true);
        if (isset($payload['featured_media']) && ($result['featured_media'] ?? null)!==$payload['featured_media']) throw new WordPressFailure('WordPress did not retain the required featured poster. Reconcile before retrying.',true);
        return ['id'=>$result['id'],'url'=>$this->safeLink($result['link'] ?? null),'status'=>$result['status']];
    }
}
