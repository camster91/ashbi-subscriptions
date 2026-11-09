<?php
/** Actual PayPal cancellation methods against offline HTTP response doubles. */
// phpcs:ignoreFile -- Compact isolated provider and WordPress runtime doubles.
define('ABSPATH','/');
class WC_Payment_Gateway { public $id; }
class WP_Error {}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_remote_post($url,$args){$GLOBALS['pc_requests'][]=$url;if(strpos($url,'/oauth2/token')!==false){return array('response'=>array('code'=>200),'body'=>'{"access_token":"offline-fixture-token"}');}return $GLOBALS['pc_response'];}
function wp_remote_get($url,$args){$GLOBALS['pc_requests'][]='GET '.$url;return $GLOBALS['pc_get_response']??array('response'=>array('code'=>200),'body'=>'{"id":"I-OFFLINE-FIXTURE","status":"CANCELLED"}');}
function wp_remote_retrieve_body($response){return is_array($response)?($response['body']??''):'';}
function wp_remote_retrieve_response_code($response){return is_array($response)?($response['response']['code']??0):0;}
function wp_json_encode($value){return json_encode($value);}
function subscrpt_write_log($message){}
function subscrpt_write_debug_log($message){}
function add_action($hook,$callback,...$args){$GLOBALS['pc_actions'][$hook][]=$callback;}
function add_filter($hook,$callback,...$args){}
function do_action($hook,...$args){foreach($GLOBALS['pc_actions'][$hook]??array() as $callback){$callback(...$args);}}
function wc_get_order($id){return $GLOBALS['pc_order'];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['pc_meta'][$key]??'';}
function update_post_meta($id,$key,$value){if(($GLOBALS['pc_meta_fail_key']??'')===$key){return false;}$GLOBALS['pc_meta'][$key]=$value;return true;}
function current_time($type,$gmt=false){return '2026-10-10 12:00:00';}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function __($text,$domain=''){return $text;}
class PaypalCancellationOrder {
 public function get_id(){return 501;}
 public function get_payment_method(){return 'wp_subscription_paypal';}
 public function get_meta($key,$single=true){return strpos($key,'subscription_id')!==false?'I-OFFLINE-FIXTURE':'';}
}
class PaypalCancellationHelper {public static function get_parent_order($id){return $GLOBALS['pc_order'];}}
class_alias('PaypalCancellationHelper','SpringDevs\\Subscription\\Illuminate\\Helper');
class PaypalCancellationDatabase {
 public $prefix='wp_';public $last_error='';public $events=array();
 public function prepare($sql,...$args){return $sql;}
 public function insert($table,$data,$format=null){$this->events[]=$data;return 1;}
 public function get_var($sql){return 1;}
 public function get_results($sql){return array();}
}
require dirname(__DIR__,2).'/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php';
$class='SpringDevs\\Subscription\\Illuminate\\Gateways\\Paypal\\Paypal';
$gateway=(new ReflectionClass($class))->newInstanceWithoutConstructor();$gateway->id='wp_subscription_paypal';
foreach(array('api_endpoint'=>'https://provider.example.test','client_id'=>'offline-client','client_secret'=>'offline-secret') as $key=>$value){$property=new ReflectionProperty($class,$key);$property->setAccessible(true);$property->setValue($gateway,$value);}
$cancel=new ReflectionMethod($class,'cancel_paypal_subscription');$cancel->setAccessible(true);$results=array();
foreach(array('transport_error'=>new WP_Error(),'timeout'=>new WP_Error(),'http_400'=>array('response'=>array('code'=>400),'body'=>''),'http_500'=>array('response'=>array('code'=>500),'body'=>''),'http_200'=>array('response'=>array('code'=>200),'body'=>''),'http_202'=>array('response'=>array('code'=>202),'body'=>''),'http_204'=>array('response'=>array('code'=>204),'body'=>'')) as $name=>$response){
 $GLOBALS['pc_requests']=array();$GLOBALS['pc_response']=$response;$results['responses'][$name]=$cancel->invoke($gateway,'I-OFFLINE-FIXTURE','offline-fixture-token');
}
$GLOBALS['wpdb']=new PaypalCancellationDatabase();$GLOBALS['pc_order']=false;$GLOBALS['pc_meta']=array('_subscrpt_order_id'=>501);$GLOBALS['pc_requests']=array();$error=null;
try{$gateway->handle_subscription_cancellation(99);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
$results['missing_order']=array('error'=>$error,'requests'=>count($GLOBALS['pc_requests']));
$GLOBALS['pc_actions']=array();$init=new ReflectionMethod($class,'init_actions');$init->setAccessible(true);$init->invoke($gateway);
$GLOBALS['pc_order']=new PaypalCancellationOrder();$GLOBALS['pc_meta']=array('_subscrpt_order_id'=>501);$GLOBALS['pc_requests']=array();$GLOBALS['pc_response']=array('response'=>array('code'=>204),'body'=>'');$error=null;
try{do_action('subscrpt_subscription_pending_cancellation',99);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
$cancel_requests=array_filter($GLOBALS['pc_requests'],function($url){return strpos($url,'/cancel')!==false;});
$results['pending_hook']=array('registered'=>isset($GLOBALS['pc_actions']['subscrpt_subscription_pending_cancellation']),'error'=>$error,'cancel_requests'=>count($cancel_requests),'events'=>$GLOBALS['wpdb']->events);
$status_key=$gateway->get_meta_key('paypal_subs_status');
foreach(array('legacy_cancelled','confirmation_write_repair','timeout_already_cancelled') as $name){
 $GLOBALS['wpdb']=new PaypalCancellationDatabase();$GLOBALS['pc_meta']=array('_subscrpt_order_id'=>501);$GLOBALS['pc_requests']=array();$GLOBALS['pc_meta_fail_key']='';$error=null;
 if('legacy_cancelled'===$name){$GLOBALS['pc_meta'][$status_key]='cancelled';}
 if('confirmation_write_repair'===$name){$GLOBALS['pc_response']=array('response'=>array('code'=>204),'body'=>'');$GLOBALS['pc_meta_fail_key']='_ashbi_cancel_provider_confirmed';$gateway->handle_subscription_cancellation(99);$GLOBALS['pc_meta_fail_key']='';}
 $GLOBALS['pc_response']=array('response'=>array('code'=>422),'body'=>'');
 try{$gateway->handle_subscription_cancellation(99);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
 $get_requests=array_filter($GLOBALS['pc_requests'],function($url){return strpos($url,'GET ')===0;});
 $results[$name]=array('error'=>$error,'confirmed'=>$GLOBALS['pc_meta']['_ashbi_cancel_provider_confirmed']??'','get_requests'=>count($get_requests),'events'=>$GLOBALS['wpdb']->events);
}
echo json_encode($results);
