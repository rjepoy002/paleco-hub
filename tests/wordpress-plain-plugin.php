<?php
// Standalone hook harness: no WordPress installation or network requests.
define('ABSPATH',__DIR__);$hooks=[];$allowed=true;$meta=[];
function add_filter($name,$callback,$priority=10,$args=1){global $hooks;$hooks[$name][$priority][]=[$callback,$args];}
function add_action(...$args){add_filter(...$args);}
function remove_filter($name,$callback,$priority){global $hooks;foreach($hooks[$name][$priority] ?? [] as $i=>$entry)if($entry[0]===$callback)unset($hooks[$name][$priority][$i]);}
function apply_filters($name,$value,...$args){global $hooks;$items=$hooks[$name] ?? [];ksort($items);foreach($items as $callbacks)foreach($callbacks as [$fn,$count])$value=$fn(...array_slice([$value,...$args],0,$count));return $value;}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_user_logged_in(){global $allowed;return $allowed;}
function current_user_can(...$args){global $allowed;return $allowed;}
function get_term($id,$type){return (object)['name'=>$id===58?'POWER_ADVISORY':'INTERRUPTIONS','slug'=>$id===58?'power_advisory':'interruptions'];}
function wp_slash($v){return addslashes($v);}
function update_post_meta($id,$key,$value){global $meta;$meta[$id][$key]=$value;}
class WP_Error {public function __construct(...$args){}}
class HubRequest {public function __construct(private array $data){}public function get_param($key){return $this->data[$key] ?? null;}}
function verify($value){if(!$value)throw new RuntimeException('WordPress plugin harness failed.');}
require dirname(__DIR__).'/docs/wordpress/paleco-hub-plain-text.php';
$slug='paleco-hub-123-'.str_repeat('a',24);$text="Original title\n\nO&apos;Reilly &amp; &lt;script&gt;\nC:\\advisory";
$request=new HubRequest(['paleco_hub_plain_text'=>true,'slug'=>$slug,'content'=>$text,'categories'=>[58],'id'=>42]);
verify(!is_wp_error(apply_filters('rest_pre_insert_post',(object)[],$request)));
$unrelated=['post_type'=>'post','post_name'=>'scheduled','post_content'=>'<p>Scheduled unchanged</p>'];
verify(apply_filters('wp_insert_post_data',$unrelated,['ID'=>1])===$unrelated);
$data=['post_type'=>'post','post_name'=>$slug,'post_content'=>'<p>Mutated by save filter</p>'];
$saved=apply_filters('wp_insert_post_data',$data,['ID'=>42]);
verify(stripslashes($saved['post_content'])===$text);
verify(apply_filters('wp_insert_post_data',$data,['ID'=>42])===$data); // One write only.
apply_filters('rest_after_insert_post',(object)['ID'=>42],$request,false);
verify($meta[42]['_paleco_hub_key']===$slug);
$allowed=false;verify(is_wp_error(apply_filters('rest_pre_insert_post',(object)[],$request)));$allowed=true;
verify(is_wp_error(apply_filters('rest_pre_insert_post',(object)[],new HubRequest(['paleco_hub_plain_text'=>true,'slug'=>$slug,'content'=>$text,'categories'=>[9]]))));
$prepared=(object)['post_content'=>'<img src="poster">'];verify(apply_filters('rest_pre_insert_post',$prepared,new HubRequest([]))===$prepared);
$createRequest=new HubRequest(['paleco_hub_plain_text'=>true,'slug'=>$slug,'content'=>"Title\n\n<script>alert(1)</script> &amp;",'categories'=>[58]]);
verify(!is_wp_error(apply_filters('rest_pre_insert_post',(object)[],$createRequest)));
$created=apply_filters('wp_insert_post_data',$data,['ID'=>0]);verify(stripslashes($created['post_content'])==="Title\n\n&lt;script&gt;alert(1)&lt;/script&gt; &amp;");
echo "PLUGIN PASS: scoped raw storage, slashing, private meta, permissions and unrelated posts.\n";
