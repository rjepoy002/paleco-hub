<?php
/**
 * Plugin Name: PALECO Hub Scheduled Sharing
 * Description: Render an existing Elementor/HT Mega sharing-only template for Hub scheduled posts.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

// Opt in only on installations where the single-post theme does not already supply sharing.
// Save the existing HT Mega Facebook widget as a dedicated Elementor template, then set
// PALECO_HUB_SHARE_TEMPLATE_ID in wp-config.php. Never use a complete announcement as this template.
function paleco_hub_sharing_only_template(array $elements): bool {
    $widgets=0;
    $walk=function(array $items)use(&$walk,&$widgets): bool {
        foreach($items as $item){
            if(!is_array($item))return false;
            if(($item['elType'] ?? '')==='widget'){
                if(($item['widgetType'] ?? '')!=='htmega-social-shere-addons')return false;
                $widgets++;
            }elseif(!in_array($item['elType'] ?? '',['container','section','column'],true))return false;
            if(!empty($item['elements'])&&(!$walk($item['elements'])))return false;
        }
        return true;
    };
    return $walk($elements)&&$widgets===1;
}
add_filter('the_content',function($content){
    if(is_admin()||is_feed()||(defined('REST_REQUEST')&&REST_REQUEST)||!is_singular('post')||!in_the_loop()||!is_main_query())return $content;
    $post=get_post();
    if(!$post||$post->post_status!=='publish'||!empty($post->post_password)||!has_category('INTERRUPTIONS',$post))return $content;
    if(!preg_match('/^paleco-hub-\d+-[a-f0-9]{24}$/D',$post->post_name)||!str_contains($post->post_content,'<!-- paleco-hub:'.$post->post_name.' -->'))return $content;
    // Existing content/shortcodes and per-post Elementor layouts remain authoritative.
    if(str_contains($content,'htmega-social-share')||str_contains($content,'data-social="facebook"')||get_post_meta($post->ID,'_elementor_edit_mode',true)==='builder')return $content;
    $template=(int)apply_filters('paleco_hub_scheduled_share_template_id',defined('PALECO_HUB_SHARE_TEMPLATE_ID')?PALECO_HUB_SHARE_TEMPLATE_ID:0,$post->ID);
    if(!$template||get_post_type($template)!=='elementor_library'||get_post_status($template)!=='publish'||!class_exists('\Elementor\Plugin'))return $content;
    $data=json_decode((string)get_post_meta($template,'_elementor_data',true),true);
    if(!is_array($data)||!paleco_hub_sharing_only_template($data))return $content;
    // Use the installed plugin's renderer, settings and scripts. No Facebook API calls or stored markup.
    $frontend=\Elementor\Plugin::instance()->frontend;
    $sharing=$frontend->get_builder_content_for_display($template,true);
    if(!is_string($sharing)||substr_count($sharing,'data-social="facebook"')!==1)return $content;
    return $content.$sharing;
},99);
