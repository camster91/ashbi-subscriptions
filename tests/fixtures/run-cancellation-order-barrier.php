<?php
/** Real order callback and manual-checkout cancellation barriers, offline only. */
// phpcs:ignoreFile -- Compact isolated WordPress/WooCommerce test doubles.
define('ABSPATH','/');define('DAY_IN_SECONDS',86400);
class WC_Order {
 public $status='pending'; public $meta=array();
 public function get_id(){return 501;} public function get_status(){return $this->status;}
 public function get_meta($key,$single=true){return $this->meta[$key]??'';}
 public function is_paid(){return 'processing'===$this->status;}
 public function get_customer_id(){return 7;}
 public function update_meta_data($key,$value){$GLOBALS['ob_effects'][]=array('order_meta',$key);$this->meta[$key]=$value;}
 public function save(){$GLOBALS['ob_effects'][]=array('order_save');return 501;}
}
function wc_get_order($id){return $GLOBALS['ob_order'];}
function get_post_status($id){return $GLOBALS['ob_status'];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['ob_meta'][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['ob_effects'][]=array('post_meta',$key);$GLOBALS['ob_meta'][$key]=$value;return true;}
function delete_post_meta($id,$key){$GLOBALS['ob_effects'][]=array('delete_meta',$key);unset($GLOBALS['ob_meta'][$key]);return true;}
function wp_update_post($data,$error=false){$GLOBALS['ob_effects'][]=array('status',$data);$GLOBALS['ob_status']=$data['post_status'];return $data['ID'];}
function apply_filters($hook,$value,...$args){return $value;}
function do_action($hook,...$args){$GLOBALS['ob_effects'][]=array('hook',$hook);}
function subscrpt_write_log($message){}
function subscrpt_is_max_payments_reached($id){return false;}
function subscrpt_renewal_is_migration_blocked($id){return false;}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000099';}
function current_time($type,$gmt=false){return '2026-10-10 12:00:00';}
function wp_json_encode($value){return json_encode($value);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function __($text,$domain=''){return $text;}
class OrderBarrierDatabase {
 public $prefix='wp_';public $last_error='';public $prepared=array();public $events=array();
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0])){$args=$args[0];}$key='sql-'.count($this->prepared);$this->prepared[$key]=array($sql,$args);return $key;}
 public function get_var($query){list($sql,$args)=$this->prepared[$query]??array($query,array());$this->last_error='';if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false){return 1;}if(strpos($sql,'SELECT subscription_id FROM')===0){return 99;}if(strpos($sql,'subscrpt_order_relation')!==false||strpos((string)($args[0]??''),'subscrpt_order_relation')!==false){return 1;}return null;}
 public function get_results($query,$output=null){return array((object)array('id'=>1,'subscription_id'=>99,'order_id'=>501,'order_item_id'=>601,'type'=>$GLOBALS['ob_type']));}
 public function insert($table,$data,$format=null){if(strpos($table,'evidence_event')!==false){$this->events[]=$data;return 1;}$GLOBALS['ob_effects'][]=array('database_insert',$table);return 1;}
 public function query($sql){$GLOBALS['ob_effects'][]=array('database_write',$sql);return false;}
}
function reset_order_barrier_fixture($subscription_status,$order_status,$type){$GLOBALS['wpdb']=new OrderBarrierDatabase();$GLOBALS['ob_order']=new WC_Order();$GLOBALS['ob_order']->status=$order_status;$GLOBALS['ob_status']=$subscription_status;$GLOBALS['ob_type']=$type;$GLOBALS['ob_meta']=array('_subscrpt_next_date'=>time()+86400,'_subscrpt_cancel_at'=>time()+7200,'_subscrpt_auto_renew'=>0);$GLOBALS['ob_effects']=array();}
require dirname(__DIR__,2).'/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Helper.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/RenewalClaim.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Order.php';
$service=(new ReflectionClass('SpringDevs\\Subscription\\Illuminate\\Order'))->newInstanceWithoutConstructor();
$results=array();
foreach(array('pe_cancelled','cancelled','active') as $subscription_status){foreach(array('renew','early-renew') as $type){foreach(array('processing','pending','on-hold') as $order_status){
 reset_order_barrier_fixture($subscription_status,$order_status,$type);$before=$GLOBALS['ob_meta'];$error=null;
 try{$service->order_status_changed(501);$service->payment_complete(501);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
 $results['callbacks'][]=array('initial_status'=>$subscription_status,'order_status'=>$order_status,'type'=>$type,'status'=>$GLOBALS['ob_status'],'before'=>$before,'after'=>$GLOBALS['ob_meta'],'effects'=>$GLOBALS['ob_effects'],'error'=>$error);
}}}
foreach(array('renew','early-renew') as $type){reset_order_barrier_fixture('pe_cancelled','pending',$type);$results['needs_payment'][$type]=$service->block_quarantined_renewal_payment(true,$GLOBALS['ob_order']);}
reset_order_barrier_fixture('expired','pending','renew');$GLOBALS['ob_meta']['_subscrpt_next_date']=time()-86400;$error=null;
try{$result=SpringDevs\Subscription\Illuminate\Helper::process_order_renewal(99,501,601);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();$result=null;}
$results['manual']=array('result'=>$result,'effects'=>$GLOBALS['ob_effects'],'error'=>$error);
echo json_encode($results);
