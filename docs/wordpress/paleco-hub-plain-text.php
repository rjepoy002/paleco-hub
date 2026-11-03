<?php
/**
 * Plugin Name: PALECO Hub Plain Advisory Storage
 * Description: Preserve escaped plain text for explicitly marked, authenticated Hub advisory REST writes.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_filter('rest_pre_insert_post',function($prepared,$request){
    if(is_wp_error($prepared) || $request->get_param('paleco_hub_plain_text')!==true)return $prepared;
    $id=(int)$request->get_param('id');
    if(!is_user_logged_in() || !current_user_can($id?'edit_post':'edit_posts',...($id?[$id]:[])))return new WP_Error('hub_permission','Advisory editing permission is required.',['status'=>403]);
    $slug=$request->get_param('slug');$text=$request->get_param('content');
    if(!is_string($slug) || !preg_match('/^paleco-hub-\d+-[a-f0-9]{24}$/D',$slug) || !is_string($text))return new WP_Error('hub_input','A Hub identifier and plain advisory text are required.',['status'=>400]);
    $advisory=false;
    foreach((array)$request->get_param('categories') as $termId){$term=get_term((int)$termId,'category');if($term && !is_wp_error($term) && (strcasecmp($term->name,'POWER_ADVISORY')===0 || strcasecmp($term->slug,'POWER_ADVISORY')===0))$advisory=true;}
    if(!$advisory)return new WP_Error('hub_category','The POWER_ADVISORY category is required.',['status'=>400]);
    $text=htmlspecialchars(str_replace(["\r\n","\r"],"\n",$text),ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8',false);
    // Only restore escaped text for this one insert/update, after ordinary save filters.
    $restore=null;
    $restore=function($data,$postarr)use($text,$slug,$id,&$restore){
        if(($data['post_type'] ?? '')==='post' && ($data['post_name'] ?? '')===$slug && (int)($postarr['ID'] ?? 0)===$id){$data['post_content']=wp_slash($text);remove_filter('wp_insert_post_data',$restore,PHP_INT_MAX);}
        return $data;
    };
    add_filter('wp_insert_post_data',$restore,PHP_INT_MAX,2);
    add_filter('rest_request_after_callbacks',function($response)use(&$restore){remove_filter('wp_insert_post_data',$restore,PHP_INT_MAX);return $response;},PHP_INT_MAX);
    return $prepared;
},10,2);

add_action('rest_after_insert_post',function($post,$request){
    if($request->get_param('paleco_hub_plain_text')===true && current_user_can('edit_post',$post->ID)){
        update_post_meta($post->ID,'_paleco_hub_key',$request->get_param('slug'));
    }
},10,2);
