<?php
/** Offline actual evidence export authorization and data-boundary fixture. */
// phpcs:ignoreFile -- Compact isolated WordPress and WooCommerce doubles.
define('ABSPATH','/');define('ARRAY_A','ARRAY_A');
function current_user_can($cap){return 'manage_woocommerce'===$cap && 'denied'!==($GLOBALS['export_case']??'build');}
function get_post($id){return 99===$id?(object)array('post_type'=>'subscrpt_order','post_author'=>7):null;}
function get_post_status($id){return 'pe_cancelled';}
function current_time($type,$gmt=false){return '2026-10-10 12:00:00';}
function wp_json_encode($value,$options=0){return json_encode($value,$options);}
function wc_format_decimal($value){return number_format((float)$value,2,'.','');}
function wc_get_price_decimals(){return 2;}
function absint($value){return abs((int)$value);}
function esc_html__($text,$domain=''){return $text;}
function wp_die($text,$title='',$args=array()){throw new RuntimeException('wp_die:'.($args['response']??0));}
function check_admin_referer($action){$GLOBALS['ex_nonce_action']=$action;if('nonce'===($GLOBALS['export_case']??'build')){throw new RuntimeException('nonce_denied');}return true;}
function nocache_headers(){}
class ExportItem {
 public function get_meta($key){if('_ashbi_contract_plan'===$key){return array('plan_id'=>0,'plan'=>array('price'=>'10.00','time'=>1,'type'=>'months','trial'=>null),'signup_fee'=>'0.00','payment_count'=>0);}return '';}
 public function get_product_id(){return 101;}public function get_variation_id(){return 0;}public function get_quantity(){return 1;}public function get_total(){return '10.00';}public function get_total_tax(){return '0.00';}
}
class ExportOrder {
 public function get_items(){return array(new ExportItem());}public function get_status(){return 'processing';}public function get_currency(){return 'USD';}public function get_total(){return '10.00';}public function get_shipping_total(){return '0.00';}public function get_discount_total(){return '0.00';}
}
function wc_get_order($id){return 501===(int)$id?new ExportOrder():false;}
class ExportDatabase {
 public $prefix='site_a_';public $last_error='';public $prepared=array();public $reads=array();
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0])){$args=$args[0];}$key='sql-'.count($this->prepared);$this->prepared[$key]=array($sql,$args);return $key;}
 public function unpack($key){$q=$this->prepared[$key];$this->reads[]=$q;$this->last_error='';if('storage'===($GLOBALS['export_case']??'build')){$this->last_error='offline missing table';}return $q;}
 public function get_row($key,$mode=null){$this->unpack($key);return array('subscription_id'=>99,'actor_id'=>7,'request_id'=>str_repeat('a',64),'requested_at'=>'2026-10-10 11:00:00','access_end'=>1791633600);}
 public function get_results($key,$mode=null){list($sql,$args)=$this->unpack($key);if(strpos($args[0],'evidence_event')!==false){return array(array('id'=>1,'event_type'=>'cancel_confirmed','actor_id'=>7,'request_id'=>str_repeat('a',64),'details'=>'{"status":"pe_cancelled","token":"private-event-token"}','created_at'=>'2026-10-10 11:00:00'));}return array(array('order_id'=>501,'type'=>'renew'));}
 public function get_var($key){$this->unpack($key);return json_encode($GLOBALS['ex_payload']);}
}
require dirname(__DIR__,2).'/plugin/includes/Frontend/ContractConsent.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/EvidenceExport.php';
$snapshot=SpringDevs\Subscription\Frontend\ContractConsent::order_snapshot(new ExportOrder());
$snapshot['customer_email']='private-customer@example.test';$snapshot['items'][0]['plan']['payment_token']='private-plan-token';
$GLOBALS['ex_payload']=array('document'=>array('version'=>'approved-1','text'=>'Previously approved subscription terms.','hash'=>hash('sha256','Previously approved subscription terms.'),'approval_ref'=>'approved-ref','secret'=>'private-document-secret'),'snapshot'=>$snapshot,'accepted_at'=>'2026-10-09 12:00:00','actor_id'=>7,'payment_outcome'=>'succeeded','raw_card'=>'4242424242424242');
$GLOBALS['wpdb']=new ExportDatabase();$case=$GLOBALS['export_case']??'build';
if('build'===$case){$report=SpringDevs\Subscription\Illuminate\EvidenceExport::build(99);echo json_encode(array('report'=>$report,'reads'=>$GLOBALS['wpdb']->reads));return;}
$_GET=array('subscription_id'=>99);$service=(new ReflectionClass('SpringDevs\\Subscription\\Illuminate\\EvidenceExport'))->newInstanceWithoutConstructor();
try{$service->download();}catch(Throwable $error){echo json_encode(array('error'=>$error->getMessage(),'reads'=>$GLOBALS['wpdb']->reads,'nonce_action'=>$GLOBALS['ex_nonce_action']??''));}
