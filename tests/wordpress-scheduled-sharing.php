<?php
namespace Elementor {
    class Plugin {
        public $frontend;
        public static function instance(){static $p;if(!$p){$p=new self();$p->frontend=new class{public function get_builder_content_for_display($id,$css){return '<div class="htmega-social-share"><li data-social="facebook">Share to Facebook</li></div>';}};}return $p;}
    }
}
namespace {
    define('ABSPATH',__DIR__);$hooks=[];$scope=true;$category=true;$template=99;$meta=[];
    function add_filter($name,$fn,$priority=10){global $hooks;$hooks[$name]=$fn;}
    function apply_filters($name,$value,...$args){global $template;return $name==='paleco_hub_scheduled_share_template_id'?$template:$value;}
    function is_admin(){return false;}function is_feed(){return false;}function is_singular($type){global $scope;return $scope;}function in_the_loop(){return true;}function is_main_query(){return true;}
    function get_post(){global $post;return $post;}function has_category($name,$post){global $category;return $category&&$name==='INTERRUPTIONS';}
    function get_post_meta($id,$key,$single){global $meta;return $meta[$id][$key] ?? '';}
    function get_post_type($id){return 'elementor_library';}function get_post_status($id){return 'publish';}
    function verify($value,$message){if(!$value)throw new \RuntimeException($message);}
    require dirname(__DIR__).'/docs/wordpress/paleco-hub-scheduled-sharing.php';
    $slug='paleco-hub-7-'.str_repeat('a',24);$content='<figure>Poster</figure><!-- paleco-hub:'.$slug.' -->';
    $post=(object)['ID'=>7,'post_status'=>'publish','post_password'=>'','post_name'=>$slug,'post_content'=>$content];
    $widget=['elType'=>'widget','widgetType'=>'htmega-social-shere-addons'];$data=[['elType'=>'section','elements'=>[$widget]]];$meta[99]['_elementor_data']=json_encode($data);
    $render=$hooks['the_content'];$result=$render($content);
    verify(substr_count($result,'data-social="facebook"')===1&&str_starts_with($result,$content),'one installed-plugin widget follows unchanged poster content');
    verify($render($result)===$result,'existing rendered sharing widget is not duplicated');
    $meta[7]['_elementor_edit_mode']='builder';verify($render($content)===$content,'existing Elementor post layout is preserved');unset($meta[7]);
    $scope=false;verify($render($content)===$content,'archives and secondary views unchanged');$scope=true;
    $category=false;verify($render($content)===$content,'partial advisory category unchanged');$category=true;
    $post->post_status='draft';verify($render($content)===$content,'non-public posts unchanged');$post->post_status='publish';
    $post->post_name='ordinary-post';verify($render($content)===$content,'non-Hub posts unchanged');$post->post_name=$slug;
    $template=0;verify($render($content)===$content,'helper stays disabled without deployment configuration');$template=99;
    $data[0]['elements'][]=['elType'=>'widget','widgetType'=>'image'];$meta[99]['_elementor_data']=json_encode($data);verify($render($content)===$content,'full announcement template cannot copy old posters');
    verify(!paleco_hub_sharing_only_template([$widget,$widget]),'template with two widgets is rejected');
    echo "PLUGIN PASS: existing Elementor renderer, one share widget, scoped public Hub posts, no duplication, no stored markup or automatic Facebook publishing.\n";
}
